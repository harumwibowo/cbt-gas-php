<?php
/**
 * Alur pengerjaan ujian siswa: mulai, autosave, pelanggaran, submit, blokir.
 */
declare(strict_types=1);

/** Batas toleransi setelah deadline sebelum jawaban dianggap terlambat. */
const EXAM_LATE_GRACE_SECONDS = 60;

/**
 * Rentang waktu ujian dari baris exams (timestamp atau null bila kosong).
 *
 * @return array{start:?int,end:?int}
 */
function exam_time_bounds(array $examRow): array
{
    $start = tostr($examRow['waktu_mulai'] ?? '');
    $end = tostr($examRow['waktu_selesai'] ?? '');
    $startTs = $start !== '' ? strtotime($start) : false;
    $endTs = $end !== '' ? strtotime($end) : false;
    return [
        'start' => $startTs === false ? null : $startTs,
        'end'   => $endTs === false ? null : $endTs,
    ];
}

/**
 * Hitung deadline pengerjaan: mulai + durasi, dibatasi waktu selesai ujian.
 */
function exam_compute_deadline(array $examRow, ?int $startTs = null): ?string
{
    $startTs = $startTs ?? time();
    // Durasi kini GLOBAL (Pengaturan › Pelaksanaan Ujian), bukan per-ujian.
    $duration = get_exam_duration();
    $deadline = $startTs + ($duration * 60);
    $bounds = exam_time_bounds($examRow);
    if ($bounds['end'] !== null && $bounds['end'] < $deadline) {
        $deadline = $bounds['end'];
    }
    return date('Y-m-d H:i:s', $deadline);
}

/** Deadline efektif untuk satu baris hasil ujian. */
function exam_result_deadline(array $examRow, array $resultRow): ?string
{
    $stored = tostr($resultRow['deadline_at'] ?? '');
    if ($stored !== '' && !str_starts_with($stored, '0000')) {
        return $stored;
    }
    $startTs = tostr($resultRow['waktu_mulai'] ?? '') !== ''
        ? strtotime(tostr($resultRow['waktu_mulai']))
        : false;
    return exam_compute_deadline($examRow, $startTs === false ? time() : $startTs);
}

/** True bila waktu sekarang melewati deadline (+ toleransi). */
function exam_deadline_passed(?string $deadline, int $grace = EXAM_LATE_GRACE_SECONDS): bool
{
    if ($deadline === null || trim($deadline) === '') {
        return false;
    }
    $ts = strtotime($deadline);
    return $ts !== false && time() > ($ts + $grace);
}

/** Ambil jawaban tersimpan dari detail_jawaban (array id_soal => huruf). */
function exam_stored_answers(array $resultRow): array
{
    $raw = tostr($resultRow['detail_jawaban'] ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Mulai / lanjutkan ujian siswa (padanan startStudentExam()).
 * Kunci jawaban tidak pernah dikirim ke klien.
 */
function startStudentExam($username, $token, $id_ujian, $inputToken = ''): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $idUjian = trim(tostr($id_ujian));
        $userName = tostr($username);

        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }

        $exam = [
            'id_ujian'             => tostr($examRow['id_ujian']),
            'nama_ujian'           => sanitize_plain_text($examRow['nama_ujian']),
            'durasi_menit'         => get_exam_duration(),
            'min_pengerjaan_menit' => get_exam_min_work(),
            'waktu_mulai'          => format_datetime_iso($examRow['waktu_mulai']),
            'waktu_selesai'        => format_datetime_iso($examRow['waktu_selesai']),
            'acak_soal'            => tostr($examRow['acak_soal']) !== '' ? tostr($examRow['acak_soal']) : 'Ya',
            'acak_opsi'            => tostr($examRow['acak_opsi']) !== '' ? tostr($examRow['acak_opsi']) : 'Ya',
            // Token TIDAK dikirim ke klien. Token hanya diverifikasi di server.
            // Klien hanya perlu tahu apakah ujian memakai token (has_token).
            'has_token'            => strtoupper(tostr($examRow['token_ujian'])) !== '',
            'kelas'                => tostr($examRow['kelas']),
            'sesi'                 => tostr($examRow['sesi']),
            'tipe_peserta'         => tostr($examRow['tipe_peserta']) !== '' ? strtolower(tostr($examRow['tipe_peserta'])) : 'general',
            'peserta_khusus'       => tostr($examRow['peserta_khusus']),
            'jumlah_soal_tampil'   => (int) $examRow['jumlah_soal_tampil'],
            'jumlah_percobaan'     => get_exam_attempt_limit(),
            'max_violations'       => violation_policy()['max'],
            'tindakan_pelanggaran' => violation_policy()['action'],
        ];

        $profile = student_profile($userName);
        $studentClass = $profile['kelas'];
        $studentSession = $profile['sesi'];
        $userLower = strtolower(trim($userName));

        // Jendela waktu ujian ditegakkan di server, bukan hanya di browser.
        $bounds = exam_time_bounds($examRow);
        $nowTs = time();
        if ($bounds['start'] !== null && $nowTs < $bounds['start']) {
            return err('Ujian belum dimulai. Jadwal mulai: ' . dt_display($examRow['waktu_mulai'] ?? ''));
        }
        if ($bounds['end'] !== null && $nowTs > $bounds['end']) {
            return err('Waktu ujian telah berakhir. Anda tidak dapat memulai atau melanjutkan ujian ini.');
        }

        // Validasi target peserta
        if ($exam['tipe_peserta'] === 'khusus') {
            $allowedStudents = array_map('strtolower', split_list($exam['peserta_khusus']));
            if (!in_array($userLower, $allowedStudents, true)) {
                return err('Ujian ini ditujukan khusus untuk siswa tertentu dan akun Anda tidak terdaftar sebagai peserta khusus.');
            }
        } else {
            $kelasLower = strtolower($exam['kelas']);
            if ($exam['kelas'] !== '' && !in_array($kelasLower, ['semua', 'all', 'semua kelas', '-'], true)) {
                $allowed = array_map('strtolower', split_list($exam['kelas']));
                $sClassLower = strtolower($studentClass);
                $isAllowed = false;
                foreach ($allowed as $a) {
                    if ($a === '') {
                        continue;
                    }
                    if ($a === $sClassLower || in_array($a, ['semua', 'all', 'semua kelas'], true)) {
                        $isAllowed = true;
                        break;
                    }
                }
                if (!$isAllowed) {
                    return err('Ujian ini tidak dibuka untuk kelas Anda (' . ($studentClass !== '' ? $studentClass : '-') . '). Target kelas: ' . $exam['kelas']);
                }
            }

            $sesiLower = strtolower($exam['sesi']);
            if ($exam['sesi'] !== '' && !in_array($sesiLower, ['semua', 'all', 'semua sesi', '-'], true)) {
                $allowedSesi = array_map('strtolower', split_list($exam['sesi']));
                $sSesiLower = strtolower($studentSession);
                $isSesiAllowed = false;
                foreach ($allowedSesi as $as) {
                    if ($as === '') {
                        continue;
                    }
                    if ($as === $sSesiLower || in_array($as, ['semua', 'all', 'semua sesi'], true)) {
                        $isSesiAllowed = true;
                        break;
                    }
                }
                if (!$isSesiAllowed) {
                    return err('Ujian ini tidak dibuka untuk sesi Anda (' . ($studentSession !== '' ? $studentSession : '-') . '). Target sesi: ' . $exam['sesi']);
                }
            }
        }

        // Riwayat pengerjaan (multi percobaan)
        $maxAttempts = normalize_exam_attempts($exam['jumlah_percobaan']);
        $existingRecord = find_result_record($idUjian, $userName);
        $draftData = null;
        $isResume = false;
        $attemptNumber = 1;

        if ($existingRecord !== null) {
            $attemptsUsed = $existingRecord['total_percobaan'];
            $bestScore = (float) $existingRecord['nilai_tertinggi'];
            $attemptNumber = (int) $existingRecord['percobaan_ke'];
            $status = strtoupper(trim(tostr($existingRecord['row']['status'])));

            if ($status === 'TERBLOKIR') {
                return err('Akses Anda ke ujian ini terblokir karena batas pelanggaran keamanan tercapai. Silakan hubungi guru/pengawas untuk membuka blokir.');
            }

            if ($status === 'DRAFT') {
                $draftDeadline = exam_result_deadline($examRow, $existingRecord['row']);
                if (exam_deadline_passed($draftDeadline)) {
                    // Jangan biarkan draft kedaluwarsa terus dibuka. Kumpulkan
                    // jawaban terakhir yang tersimpan di server.
                    $auto = submitExam($username, $token, [
                        'id_ujian'     => $idUjian,
                        'answers'      => exam_stored_answers($existingRecord['row']),
                        'violations'   => (int) ($existingRecord['row']['jumlah_pelanggaran'] ?? 0),
                        'isAutoSubmit' => true,
                        'reason'       => 'Batas waktu pengerjaan tercapai',
                        'percobaan_ke' => $attemptNumber,
                    ]);
                    $scoreText = ($auto['success'] ?? false) ? ' Nilai Anda: ' . ($auto['score'] ?? 0) . '.' : '';
                    return err('Waktu pengerjaan ujian telah berakhir. Jawaban terakhir Anda telah dikumpulkan otomatis.' . $scoreText);
                }
            }

            if ($status === 'SUBMITTED') {
                if ($attemptsUsed >= $maxAttempts) {
                    $limitMessage = $maxAttempts > 1
                        ? 'Anda sudah menggunakan seluruh ' . $maxAttempts . ' kesempatan mengerjakan ujian ini. Nilai tertinggi Anda: ' . $bestScore . '.'
                        : 'Anda sudah mengumpulkan ujian ini.';
                    return err($limitMessage);
                }
                $attemptNumber = $attemptsUsed + 1;
                $isResume = false;
            } else {
                $isResume = true;
                $row = $existingRecord['row'];
                $draftData = [
                    'id_hasil'           => tostr($row['id_hasil']),
                    'waktu_mulai'        => format_datetime_iso($row['waktu_mulai']),
                    'jumlah_pelanggaran' => (int) $row['jumlah_pelanggaran'],
                    'detail_jawaban'     => tostr($row['detail_jawaban']) !== '' ? tostr($row['detail_jawaban']) : '{}',
                    'percobaan_ke'       => $attemptNumber,
                    'is_resume'          => true,
                ];
            }
        }

        // Verifikasi token ujian (tidak diminta ulang saat melanjutkan).
        // Mode token_per_siswa = Ya memakai token sekali pakai per peserta,
        // berlaku sampai expires_at dan hanya bisa dipakai sekali (3.4).
        $tokenPerSiswa = strtolower(trim(tostr(settings_map()['token_per_siswa'] ?? 'Tidak'))) === 'ya';
        if ($tokenPerSiswa && !$isResume) {
            $entered = strtoupper(trim(tostr($inputToken)));
            $hash = hash('sha256', $entered);
            $row = db_one(
                'SELECT token_hash, expires_at, used_at FROM exam_tokens
                 WHERE id_ujian = ? AND username_siswa = ? LIMIT 1',
                [$idUjian, strtolower(trim($userName))]
            );
            $expired = !$row || strtotime(tostr($row['expires_at'])) < time();
            if (!$row || $entered === '' || !hash_equals(tostr($row['token_hash']), $hash)
                || $expired || tostr($row['used_at']) !== '') {
                return err('Token pribadi tidak valid, kedaluwarsa, atau sudah digunakan. Hubungi guru pengawas.');
            }
            db_exec(
                'UPDATE exam_tokens SET used_at = ? WHERE id_ujian = ? AND username_siswa = ?',
                [now_dt(), $idUjian, strtolower(trim($userName))]
            );
        } else {
            $examToken = strtoupper(trim(tostr($examRow['token_ujian'] ?? '')));
            if ($examToken !== '' && !$isResume) {
                $enteredToken = strtoupper(trim(tostr($inputToken)));
                if ($enteredToken === '' || !hash_equals($examToken, $enteredToken)) {
                    return err('Token ujian salah. Silakan minta token yang valid kepada guru pengawas.');
                }
            }
        }

        if ($draftData === null) {
            $nowStr = now_dt();
            $newResultId = new_id('RES');
            $newDeadline = exam_compute_deadline($examRow, $nowTs);
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke, deadline_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$newResultId, $idUjian, $userName, $nowStr, null, 0, 0, 'DRAFT', '{}', '', $attemptNumber, $newDeadline]
            );
            $draftData = [
                'id_hasil'           => $newResultId,
                'waktu_mulai'        => $nowStr,
                'jumlah_pelanggaran' => 0,
                'detail_jawaban'     => '{}',
                'percobaan_ke'       => $attemptNumber,
                'is_resume'          => false,
                'deadline_at'        => $newDeadline,
            ];
        } else {
            // Draft lama tanpa deadline (data sebelum migrasi) dihitung dan disimpan.
            $resumeDeadline = exam_result_deadline($examRow, $existingRecord['row']);
            if (tostr($existingRecord['row']['deadline_at'] ?? '') === '' && $resumeDeadline !== null) {
                db_exec('UPDATE results SET deadline_at = ? WHERE id_hasil = ?', [$resumeDeadline, tostr($existingRecord['row']['id_hasil'])]);
            }
            $draftData['deadline_at'] = $resumeDeadline;
        }

        // Ambil soal tanpa kunci jawaban
        $textMap = exam_text_map($idUjian);
        $qRows = db_all('SELECT * FROM questions WHERE id_ujian = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);

        $questions = [];
        foreach ($qRows as $qRow) {
            $qIdTeks = trim(tostr($qRow['id_teks']));
            $qTeksObj = $textMap[$qIdTeks] ?? null;
            $qObj = [
                'id_soal'      => tostr($qRow['id_soal']),
                'teks_soal'    => sanitize_html_fragment($qRow['teks_soal']),
                'url_gambar'   => safe_media_url($qRow['url_gambar']),
                'bobot'        => (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1,
                'id_teks'      => $qIdTeks,
                'nama_teks'    => $qTeksObj ? sanitize_plain_text($qTeksObj['nama_teks']) : '',
                'teks_konteks' => $qTeksObj ? sanitize_html_fragment($qTeksObj['isi_teks']) : '',
            ];
            foreach (option_letters() as $l) {
                $qObj['opsi_' . $l] = sanitize_html_fragment($qRow['opsi_' . $l] ?? '');
            }
            $questions[] = $qObj;
        }

        if (count($questions) === 0) {
            return err('Ujian belum memiliki soal pilihan ganda.');
        }

        // Paket soal acak sesuai "jumlah soal tampil"
        $jumlahSoalTampil = (int) $exam['jumlah_soal_tampil'];
        $resultRecord = find_result_record($idUjian, $userName, $attemptNumber);
        $storedRaw = '';
        $resultIdActive = '';
        if ($resultRecord !== null) {
            $resultIdActive = tostr($resultRecord['row']['id_hasil']);
            $storedRaw = tostr($resultRecord['row']['soal_terpilih']);
        }

        $bankIds = [];
        $bankSet = [];
        foreach ($questions as $qb) {
            $qid = $qb['id_soal'];
            if ($qid !== '' && !isset($bankSet[$qid])) {
                $bankSet[$qid] = true;
                $bankIds[] = $qid;
            }
        }

        $selectedIds = [];
        if ($storedRaw !== '') {
            foreach (explode(',', $storedRaw) as $qidRaw) {
                $qid = trim($qidRaw);
                if ($qid !== '' && isset($bankSet[$qid]) && !in_array($qid, $selectedIds, true)) {
                    $selectedIds[] = $qid;
                }
            }
        }

        if (count($selectedIds) > 0) {
            $desiredCount = min($jumlahSoalTampil > 0 ? $jumlahSoalTampil : count($selectedIds), count($bankIds));
            if (count($selectedIds) < $desiredCount) {
                $chosenSet = array_fill_keys($selectedIds, true);
                $extraIds = array_values(array_filter($bankIds, static fn ($qidExtra) => !isset($chosenSet[$qidExtra])));
                for ($ei = count($extraIds) - 1; $ei > 0; $ei--) {
                    $ej = (int) floor(mt_rand() / mt_getrandmax() * ($ei + 1));
                    $tmp = $extraIds[$ei];
                    $extraIds[$ei] = $extraIds[$ej];
                    $extraIds[$ej] = $tmp;
                }
                $selectedIds = array_merge($selectedIds, array_slice($extraIds, 0, $desiredCount - count($selectedIds)));
            }
        } elseif ($jumlahSoalTampil > 0 && $jumlahSoalTampil < count($questions)) {
            $poolIds = $bankIds;
            for ($pi = count($poolIds) - 1; $pi > 0; $pi--) {
                $pj = (int) floor(mt_rand() / mt_getrandmax() * ($pi + 1));
                $tmp = $poolIds[$pi];
                $poolIds[$pi] = $poolIds[$pj];
                $poolIds[$pj] = $tmp;
            }
            $selectedIds = array_slice($poolIds, 0, $jumlahSoalTampil);
        }

        if (count($selectedIds) > 0) {
            if ($resultIdActive !== '') {
                db_exec('UPDATE results SET soal_terpilih = ? WHERE id_hasil = ?', [implode(',', $selectedIds), $resultIdActive]);
            }
            $chosenFinal = array_fill_keys($selectedIds, true);
            $questions = array_values(array_filter($questions, static fn ($qf) => isset($chosenFinal[$qf['id_soal']])));
        }

        // Pengacakan opsi & soal deterministik per siswa
        $isAcakOpsi = $exam['acak_opsi'] === 'Ya';
        $isAcakSoal = $exam['acak_soal'] === 'Ya';
        $alphabet = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

        foreach ($questions as &$qItem) {
            $rawOpts = [];
            foreach ($alphabet as $letter) {
                $key = 'opsi_' . strtolower($letter);
                $optVal = $qItem[$key] ?? '';
                if ($optVal !== '' && trim($optVal) !== '') {
                    $rawOpts[] = ['origKey' => $letter, 'text' => $optVal];
                }
            }
            if ($isAcakOpsi) {
                $rawOpts = shuffle_with(make_prng($userLower . '_' . $qItem['id_soal']), $rawOpts);
            }
            $shuffled = [];
            foreach ($rawOpts as $idx => $o) {
                $shuffled[] = [
                    'displayLabel' => $alphabet[$idx] ?? ('Opsi ' . ($idx + 1)),
                    'origKey'      => $o['origKey'],
                    'text'         => $o['text'],
                ];
            }
            $qItem['shuffled_options'] = $shuffled;
        }
        unset($qItem);

        if ($isAcakSoal) {
            $questions = shuffle_with(make_prng($userLower . '_' . $idUjian), $questions);
        }

        log_activity(
            $userName,
            'Start Exam',
            'Memulai ujian: ' . $exam['nama_ujian'] . ' (' . count($questions) . ' soal)'
            . ' | Percobaan ke-' . $attemptNumber . '/' . $maxAttempts
        );

        // Sisa batas minimal pengerjaan dihitung SERVER (detik), bukan di
        // browser: string waktu_mulai tanpa zona waktu akan ditafsirkan
        // memakai zona waktu perangkat siswa, sehingga tombol kirim bisa
        // terbuka lebih awal daripada yang diizinkan server saat submit.
        // Penting: pakai nilai DATETIME asli dari database (berisi detik),
        // bukan draft.waktu_mulai yang sudah dipotong ke menit oleh
        // format_datetime_iso() - pemotongan itu membuat sisa waktu terlalu
        // kecil sampai 59 detik dan tombol terbuka sebelum waktunya.
        $workStartRaw = ($isResume && $existingRecord !== null)
            ? tostr($existingRecord['row']['waktu_mulai'] ?? '')
            : tostr($draftData['waktu_mulai'] ?? '');
        $minWorkMinutes = get_exam_min_work();
        $minWorkRemaining = 0;
        if ($minWorkMinutes > 0) {
            $workStartTs = $workStartRaw !== '' ? strtotime($workStartRaw) : false;
            $workElapsed = $workStartTs === false ? 0 : max(0, $nowTs - $workStartTs);
            $minWorkRemaining = max(0, ($minWorkMinutes * 60) - $workElapsed);
        }
        $deadlineEpoch = strtotime(tostr($draftData['deadline_at'] ?? ''));

        return [
            'success'   => true,
            'exam'      => $exam,
            'questions'   => $questions,
            'draft'       => $draftData,
            'server_time' => now_dt(),
            'server_epoch'=> time(),
            'min_pengerjaan_sisa' => $minWorkRemaining,
            'deadline_epoch'      => $deadlineEpoch === false ? 0 : $deadlineEpoch,
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat lembar ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Status waktu otoritatif untuk satu sesi ujian siswa.
 *
 * Dipakai browser untuk memastikan tombol kirim hanya terbuka setelah server
 * setuju (bukan berdasarkan jam/zona waktu perangkat) dan untuk memulihkan
 * hitung mundur bila state klien sudah kedaluwarsa.
 */
function getExamTiming($username, $token, $id_ujian, $percobaan_ke = null): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $idUjian = trim(tostr($id_ujian));
    if ($idUjian === '') {
        return err('ID ujian tidak valid.');
    }

    try {
        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }

        $record = find_result_record($idUjian, tostr($username), $percobaan_ke);
        if ($record === null) {
            return err('Sesi ujian tidak ditemukan. Mulai ujian terlebih dahulu.');
        }

        $row = $record['row'];
        $status = strtoupper(trim(tostr($row['status'])));
        $nowTs = time();
        $minMinutes = get_exam_min_work();
        $minRemaining = 0;
        if ($minMinutes > 0 && $status === 'DRAFT') {
            $startRaw = tostr($row['waktu_mulai'] ?? '');
            $startTs = $startRaw !== '' ? strtotime($startRaw) : false;
            $elapsed = $startTs === false ? 0 : max(0, $nowTs - $startTs);
            $minRemaining = max(0, ($minMinutes * 60) - $elapsed);
        }

        $deadline = exam_result_deadline($examRow, $row);
        $deadlineTs = $deadline !== null ? strtotime($deadline) : false;

        return [
            'success'              => true,
            'server_time'          => now_dt(),
            'server_epoch'         => $nowTs,
            'status'               => $status,
            'min_pengerjaan_menit' => $minMinutes,
            'min_pengerjaan_sisa'  => $minRemaining,
            'deadline_epoch'       => $deadlineTs === false ? 0 : $deadlineTs,
        ];
    } catch (Throwable $e) {
        error_log('[CBT] getExamTiming: ' . $e->getMessage());
        return err('Gagal membaca status waktu ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Autosave jawaban draft ke database (padanan autosaveExam()).
 */
/**
 * Catat perubahan jawaban ke tabel audit append-only (Bagian 3.4).
 * Hanya jawaban yang benar-benar berubah yang ditulis (maks. 200 butir/save).
 */
function answer_audit_changes(string $idHasil, string $oldJson, array $newAnswers): void
{
    $old = json_decode($oldJson, true);
    if (!is_array($old)) {
        $old = [];
    }
    $written = 0;
    foreach ($newAnswers as $questionId => $value) {
        $qid = trim(tostr($questionId));
        if ($qid === '' || $written >= 200) {
            break;
        }
        $before = tostr($old[$qid] ?? '');
        $after = tostr($value);
        if ($before === $after) {
            continue;
        }
        try {
            db_exec(
                'INSERT INTO answer_audit (id_hasil, id_soal, jawaban, changed_at, ip) VALUES (?,?,?,?,?)',
                [$idHasil, substr($qid, 0, 40), substr($after, 0, 500), now_dt(), auth_client_ip()]
            );
            $written++;
        } catch (Throwable $e) {
            error_log('answer_audit_changes: ' . $e->getMessage());
        }
    }
}

function autosaveExam($username, $token, $payload): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }

    $payload = is_array($payload) ? $payload : [];
    $idUjian = trim(tostr($payload['id_ujian'] ?? ''));
    if ($idUjian === '') {
        return err('ID ujian tidak valid.');
    }

    $rawAnswers = $payload['answers'] ?? [];
    if (is_string($rawAnswers)) {
        $decodedAnswers = json_decode($rawAnswers, true);
        if (!is_array($decodedAnswers)) {
            return err('Format jawaban tidak valid.');
        }
        $answersJson = $rawAnswers;
    } else {
        $answersJson = json_encode($rawAnswers, JSON_UNESCAPED_UNICODE);
    }
    if (strlen($answersJson) > 1048576) {
        return err('Data jawaban terlalu besar.');
    }
    $violations = max(0, (int) ($payload['violations'] ?? 0));

    try {
        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);
        if ($existingRecord === null) {
            return err('Sesi ujian tidak ditemukan. Mulai ujian terlebih dahulu.');
        }

        $status = strtoupper(trim(tostr($existingRecord['row']['status'])));
        if ($status === 'SUBMITTED') {
            return err('Ujian sudah disubmit sebelumnya.');
        }
        if ($status === 'TERBLOKIR') {
            return err('Ujian Anda sedang diblokir. Hubungi guru/pengawas.');
        }

        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }
        $deadline = exam_result_deadline($examRow, $existingRecord['row']);
        if (exam_deadline_passed($deadline)) {
            // Simpan bukan jawaban baru, tetapi kumpulkan jawaban terakhir yang
            // sudah tersimpan di server. Jawaban setelah deadline tidak diterima.
            $auto = submitExam($username, $token, [
                'id_ujian'     => $idUjian,
                'answers'      => exam_stored_answers($existingRecord['row']),
                'violations'   => $violations,
                'isAutoSubmit' => true,
                'reason'       => 'Batas waktu pengerjaan tercapai (autosave)',
                'percobaan_ke' => (int) $existingRecord['percobaan_ke'],
            ]);
            return err(
                'Waktu pengerjaan telah berakhir. Jawaban terakhir Anda dikumpulkan otomatis.'
                . (($auto['success'] ?? false) ? ' Nilai: ' . ($auto['score'] ?? 0) . '.' : ''),
                ['expired' => true, 'auto_submitted' => (bool) ($auto['success'] ?? false), 'score' => $auto['score'] ?? null]
            );
        }

        // Batasi frekuensi tulis ke DB: maksimal satu autosave per 2 detik per
        // (siswa, ujian). Memakai APCu bila tersedia; tanpa APCu tidak ada
        // pembatasan agar autosave tetap aman (tidak ada jawaban yang hilang).
        if (cbt_cache_apcu_ready() && function_exists('apcu_add')) {
            $rateKey = 'cbt.autosave.' . md5(strtolower(tostr($username)) . '|' . $idUjian);
            if (!apcu_add($rateKey, 1, 2)) {
                return ['success' => true, 'skipped' => true, 'timestamp' => date('H:i:s'), 'deadline_at' => $deadline];
            }
        }

        db_transaction(static function () use ($existingRecord, $answersJson, $violations, $deadline): void {
            $answersArr = json_decode($answersJson, true);
            answer_audit_changes(
                tostr($existingRecord['row']['id_hasil']),
                tostr($existingRecord['row']['detail_jawaban'] ?? ''),
                is_array($answersArr) ? $answersArr : []
            );
            db_exec(
                'UPDATE results SET jumlah_pelanggaran = ?, detail_jawaban = ?, deadline_at = COALESCE(deadline_at, ?) WHERE id_hasil = ?',
                [$violations, $answersJson, $deadline, tostr($existingRecord['row']['id_hasil'])]
            );
        });

        return ['success' => true, 'timestamp' => date('H:i:s'), 'deadline_at' => $deadline];
    } catch (Throwable $e) {
        return err('Gagal menyimpan jawaban: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Catat pelanggaran anti-cheat ke logs & results (padanan recordViolation()).
 */
function recordViolation($username, $token, $payload): array
{
    if (!validate_session($username, $token)) {
        return ['success' => false, 'message' => 'Sesi kedaluwarsa.'];
    }

    $payload = is_array($payload) ? $payload : [];
    $idUjian = trim(tostr($payload['id_ujian'] ?? ''));
    $jenis = limit_text(sanitize_plain_text($payload['jenis'] ?? ''), 80);
    if ($jenis === '') {
        $jenis = 'Tab Switch';
    }
    $keterangan = limit_text(sanitize_plain_text($payload['keterangan'] ?? ''), 500);
    if ($keterangan === '') {
        $keterangan = 'Terdeteksi meninggalkan layar ujian.';
    }
    if ($idUjian === '') {
        return ['success' => false, 'message' => 'ID ujian tidak valid.'];
    }

    try {
        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);
        if ($existingRecord === null) {
            return ['success' => false, 'message' => 'Sesi ujian tidak ditemukan.'];
        }

        $row = $existingRecord['row'];
        $status = strtoupper(trim(tostr($row['status'])));
        if ($status !== 'DRAFT') {
            return ['success' => false, 'message' => 'Ujian tidak dalam status pengerjaan.'];
        }

        // Hitung di server: setiap panggilan = satu pelanggaran baru. Angka dari
        // klien tidak dipercaya agar siswa tidak dapat mengirim 0/angka palsu.
        $serverCount = max(0, (int) $row['jumlah_pelanggaran']) + 1;
        db_exec(
            'UPDATE results SET jumlah_pelanggaran = ? WHERE id_hasil = ?',
            [$serverCount, tostr($row['id_hasil'])]
        );

        log_violation_activity(
            tostr($username),
            $jenis,
            'Pelanggaran ke-' . $serverCount . ' | ' . $keterangan,
            $idUjian
        );

        $policy = violation_policy();
        $action = 'none';
        $auto = null;
        if ($serverCount >= $policy['max']) {
            if ($policy['action'] === 'auto_blokir') {
                db_exec(
                    'UPDATE results SET status = ?, jumlah_pelanggaran = ? WHERE id_hasil = ?',
                    ['TERBLOKIR', $serverCount, tostr($row['id_hasil'])]
                );
                $action = 'blocked';
                log_activity(tostr($username), 'Auto Blokir Pelanggaran', 'Batas ' . $policy['max'] . ' pelanggaran tercapai pada ujian ' . $idUjian . '.');
            } elseif ($policy['action'] === 'auto_submit') {
                $auto = submitExam($username, $token, [
                    'id_ujian'     => $idUjian,
                    'answers'      => exam_stored_answers($row),
                    'violations'   => $serverCount,
                    'isAutoSubmit' => true,
                    'reason'       => 'Batas maksimal pelanggaran tercapai',
                    'percobaan_ke' => (int) $existingRecord['percobaan_ke'],
                ]);
                $action = ($auto['success'] ?? false) ? 'submitted' : 'none';
            } else {
                $action = 'recorded';
            }
        }

        return [
            'success'        => true,
            'violations'     => $serverCount,
            'max_violations' => $policy['max'],
            'action'         => $action,
            'score'          => $auto['score'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log('recordViolation: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mencatat pelanggaran.'];
    }
}

/**
 * Submit ujian & hitung nilai di server (padanan submitExam()).
 */
function submitExam($username, $token, $payload): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Gagal mengirim jawaban.');
    }

    $payload = is_array($payload) ? $payload : [];
    $idUjian = trim(tostr($payload['id_ujian'] ?? ''));
    $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
    $violations = max(0, (int) ($payload['violations'] ?? 0));
    $isAutoSubmit = (bool) ($payload['isAutoSubmit'] ?? false);
    $reason = tostr($payload['reason'] ?? '') !== '' ? tostr($payload['reason']) : ($isAutoSubmit ? 'Auto Submit Sistem' : 'Selesai Manual');

    if ($idUjian === '') {
        return err('ID ujian tidak valid.');
    }

    try {
        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }

        // Idempotency key (Bagian 3.4): retry submit dengan kunci yang sama
        // mengembalikan respons lama, bukan error "sudah disubmit".
        $idempotencyKey = substr(trim(tostr($payload['idempotency_key'] ?? '')), 0, 64);
        if ($idempotencyKey !== '') {
            $prior = db_one(
                'SELECT id_hasil, nilai_akhir, status FROM results WHERE idempotency_key = ? LIMIT 1',
                [$idempotencyKey]
            );
            if ($prior && strtoupper(tostr($prior['status'])) === 'SUBMITTED') {
                return [
                    'success'            => true,
                    'score'              => (float) $prior['nilai_akhir'],
                    'idempotent_replay'  => true,
                    'message'            => 'Submit ini sudah diproses sebelumnya (idempotent replay).',
                ];
            }
        }

        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);
        if ($existingRecord === null) {
            return err('Sesi ujian tidak ditemukan. Mulai ujian terlebih dahulu.');
        }

        $status = strtoupper(trim(tostr($existingRecord['row']['status'])));
        if ($status === 'SUBMITTED') {
            return err('Ujian ini sudah Anda kumpulkan sebelumnya.');
        }
        if ($status === 'TERBLOKIR') {
            return err('Ujian Anda sedang diblokir. Hubungi guru/pengawas.');
        }

        $deadline = exam_result_deadline($examRow, $existingRecord['row']);
        if (exam_deadline_passed($deadline)) {
            // Jawaban yang dikirim setelah deadline tidak dipercaya. Nilai
            // dihitung dari jawaban terakhir yang sudah tersimpan di server.
            $answers = exam_stored_answers($existingRecord['row']);
            $isAutoSubmit = true;
            $reason = 'Melewati batas waktu pengerjaan';
        } else {
            // Batas minimal pengerjaan: ditolak di server, bukan hanya di
            // browser. Submit paksa oleh sistem (pelanggaran) tetap diizinkan.
            $minPengerjaanMenit = get_exam_min_work();
            $forcedByViolation = max(0, (int) $existingRecord['row']['jumlah_pelanggaran']) >= violation_policy()['max'];
            if ($minPengerjaanMenit > 0 && !$forcedByViolation) {
                $startedRaw = tostr($existingRecord['row']['waktu_mulai'] ?? '');
                $startedTs = $startedRaw !== '' ? strtotime($startedRaw) : false;
                $elapsedSeconds = $startedTs === false ? 0 : max(0, time() - $startedTs);
                $minSeconds = $minPengerjaanMenit * 60;
                if ($elapsedSeconds < $minSeconds) {
                    $sisaMenit = (int) ceil(($minSeconds - $elapsedSeconds) / 60);
                    // Sertakan acuan otoritatif supaya klien dapat menyinkronkan
                    // ulang hitung mundur (mis. halaman lama / jam perangkat meleset).
                    return err(
                        'Minimal pengerjaan ' . $minPengerjaanMenit . ' menit belum terpenuhi. '
                        . 'Tombol kirim terbuka dalam ' . $sisaMenit . ' menit lagi. Jawaban Anda tetap tersimpan otomatis.',
                        [
                            'server_epoch'         => time(),
                            'min_pengerjaan_menit' => $minPengerjaanMenit,
                            'min_pengerjaan_sisa'  => max(0, $minSeconds - $elapsedSeconds),
                            'deadline_epoch'       => strtotime(tostr($deadline ?? '')) ?: 0,
                        ]
                    );
                }
            }
        }

        $submitAttemptNumber = (int) $existingRecord['percobaan_ke'];

        // Paket soal acak milik siswa
        $selectedSoalSet = null;
        if ($existingRecord !== null) {
            $subSelRaw = tostr($existingRecord['row']['soal_terpilih']);
            if ($subSelRaw !== '') {
                $tmp = [];
                foreach (explode(',', $subSelRaw) as $sidRaw) {
                    $sid = trim($sidRaw);
                    if ($sid !== '') {
                        $tmp[$sid] = true;
                    }
                }
                if (count($tmp) > 0) {
                    $selectedSoalSet = $tmp;
                }
            }
        }

        // Penilaian memakai kunci jawaban terbaru
        if (strlen((string) json_encode($answers, JSON_UNESCAPED_UNICODE)) > 1048576) {
            return err('Data jawaban terlalu besar.');
        }
        $keyMap = [];
        $totalBobot = 0;
        $skorDiperoleh = 0;
        $correctCount = 0;
        $totalQuestions = 0;
        $unansweredCount = 0;

        $qRows = db_all('SELECT id_soal, kunci_jawaban, bobot FROM questions WHERE id_ujian = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);
        foreach ($qRows as $qRow) {
            $qId = tostr($qRow['id_soal']);
            if ($selectedSoalSet !== null && !isset($selectedSoalSet[$qId])) {
                continue;
            }
            $totalQuestions++;
            $kunci = strtoupper(trim(tostr($qRow['kunci_jawaban'])));
            $bobot = (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1;
            $totalBobot += $bobot;

            $studentAns = strtoupper(trim(tostr($answers[$qId] ?? '')));
            if ($studentAns === '') {
                $unansweredCount++;
            } elseif ($studentAns === $kunci) {
                $skorDiperoleh += $bobot;
                $correctCount++;
            }
            $keyMap[$qId] = true;
        }

        if (!$isAutoSubmit && $unansweredCount > 0) {
            return err(
                'Jawaban tidak dapat dikirim karena masih ada ' . $unansweredCount
                . ' butir soal yang belum dijawab. Harap lengkapi seluruh soal sebelum mengumpulkan ujian.'
            );
        }

        $nilaiAkhir = $totalBobot > 0 ? round(($skorDiperoleh / $totalBobot) * 100, 1) : 0.0;
        $nowStr = now_dt();
        $answersJsonStr = json_encode($answers, JSON_UNESCAPED_UNICODE);

        if ($existingRecord !== null) {
            db_exec(
                'UPDATE results SET waktu_submit = ?, jumlah_pelanggaran = ?, nilai_akhir = ?, status = ?, detail_jawaban = ?,
                    deadline_at = COALESCE(deadline_at, ?), idempotency_key = COALESCE(idempotency_key, ?)
                 WHERE id_hasil = ?',
                [$nowStr, $violations, $nilaiAkhir, 'SUBMITTED', $answersJsonStr, $deadline,
                 $idempotencyKey !== '' ? $idempotencyKey : null, tostr($existingRecord['row']['id_hasil'])]
            );
        } else {
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke, deadline_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('RES'), $idUjian, tostr($username), $nowStr, $nowStr, $violations, $nilaiAkhir,
                    'SUBMITTED', $answersJsonStr, '', $submitAttemptNumber, $deadline,
                ]
            );
        }

        // Nilai tertinggi antar percobaan + sisa kesempatan
        $submitSummary = find_result_record($idUjian, tostr($username), $submitAttemptNumber);
        $nilaiTertinggi = $submitSummary !== null ? (float) $submitSummary['nilai_tertinggi'] : $nilaiAkhir;

        $attemptsLimit = get_exam_attempt_limit();
        $attemptsUsed = $submitSummary !== null ? $submitSummary['total_percobaan'] : $submitAttemptNumber;
        $sisaPercobaan = max(0, $attemptsLimit - $attemptsUsed);

        log_activity(
            tostr($username),
            $isAutoSubmit ? 'Auto Submit' : 'Submit Ujian',
            'Ujian: ' . $idUjian . ' | Percobaan: ' . $submitAttemptNumber . '/' . $attemptsLimit
            . ' | Nilai: ' . $nilaiAkhir . ' | Nilai Tertinggi: ' . $nilaiTertinggi
            . ' | Benar: ' . $correctCount . '/' . $totalQuestions . ' | Alasan: ' . $reason
        );

        return [
            'success'         => true,
            'score'           => $nilaiAkhir,
            'nilai_tertinggi' => $nilaiTertinggi,
            'percobaan_ke'    => $submitAttemptNumber,
            'jumlah_percobaan' => $attemptsLimit,
            'sisa_percobaan'  => $sisaPercobaan,
            'correctCount'    => $correctCount,
            'totalQuestions'  => $totalQuestions,
            'message'         => $isAutoSubmit
                ? 'Ujian telah di-submit otomatis karena ' . $reason
                : 'Ujian berhasil diselesaikan dan dikirim.',
        ];
    } catch (Throwable $e) {
        return err('Gagal memproses submit ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Blokir siswa dari ujian karena batas pelanggaran tercapai.
 */
function blockStudentExam($username, $token, $payload): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid. Silakan login kembali.');
    }

    $payload = is_array($payload) ? $payload : [];
    $idUjian = trim(tostr($payload['id_ujian'] ?? ''));
    if ($idUjian === '') {
        return err('ID ujian tidak valid.');
    }
    $violations = (int) ($payload['violations'] ?? 0) > 0 ? (int) $payload['violations'] : 1;
    $reason = tostr($payload['reason'] ?? '') !== '' ? tostr($payload['reason']) : 'Batas maksimal pelanggaran keamanan tercapai';
    $answersJson = is_string($payload['answers'] ?? null)
        ? (string) $payload['answers']
        : json_encode($payload['answers'] ?? [], JSON_UNESCAPED_UNICODE);

    log_activity(tostr($username), 'Auto Blokir Pelanggaran', 'Ujian: ' . $idUjian . ' | Pelanggaran ke-' . $violations . ' | ' . $reason);

    try {
        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);

        if ($existingRecord !== null) {
            if (strtoupper(trim(tostr($existingRecord['row']['status']))) === 'SUBMITTED') {
                return err('Ujian sudah disubmit sebelumnya.');
            }
            db_exec(
                'UPDATE results SET jumlah_pelanggaran = ?, status = ?, detail_jawaban = ?, blocked_at = COALESCE(blocked_at, ?) WHERE id_hasil = ?',
                [$violations, 'TERBLOKIR', $answersJson !== '' ? $answersJson : '{}', now_dt(), tostr($existingRecord['row']['id_hasil'])]
            );
        } else {
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke, blocked_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('RES'), $idUjian, tostr($username), now_dt(), null, $violations, 0, 'TERBLOKIR',
                    $answersJson !== '' ? $answersJson : '{}', '', normalize_exam_attempts($requestedAttempt), now_dt(),
                ]
            );
        }

        return ['success' => true, 'message' => 'Ujian berhasil diblokir.'];
    } catch (Throwable $e) {
        error_log('[CBT] blockStudentExam: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return err('Gagal memblokir ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Deadline hasil ujian setelah blokir dibuka.
 *
 * `waktu_mulai` TIDAK direset: batas minimal pengerjaan tetap dihitung dari
 * awal ujian, sehingga siswa yang sudah memenuhi minimal tidak perlu menunggu
 * lagi. Waktu selama diblokir dikembalikan dengan menggeser `deadline_at`
 * sebesar durasi blokir (dibatasi waktu selesai ujian).
 */
function exam_deadline_after_unblock(array $examRow, array $resultRow, ?int $nowTs = null): string
{
    $nowTs = $nowTs ?? time();

    $storedDeadline = tostr($resultRow['deadline_at'] ?? '');
    $deadlineTs = $storedDeadline !== '' ? strtotime($storedDeadline) : false;
    if ($deadlineTs === false) {
        // Data lama tanpa deadline: hitung dari waktu mulai + durasi.
        $startRaw = tostr($resultRow['waktu_mulai'] ?? '');
        $startTs = $startRaw !== '' ? strtotime($startRaw) : false;
        $deadlineTs = ($startTs === false ? $nowTs : $startTs) + (get_exam_duration() * 60);
    }

    $blockedRaw = tostr($resultRow['blocked_at'] ?? '');
    $blockedTs = $blockedRaw !== '' ? strtotime($blockedRaw) : false;
    $pausedSeconds = $blockedTs === false ? 0 : max(0, $nowTs - $blockedTs);

    $newDeadlineTs = $deadlineTs + $pausedSeconds;
    $bounds = exam_time_bounds($examRow);
    if ($bounds['end'] !== null && $newDeadlineTs > $bounds['end']) {
        $newDeadlineTs = $bounds['end'];
    }
    return date('Y-m-d H:i:s', $newDeadlineTs);
}

/**
 * Buka blokir ujian siswa (padanan unblockStudentExam()).
 * Status kembali DRAFT dan pelanggaran diset (batas global - 1).
 */
function unblockStudentExam($username, $token, $id_ujian, $username_siswa): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid. Silakan login kembali.');
    }

    $targetExamId = trim(tostr($id_ujian));
    $targetStudent = trim(tostr($username_siswa));
    if ($targetExamId === '' || $targetStudent === '') {
        return err('ID Ujian dan Username Siswa harus diisi.');
    }

    try {
        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }
        $bounds = exam_time_bounds($examRow);
        if ($bounds['end'] !== null && time() > $bounds['end']) {
            return err('Masa ujian sudah berakhir; blokir tidak dapat dibuka.');
        }

        $callerRole = current_role(tostr($username));
        if ($callerRole !== 'admin') {
            if ($callerRole === 'guru') {
                if (!can_manage_exam($examRow, tostr($username))) {
                    return err('Anda tidak memiliki hak akses untuk membuka blokir pada ujian ini.');
                }
            } else {
                return err('Akses ditolak.');
            }
        }

        // Batas pelanggaran GLOBAL (menu Pengaturan Admin).
        $maxViolations = get_max_violations();
        $resetViolations = max(0, $maxViolations - 1);

        $existingRecord = find_result_record($targetExamId, $targetStudent);
        if ($existingRecord === null) {
            return err('Data hasil ujian siswa tidak ditemukan.');
        }

        $newDeadline = exam_deadline_after_unblock($examRow, $existingRecord['row'], time());
        // waktu_mulai dibiarkan apa adanya: batas minimal pengerjaan tidak
        // ikut ter-reset saat blokir dibuka. blocked_at dikosongkan agar
        // blokir berikutnya mencatat waktu blokir yang baru.
        db_exec(
            'UPDATE results SET status = ?, jumlah_pelanggaran = ?, deadline_at = ?, blocked_at = NULL WHERE id_hasil = ?',
            ['DRAFT', $resetViolations, $newDeadline, tostr($existingRecord['row']['id_hasil'])]
        );

        log_activity(
            tostr($username),
            'Buka Blokir Siswa',
            'Buka blokir ujian ' . $targetExamId . ' untuk siswa ' . $targetStudent
            . ' | Pelanggaran diset ke ' . $resetViolations
            . ' | Deadline baru ' . dt_display($newDeadline) . ' (waktu mulai dipertahankan)'
        );

        return [
            'success' => true,
            'message' => 'Blokir ujian siswa ' . $targetStudent . ' berhasil dibuka. Siswa kini dapat melanjutkan ujian.',
        ];
    } catch (Throwable $e) {
        return err('Gagal membuka blokir: ' . client_error_message($e->getMessage()));
    }
}

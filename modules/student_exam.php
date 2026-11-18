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
    $duration = (int) ($examRow['durasi_menit'] ?? 0);
    $duration = $duration > 0 ? $duration : 60;
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

        $examRow = db_one('SELECT * FROM exams WHERE TRIM(id_ujian) = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }

        $exam = [
            'id_ujian'             => tostr($examRow['id_ujian']),
            'nama_ujian'           => sanitize_plain_text($examRow['nama_ujian']),
            'durasi_menit'         => (int) $examRow['durasi_menit'] > 0 ? (int) $examRow['durasi_menit'] : 60,
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
            'jumlah_percobaan'     => normalize_exam_attempts($examRow['jumlah_percobaan']),
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

        // Verifikasi token ujian (tidak diminta ulang saat melanjutkan)
        $examToken = strtoupper(trim(tostr($examRow['token_ujian'] ?? '')));
        if ($examToken !== '' && !$isResume) {
            $enteredToken = strtoupper(trim(tostr($inputToken)));
            if ($enteredToken === '' || !hash_equals($examToken, $enteredToken)) {
                return err('Token ujian salah. Silakan minta token yang valid kepada guru pengawas.');
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
        $qRows = db_all('SELECT * FROM questions WHERE TRIM(id_ujian) = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);

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

        return [
            'success'   => true,
            'exam'      => $exam,
            'questions'   => $questions,
            'draft'       => $draftData,
            'server_time' => now_dt(),
            'server_epoch'=> time(),
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat lembar ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Autosave jawaban draft ke database (padanan autosaveExam()).
 */
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

        $examRow = db_one('SELECT * FROM exams WHERE TRIM(id_ujian) = ?', [$idUjian]);
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

        db_exec(
            'UPDATE results SET jumlah_pelanggaran = ?, detail_jawaban = ?, deadline_at = COALESCE(deadline_at, ?) WHERE id_hasil = ?',
            [$violations, $answersJson, $deadline, tostr($existingRecord['row']['id_hasil'])]
        );

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

        log_activity(
            tostr($username),
            $jenis,
            'Ujian: ' . $idUjian . ' | Pelanggaran ke-' . $serverCount . ' | ' . $keterangan
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
        $examRow = db_one('SELECT * FROM exams WHERE TRIM(id_ujian) = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
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

        $qRows = db_all('SELECT id_soal, kunci_jawaban, bobot FROM questions WHERE TRIM(id_ujian) = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);
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
                    deadline_at = COALESCE(deadline_at, ?)
                 WHERE id_hasil = ?',
                [$nowStr, $violations, $nilaiAkhir, 'SUBMITTED', $answersJsonStr, $deadline, tostr($existingRecord['row']['id_hasil'])]
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

        $attemptsLimit = 1;
        $examRow = db_one('SELECT jumlah_percobaan FROM exams WHERE TRIM(id_ujian) = ?', [$idUjian]);
        if ($examRow) {
            $attemptsLimit = normalize_exam_attempts($examRow['jumlah_percobaan']);
        }
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
                'UPDATE results SET jumlah_pelanggaran = ?, status = ?, detail_jawaban = ? WHERE id_hasil = ?',
                [$violations, 'TERBLOKIR', $answersJson !== '' ? $answersJson : '{}', tostr($existingRecord['row']['id_hasil'])]
            );
        } else {
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('RES'), $idUjian, tostr($username), now_dt(), null, $violations, 0, 'TERBLOKIR',
                    $answersJson !== '' ? $answersJson : '{}', '', normalize_exam_attempts($requestedAttempt),
                ]
            );
        }

        return ['success' => true, 'message' => 'Ujian berhasil diblokir.'];
    } catch (Throwable $e) {
        return err('Gagal memblokir ujian: ' . client_error_message($e->getMessage()));
    }
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

        $newDeadline = exam_compute_deadline($examRow, time());
        db_exec(
            'UPDATE results SET status = ?, jumlah_pelanggaran = ?, waktu_mulai = ?, deadline_at = ? WHERE id_hasil = ?',
            ['DRAFT', $resetViolations, now_dt(), $newDeadline, tostr($existingRecord['row']['id_hasil'])]
        );

        log_activity(
            tostr($username),
            'Buka Blokir Siswa',
            'Buka blokir ujian ' . $targetExamId . ' untuk siswa ' . $targetStudent . ' | Pelanggaran diset ke ' . $resetViolations
        );

        return [
            'success' => true,
            'message' => 'Blokir ujian siswa ' . $targetStudent . ' berhasil dibuka. Siswa kini dapat melanjutkan ujian.',
        ];
    } catch (Throwable $e) {
        return err('Gagal membuka blokir: ' . client_error_message($e->getMessage()));
    }
}

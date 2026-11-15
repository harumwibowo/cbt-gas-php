<?php
/**
 * Alur pengerjaan ujian siswa: mulai, autosave, pelanggaran, submit, blokir.
 */
declare(strict_types=1);

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
            'nama_ujian'           => tostr($examRow['nama_ujian']),
            'durasi_menit'         => (int) $examRow['durasi_menit'] > 0 ? (int) $examRow['durasi_menit'] : 60,
            'waktu_mulai'          => format_datetime_iso($examRow['waktu_mulai']),
            'waktu_selesai'        => format_datetime_iso($examRow['waktu_selesai']),
            'acak_soal'            => tostr($examRow['acak_soal']) !== '' ? tostr($examRow['acak_soal']) : 'Ya',
            'acak_opsi'            => tostr($examRow['acak_opsi']) !== '' ? tostr($examRow['acak_opsi']) : 'Ya',
            'token_ujian'          => strtoupper(tostr($examRow['token_ujian'])),
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
        if ($exam['token_ujian'] !== '' && !$isResume) {
            $enteredToken = strtoupper(trim(tostr($inputToken)));
            if ($enteredToken === '' || $enteredToken !== $exam['token_ujian']) {
                return err('Token ujian salah. Silakan minta token yang valid kepada guru pengawas.');
            }
        }

        if ($draftData === null) {
            $nowStr = now_dt();
            $newResultId = new_id('RES');
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$newResultId, $idUjian, $userName, $nowStr, null, 0, 0, 'DRAFT', '{}', '', $attemptNumber]
            );
            $draftData = [
                'id_hasil'           => $newResultId,
                'waktu_mulai'        => $nowStr,
                'jumlah_pelanggaran' => 0,
                'detail_jawaban'     => '{}',
                'percobaan_ke'       => $attemptNumber,
                'is_resume'          => false,
            ];
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
                'teks_soal'    => tostr($qRow['teks_soal']),
                'url_gambar'   => tostr($qRow['url_gambar']),
                'bobot'        => (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1,
                'id_teks'      => $qIdTeks,
                'nama_teks'    => $qTeksObj ? $qTeksObj['nama_teks'] : '',
                'teks_konteks' => $qTeksObj ? $qTeksObj['isi_teks'] : '',
            ];
            foreach (option_letters() as $l) {
                $qObj['opsi_' . $l] = tostr($qRow['opsi_' . $l] ?? '');
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
            'questions' => $questions,
            'draft'     => $draftData,
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat lembar ujian: ' . $e->getMessage());
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
    $answersJson = is_string($payload['answers'] ?? null)
        ? (string) $payload['answers']
        : json_encode($payload['answers'] ?? [], JSON_UNESCAPED_UNICODE);
    $violations = (int) ($payload['violations'] ?? 0);

    try {
        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);

        if ($existingRecord !== null) {
            if (strtoupper(trim(tostr($existingRecord['row']['status']))) === 'SUBMITTED') {
                return err('Ujian sudah disubmit sebelumnya.');
            }
            db_exec(
                'UPDATE results SET jumlah_pelanggaran = ?, detail_jawaban = ? WHERE id_hasil = ?',
                [$violations, $answersJson, tostr($existingRecord['row']['id_hasil'])]
            );
        } else {
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('RES'), $idUjian, tostr($username), now_dt(), null, $violations, 0, 'DRAFT',
                    $answersJson, '', normalize_exam_attempts($requestedAttempt),
                ]
            );
        }

        return ['success' => true, 'timestamp' => date('H:i:s')];
    } catch (Throwable $e) {
        return err($e->getMessage());
    }
}

/**
 * Catat pelanggaran anti-cheat ke logs & results (padanan recordViolation()).
 */
function recordViolation($username, $token, $payload): array
{
    if (!validate_session($username, $token)) {
        return ['success' => false];
    }

    $payload = is_array($payload) ? $payload : [];
    $idUjian = trim(tostr($payload['id_ujian'] ?? ''));
    $jenis = tostr($payload['jenis'] ?? '') !== '' ? tostr($payload['jenis']) : 'Tab Switch';
    $violations = (int) ($payload['violations'] ?? 0) > 0 ? (int) $payload['violations'] : 1;
    $keterangan = tostr($payload['keterangan'] ?? '') !== '' ? tostr($payload['keterangan']) : 'Terdeteksi meninggalkan layar ujian.';

    log_activity(tostr($username), $jenis, 'Ujian: ' . $idUjian . ' | Pelanggaran ke-' . $violations . ' | ' . $keterangan);

    try {
        $existingRecord = find_result_record($idUjian, tostr($username));
        if ($existingRecord !== null && strtoupper(trim(tostr($existingRecord['row']['status']))) !== 'SUBMITTED') {
            db_exec(
                'UPDATE results SET jumlah_pelanggaran = ? WHERE id_hasil = ?',
                [$violations, tostr($existingRecord['row']['id_hasil'])]
            );
        }
    } catch (Throwable $e) {
        error_log('recordViolation: ' . $e->getMessage());
    }

    return ['success' => true];
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
    $violations = (int) ($payload['violations'] ?? 0);
    $isAutoSubmit = (bool) ($payload['isAutoSubmit'] ?? false);
    $reason = tostr($payload['reason'] ?? '') !== '' ? tostr($payload['reason']) : ($isAutoSubmit ? 'Auto Submit Sistem' : 'Selesai Manual');

    try {
        $requestedAttempt = $payload['percobaan_ke'] ?? null;
        $existingRecord = find_result_record($idUjian, tostr($username), $requestedAttempt);

        if ($existingRecord !== null && strtoupper(trim(tostr($existingRecord['row']['status']))) === 'SUBMITTED') {
            return err('Ujian ini sudah Anda kumpulkan sebelumnya.');
        }

        $submitAttemptNumber = $existingRecord !== null
            ? (int) $existingRecord['percobaan_ke']
            : normalize_exam_attempts($requestedAttempt);

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
                'UPDATE results SET waktu_submit = ?, jumlah_pelanggaran = ?, nilai_akhir = ?, status = ?, detail_jawaban = ?
                 WHERE id_hasil = ?',
                [$nowStr, $violations, $nilaiAkhir, 'SUBMITTED', $answersJsonStr, tostr($existingRecord['row']['id_hasil'])]
            );
        } else {
            db_exec(
                'INSERT INTO results (id_hasil, id_ujian, username_siswa, waktu_mulai, waktu_submit, jumlah_pelanggaran,
                    nilai_akhir, status, detail_jawaban, soal_terpilih, percobaan_ke)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('RES'), $idUjian, tostr($username), $nowStr, $nowStr, $violations, $nilaiAkhir,
                    'SUBMITTED', $answersJsonStr, '', $submitAttemptNumber,
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
        return err('Gagal memproses submit ujian: ' . $e->getMessage());
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
        return err('Gagal memblokir ujian: ' . $e->getMessage());
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
        $callerRole = current_role(tostr($username));
        if ($callerRole !== 'admin') {
            if ($callerRole === 'guru') {
                $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
                if (!$examRow || !can_manage_exam($examRow, tostr($username))) {
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

        db_exec(
            'UPDATE results SET status = ?, jumlah_pelanggaran = ?, waktu_mulai = ? WHERE id_hasil = ?',
            ['DRAFT', $resetViolations, now_dt(), tostr($existingRecord['row']['id_hasil'])]
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
        return err('Gagal membuka blokir: ' . $e->getMessage());
    }
}

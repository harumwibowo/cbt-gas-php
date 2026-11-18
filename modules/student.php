<?php
/**
 * Modul siswa: daftar ujian, mulai ujian, autosave, pelanggaran, submit,
 * blokir, dan pembahasan (padanan modul 7 pada Code.gs).
 */
declare(strict_types=1);

/**
 * Ringkas riwayat percobaan siswa pada satu ujian
 * (padanan summarizeStudentAttempts()).
 *
 * @param array $attemptRows daftar ['attempt','status','id_hasil','score','waktu_mulai','waktu_submit','jumlah_pelanggaran']
 */
function summarize_student_attempts(array $attemptRows, $limit): array
{
    $limitN = normalize_exam_attempts($limit);
    $byAttempt = [];

    foreach ($attemptRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $key = (string) normalize_exam_attempts($r['attempt'] ?? 1);
        if (!isset($byAttempt[$key])) {
            $byAttempt[$key] = $r;
            continue;
        }
        $cur = $byAttempt[$key];
        if (($r['status'] ?? '') === 'SUBMITTED' && ($cur['status'] ?? '') !== 'SUBMITTED') {
            $byAttempt[$key] = $r;
            continue;
        }
        if (($r['status'] ?? '') === ($cur['status'] ?? '') && (float) ($r['score'] ?? 0) > (float) ($cur['score'] ?? 0)) {
            $byAttempt[$key] = $r;
        }
    }

    $rows = array_values($byAttempt);
    usort($rows, static fn ($a, $b) => (int) $a['attempt'] <=> (int) $b['attempt']);

    $best = null;
    $draft = null;
    $blocked = null;
    foreach ($rows as $r) {
        $status = $r['status'] ?? '';
        if ($status === 'SUBMITTED' && ($best === null || (float) $r['score'] > (float) $best['score'])) {
            $best = $r;
        }
        if ($status === 'DRAFT') {
            $draft = $r;
        }
        if ($status === 'TERBLOKIR') {
            $blocked = $r;
        }
    }

    $used = count($rows);
    return [
        'rows'           => $rows,
        'attempts_used'  => $used,
        'attempts_limit' => $limitN,
        'attempts_left'  => max(0, $limitN - $used),
        'best'           => $best,
        'draft'          => $draft,
        'is_blocked'     => $blocked !== null,
        'is_complete'    => $used >= $limitN && $best !== null && $draft === null && $blocked === null,
    ];
}

/** Ambil baris profil siswa (kelas & sesi). */
function student_profile(string $username): array
{
    $user = find_user($username);
    return [
        'kelas' => $user ? trim(tostr($user['kelas'])) : '',
        'sesi'  => $user ? trim(tostr($user['sesi'])) : '',
    ];
}

/** Nilai waktu 'Y-m-d H:i:s' → timestamp, atau null. */
function dt_ts($val): ?int
{
    if ($val === null || tostr($val) === '' || str_starts_with(tostr($val), '0000')) {
        return null;
    }
    $ts = strtotime(tostr($val));
    return $ts === false ? null : $ts;
}

/**
 * Daftar ujian yang tersedia untuk siswa (padanan getStudentAvailableExams()).
 */
function getStudentAvailableExams($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $userLower = strtolower(trim(tostr($username)));
        $profile = student_profile(tostr($username));
        $studentClass = $profile['kelas'];
        $studentSession = $profile['sesi'];

        $qCounts = question_counts();

        // Riwayat pengerjaan siswa (tiap baris = satu percobaan)
        $studentAttempts = [];
        $rRows = db_all(
            'SELECT * FROM results WHERE LOWER(TRIM(username_siswa)) = ? ORDER BY id_hasil ASC',
            [$userLower]
        );
        foreach ($rRows as $row) {
            $rIdUjian = tostr($row['id_ujian']);
            if ($rIdUjian === '') {
                continue;
            }
            $studentAttempts[$rIdUjian][] = [
                'attempt'            => get_result_attempt_number($row),
                'status'             => strtoupper(trim(tostr($row['status']))),
                'id_hasil'           => tostr($row['id_hasil']),
                'score'              => (float) $row['nilai_akhir'],
                'waktu_mulai'        => format_datetime_iso($row['waktu_mulai']),
                'waktu_submit'       => format_datetime_iso($row['waktu_submit']),
                'jumlah_pelanggaran' => (int) $row['jumlah_pelanggaran'],
            ];
        }

        $now = time();
        $activeList = [];
        $completedList = [];
        $allList = [];

        $examRows = db_all('SELECT * FROM exams ORDER BY created_at ASC');
        foreach ($examRows as $examRow) {
            $examId = tostr($examRow['id_ujian']);
            if ($examId === '') {
                continue;
            }

            $examClassesRaw = trim(tostr($examRow['kelas']));
            $examSessionsRaw = trim(tostr($examRow['sesi']));
            $tipePeserta = tostr($examRow['tipe_peserta']) !== '' ? strtolower(tostr($examRow['tipe_peserta'])) : 'general';
            $pesertaKhususRaw = trim(tostr($examRow['peserta_khusus']));

            if ($tipePeserta === 'khusus') {
                $allowedStudents = array_map('strtolower', split_list($pesertaKhususRaw));
                if (!in_array($userLower, $allowedStudents, true)) {
                    continue;
                }
            } else {
                $lowerClasses = strtolower($examClassesRaw);
                if ($examClassesRaw !== '' && !in_array($lowerClasses, ['semua', 'all', 'semua kelas', '-'], true)) {
                    $allowedClasses = array_map('strtolower', split_list($examClassesRaw));
                    $sClassLower = strtolower($studentClass);
                    $isMatch = false;
                    foreach ($allowedClasses as $ac) {
                        if ($ac === '' ) {
                            continue;
                        }
                        if ($ac === $sClassLower || in_array($ac, ['semua', 'all', 'semua kelas'], true)) {
                            $isMatch = true;
                            break;
                        }
                    }
                    if (!$isMatch) {
                        continue;
                    }
                }

                $lowerSessions = strtolower($examSessionsRaw);
                if ($examSessionsRaw !== '' && !in_array($lowerSessions, ['semua', 'all', 'semua sesi', '-'], true)) {
                    $allowedSessions = array_map('strtolower', split_list($examSessionsRaw));
                    $sSesiLower = strtolower($studentSession);
                    $isSesiMatch = false;
                    foreach ($allowedSessions as $as) {
                        if ($as === '') {
                            continue;
                        }
                        if ($as === $sSesiLower || in_array($as, ['semua', 'all', 'semua sesi'], true)) {
                            $isSesiMatch = true;
                            break;
                        }
                    }
                    if (!$isSesiMatch) {
                        continue;
                    }
                }
            }

            $qCount = $qCounts[$examId] ?? 0;
            if ($qCount === 0) {
                continue;
            }

            $rawMulai = format_datetime_iso($examRow['waktu_mulai']);
            $rawSelesai = format_datetime_iso($examRow['waktu_selesai']);
            $startTs = dt_ts($examRow['waktu_mulai']);
            $endTs = dt_ts($examRow['waktu_selesai']);

            $attemptLimit = normalize_exam_attempts($examRow['jumlah_percobaan']);
            $attemptSummary = summarize_student_attempts($studentAttempts[$examId] ?? [], $attemptLimit);
            $attemptRows = $attemptSummary['rows'];
            $latestAttempt = count($attemptRows) > 0 ? $attemptRows[count($attemptRows) - 1] : null;

            // Ringkasan hasil siswa
            $myResult = null;
            if ($attemptSummary['draft'] !== null) {
                $draftRow = $attemptSummary['draft'];
                $myResult = [
                    'status'             => 'DRAFT',
                    'id_hasil'           => $draftRow['id_hasil'],
                    'nilai_akhir'        => $attemptSummary['best'] ? (float) $attemptSummary['best']['score'] : 0,
                    'waktu_mulai'        => $draftRow['waktu_mulai'],
                    'waktu_submit'       => $draftRow['waktu_submit'],
                    'jumlah_pelanggaran' => $draftRow['jumlah_pelanggaran'],
                ];
            } elseif ($attemptSummary['is_blocked']) {
                $blockedRows = array_values(array_filter($attemptRows, static fn ($r) => $r['status'] === 'TERBLOKIR'));
                $blockedRow = count($blockedRows) > 0 ? $blockedRows[count($blockedRows) - 1] : null;
                $myResult = [
                    'status'             => 'TERBLOKIR',
                    'id_hasil'           => $blockedRow ? $blockedRow['id_hasil'] : '',
                    'nilai_akhir'        => $attemptSummary['best'] ? (float) $attemptSummary['best']['score'] : 0,
                    'waktu_mulai'        => $blockedRow ? $blockedRow['waktu_mulai'] : '',
                    'waktu_submit'       => $blockedRow ? $blockedRow['waktu_submit'] : '',
                    'jumlah_pelanggaran' => $blockedRow ? $blockedRow['jumlah_pelanggaran'] : 0,
                ];
            } elseif ($attemptSummary['best'] !== null) {
                $bestRow = $attemptSummary['best'];
                $myResult = [
                    'status'             => 'SUBMITTED',
                    'id_hasil'           => $bestRow['id_hasil'],
                    'nilai_akhir'        => (float) $bestRow['score'],
                    'waktu_mulai'        => $bestRow['waktu_mulai'],
                    'waktu_submit'       => $bestRow['waktu_submit'],
                    'jumlah_pelanggaran' => $bestRow['jumlah_pelanggaran'],
                ];
            }

            if ($myResult !== null) {
                $myResult['percobaan_ke'] = $latestAttempt ? (int) $latestAttempt['attempt'] : 1;
                $myResult['jumlah_percobaan_dilakukan'] = $attemptSummary['attempts_used'];
                $myResult['nilai_tertinggi'] = $attemptSummary['best'] ? (float) $attemptSummary['best']['score'] : 0;

                $totalPelanggaranSemua = 0;
                foreach ($attemptRows as $rw) {
                    $totalPelanggaranSemua += (int) ($rw['jumlah_pelanggaran'] ?? 0);
                }
                $myResult['total_pelanggaran'] = $totalPelanggaranSemua;
                if ($myResult['status'] === 'SUBMITTED') {
                    $myResult['jumlah_pelanggaran'] = $totalPelanggaranSemua;
                }
            }

            $examState = 'OPEN';
            if ($attemptSummary['is_blocked']) {
                $examState = 'TERBLOKIR';
            } elseif ($attemptSummary['is_complete']) {
                $examState = 'SUBMITTED';
            } elseif ($startTs !== null && $now < $startTs) {
                $examState = 'UPCOMING';
            } elseif ($endTs !== null && $now > $endTs) {
                $examState = 'EXPIRED';
            }

            if (($examState === 'EXPIRED' || $examState === 'UPCOMING') && $attemptSummary['best'] !== null) {
                $examState = 'SUBMITTED';
            }

            $tokenVal = strtoupper(tostr($examRow['token_ujian']));
            $item = [
                'id_ujian'             => $examId,
                'nama_ujian'           => sanitize_plain_text($examRow['nama_ujian']),
                'durasi_menit'         => (int) $examRow['durasi_menit'] > 0 ? (int) $examRow['durasi_menit'] : 60,
                'waktu_mulai'          => $rawMulai,
                'waktu_selesai'        => $rawSelesai,
                'acak_soal'            => tostr($examRow['acak_soal']) !== '' ? tostr($examRow['acak_soal']) : 'Ya',
                'acak_opsi'            => tostr($examRow['acak_opsi']) !== '' ? tostr($examRow['acak_opsi']) : 'Ya',
                // Token tidak dikirim ke siswa; hanya status pemakaian token.
                'has_token'            => $tokenVal !== '',
                'kelas'                => $tipePeserta === 'khusus' ? 'Peserta Khusus' : ($examClassesRaw !== '' ? $examClassesRaw : 'Semua Kelas'),
                'sesi'                 => $examSessionsRaw !== '' ? $examSessionsRaw : 'Semua Sesi',
                'tipe_peserta'         => $tipePeserta,
                'peserta_khusus'       => $pesertaKhususRaw,
                'jumlah_soal'          => $qCount,
                'jumlah_soal_tampil'   => (int) $examRow['jumlah_soal_tampil'],
                'jumlah_percobaan'     => $attemptLimit,
                'percobaan_dilakukan'  => $attemptSummary['attempts_used'],
                'sisa_percobaan'       => $attemptSummary['attempts_left'],
                'nilai_tertinggi'      => $attemptSummary['best'] ? (float) $attemptSummary['best']['score'] : 0,
                'tampilkan_pembahasan' => strtolower(trim(tostr($examRow['tampilkan_pembahasan']))) === 'ya' ? 'Ya' : 'Tidak',
                'max_violations'       => violation_policy()['max'],
                'tindakan_pelanggaran' => violation_policy()['action'],
                'student_result'       => $myResult,
                'exam_state'           => $examState,
            ];

            $allList[] = $item;

            if ($attemptSummary['best'] !== null) {
                $completedList[] = $item;
            }
            if ($examState === 'OPEN' || $examState === 'TERBLOKIR') {
                $activeList[] = $item;
            }
        }

        return [
            'success'          => true,
            'student_class'    => $studentClass,
            'student_session'  => $studentSession,
            'exams'            => $activeList,
            'completed_exams'  => $completedList,
            'all_exams'        => $allList,
            'server_time'      => now_dt(),
            'server_epoch'     => time(),
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat ujian siswa: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Pembahasan ujian untuk siswa (padanan getStudentExamReview()).
 */
function getStudentExamReview($username, $token, $id_ujian): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $targetExamId = trim(tostr($id_ujian));
    if ($targetExamId === '') {
        return err('ID ujian tidak valid.');
    }

    try {
        $examRow = db_one('SELECT * FROM exams WHERE TRIM(id_ujian) = ?', [$targetExamId]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }
        $examInfo = [
            'id_ujian'             => $targetExamId,
            'nama_ujian'           => sanitize_plain_text($examRow['nama_ujian']),
            'tampilkan_pembahasan' => strtolower(trim(tostr($examRow['tampilkan_pembahasan']))) === 'ya' ? 'Ya' : 'Tidak',
        ];
        if ($examInfo['tampilkan_pembahasan'] !== 'Ya') {
            return err('Kunci jawaban & pembahasan untuk ujian ini tidak dibuka oleh guru pengampu.');
        }

        // Hasil siswa: pakai percobaan dengan nilai tertinggi
        $uLower = strtolower(trim(tostr($username)));
        $rows = db_all(
            'SELECT * FROM results WHERE TRIM(id_ujian) = ? AND LOWER(TRIM(username_siswa)) = ? ORDER BY id_hasil ASC',
            [$targetExamId, $uLower]
        );

        $myResult = null;
        foreach ($rows as $row) {
            $rowStatus = strtoupper(trim(tostr($row['status'])));
            $rowScore = (float) $row['nilai_akhir'];
            $rowAttempt = get_result_attempt_number($row);
            $candidate = [
                'status'       => $rowStatus,
                'score'        => $rowScore,
                'percobaan_ke' => $rowAttempt,
                'detail'       => tostr($row['detail_jawaban']),
                'terpilih'     => tostr($row['soal_terpilih']),
            ];
            if ($myResult === null) {
                $myResult = $candidate;
            } elseif ($rowStatus === 'SUBMITTED') {
                if ($myResult['status'] !== 'SUBMITTED'
                    || $rowScore > (float) $myResult['score']
                    || ($rowScore === (float) $myResult['score'] && $rowAttempt > (int) $myResult['percobaan_ke'])) {
                    $myResult = $candidate;
                }
            }
        }

        if ($myResult === null) {
            return err('Anda belum menyelesaikan ujian ini.');
        }
        if ($myResult['status'] !== 'SUBMITTED') {
            return err('Pembahasan hanya dapat dibuka setelah ujian Anda selesai dikumpulkan.');
        }

        $studentAnswers = parse_answer_detail($myResult['detail']);

        // Paket soal acak milik siswa
        $selectedSet = null;
        if (tostr($myResult['terpilih']) !== '') {
            $tmp = [];
            foreach (explode(',', tostr($myResult['terpilih'])) as $sid) {
                $clean = trim($sid);
                if ($clean !== '') {
                    $tmp[$clean] = true;
                }
            }
            if (count($tmp) > 0) {
                $selectedSet = $tmp;
            }
        }

        $textMap = exam_text_map($targetExamId);
        $qRows = db_all('SELECT * FROM questions WHERE TRIM(id_ujian) = ? ORDER BY urutan ASC, id_soal ASC', [$targetExamId]);

        $benar = 0;
        $salah = 0;
        $kosong = 0;
        $questions = [];

        foreach ($qRows as $qRow) {
            $qId = tostr($qRow['id_soal']);
            if ($selectedSet !== null && !isset($selectedSet[$qId])) {
                continue;
            }
            $kunci = strtoupper(trim(tostr($qRow['kunci_jawaban'])));
            $jawabanSiswa = strtoupper(trim(tostr($studentAnswers[$qId] ?? '')));
            $isBenar = $jawabanSiswa !== '' && $jawabanSiswa === $kunci;
            if ($jawabanSiswa === '') {
                $kosong++;
            } elseif ($isBenar) {
                $benar++;
            } else {
                $salah++;
            }

            $qIdTeks = trim(tostr($qRow['id_teks']));
            $qTeksObj = $textMap[$qIdTeks] ?? null;
            $item = [
                'id_soal'       => $qId,
                'teks_soal'     => sanitize_html_fragment($qRow['teks_soal']),
                'url_gambar'    => safe_media_url($qRow['url_gambar']),
                'kunci_jawaban' => $kunci,
                'pembahasan'    => sanitize_html_fragment($qRow['pembahasan']),
                'bobot'         => (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1,
                'jawaban_siswa' => $jawabanSiswa,
                'is_benar'      => $isBenar,
                'nama_teks'     => $qTeksObj ? sanitize_plain_text($qTeksObj['nama_teks']) : '',
                'teks_konteks'  => $qTeksObj ? sanitize_html_fragment($qTeksObj['isi_teks']) : '',
            ];
            foreach (option_letters() as $l) {
                $item['opsi_' . $l] = sanitize_html_fragment($qRow['opsi_' . $l] ?? '');
            }
            $questions[] = $item;
        }

        // Fallback: bila paket acak tidak tersimpan, tampilkan seluruh soal ujian
        if (count($questions) === 0) {
            foreach ($qRows as $qRow) {
                $qId2 = tostr($qRow['id_soal']);
                $kunci2 = strtoupper(trim(tostr($qRow['kunci_jawaban'])));
                $jawaban2 = strtoupper(trim(tostr($studentAnswers[$qId2] ?? '')));
                $isBenar2 = $jawaban2 !== '' && $jawaban2 === $kunci2;
                if ($jawaban2 === '') {
                    $kosong++;
                } elseif ($isBenar2) {
                    $benar++;
                } else {
                    $salah++;
                }

                $qIdTeks2 = trim(tostr($qRow['id_teks']));
                $qTeksObj2 = $textMap[$qIdTeks2] ?? null;
                $item2 = [
                    'id_soal'       => $qId2,
                    'teks_soal'     => sanitize_html_fragment($qRow['teks_soal']),
                    'url_gambar'    => safe_media_url($qRow['url_gambar']),
                    'kunci_jawaban' => $kunci2,
                    'pembahasan'    => sanitize_html_fragment($qRow['pembahasan']),
                    'bobot'         => (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1,
                    'jawaban_siswa' => $jawaban2,
                    'is_benar'      => $isBenar2,
                    'nama_teks'     => $qTeksObj2 ? $qTeksObj2['nama_teks'] : '',
                    'teks_konteks'  => $qTeksObj2 ? $qTeksObj2['isi_teks'] : '',
                ];
                foreach (option_letters() as $l) {
                    $item2['opsi_' . $l] = sanitize_html_fragment($qRow['opsi_' . $l] ?? '');
                }
                $questions[] = $item2;
            }
        }

        return [
            'success'   => true,
            'exam'      => $examInfo,
            'questions' => $questions,
            'summary'   => [
                'total'  => count($questions),
                'benar'  => $benar,
                'salah'  => $salah,
                'kosong' => $kosong,
            ],
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat pembahasan ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Cari (dan rapikan duplikat) baris hasil siswa untuk satu ujian
 * (padanan findAndConsolidateStudentResult()).
 *
 * @return array|null ['row'=>array,'percobaan_ke'=>int,'total_percobaan'=>int,'nilai_tertinggi'=>float]
 */
function find_result_record(string $idUjian, string $username, $percobaanKe = null): ?array
{
    $targetExamId = trim($idUjian);
    $targetUsername = strtolower(trim($username));
    if ($targetExamId === '' || $targetUsername === '') {
        return null;
    }

    $rows = db_all(
        'SELECT * FROM results WHERE TRIM(id_ujian) = ? AND LOWER(TRIM(username_siswa)) = ? ORDER BY id_hasil ASC',
        [$targetExamId, $targetUsername]
    );
    if (count($rows) === 0) {
        return null;
    }

    // Kelompokkan per percobaan lalu pilih yang terbaik. Jalur baca bersifat
    // read-only; duplikat lama dibersihkan oleh migrasi unique index.
    $grouped = [];
    foreach ($rows as $row) {
        $key = (string) get_result_attempt_number($row);
        if (!isset($grouped[$key])) {
            $grouped[$key] = $row;
            continue;
        }
        $chosen = $grouped[$key];
        $rowStatus = strtoupper(trim(tostr($row['status'])));
        $chosenStatus = strtoupper(trim(tostr($chosen['status'])));
        if ($rowStatus === 'SUBMITTED' && $chosenStatus !== 'SUBMITTED') {
            $grouped[$key] = $row;
        } elseif ($rowStatus === $chosenStatus && (float) $row['nilai_akhir'] > (float) $chosen['nilai_akhir']) {
            $grouped[$key] = $row;
        }
    }

    $attempts = array_keys($grouped);
    $totalPercobaan = count($attempts);

    $targetAttempt = null;
    if ($percobaanKe !== null && trim(tostr($percobaanKe)) !== '') {
        $targetAttempt = normalize_exam_attempts($percobaanKe);
    } else {
        foreach ($attempts as $a) {
            $aInt = (int) $a;
            if ($targetAttempt === null || $aInt > $targetAttempt) {
                $targetAttempt = $aInt;
            }
        }
    }

    if (!isset($grouped[(string) $targetAttempt])) {
        return null;
    }

    $nilaiTertinggi = 0.0;
    foreach ($grouped as $row) {
        if (strtoupper(trim(tostr($row['status']))) === 'SUBMITTED') {
            $score = (float) $row['nilai_akhir'];
            if ($score > $nilaiTertinggi) {
                $nilaiTertinggi = $score;
            }
        }
    }

    return [
        'row'              => $grouped[(string) $targetAttempt],
        'percobaan_ke'     => $targetAttempt,
        'total_percobaan'  => $totalPercobaan,
        'nilai_tertinggi'  => $nilaiTertinggi,
    ];
}

/** Generator pseudo-random deterministik (padanan createPRNG()). */
function make_prng(string $seedStr): callable
{
    $hash = 0;
    $len = strlen($seedStr);
    for ($p = 0; $p < $len; $p++) {
        $hash = (($hash << 5) - $hash) + ord($seedStr[$p]);
        $hash &= 0xFFFFFFFF;
        if ($hash >= 0x80000000) {
            $hash -= 0x100000000;
        }
    }
    $seed = abs($hash);
    if ($seed === 0) {
        $seed = 1234567;
    }
    return static function () use (&$seed) {
        $seed = ($seed * 9301 + 49297) % 233280;
        return $seed / 233280;
    };
}

/** Acak array dengan generator deterministik (Fisher-Yates). */
function shuffle_with(callable $rng, array $items): array
{
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = (int) floor($rng() * ($i + 1));
        $tmp = $items[$i];
        $items[$i] = $items[$j];
        $items[$j] = $tmp;
    }
    return $items;
}

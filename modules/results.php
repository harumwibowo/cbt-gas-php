<?php
/**
 * Rekapitulasi nilai, pelanggaran, dan nilai ulang (regrade)
 * (padanan modul 6a/6b pada Code.gs).
 */
declare(strict_types=1);

/** Ambil detail jawaban (JSON string) menjadi array asosiatif. */
function parse_answer_detail($raw): array
{
    $raw = tostr($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Hitung nilai akhir (skala 0-100, satu desimal) dari jawaban siswa.
 *
 * @param array $answers      map id_soal => 'A'
 * @param array $keyMap       map id_soal => ['kunci' => 'A', 'bobot' => 1]
 * @param array $questionOrder urutan id_soal pada ujian
 * @param array|null $selectedSet set id_soal paket acak siswa (null = semua soal)
 */
function compute_final_score(array $answers, array $keyMap, array $questionOrder, ?array $selectedSet = null): float
{
    $totalBobot = 0;
    $skor = 0;
    foreach ($questionOrder as $soalId) {
        if ($selectedSet !== null && !isset($selectedSet[$soalId])) {
            continue;
        }
        if (!isset($keyMap[$soalId])) {
            continue;
        }
        $bobotQ = $keyMap[$soalId]['bobot'];
        $totalBobot += $bobotQ;
        $jawaban = strtoupper(trim(tostr($answers[$soalId] ?? '')));
        if ($jawaban !== '' && $jawaban === $keyMap[$soalId]['kunci']) {
            $skor += $bobotQ;
        }
    }
    return $totalBobot > 0 ? round(($skor / $totalBobot) * 100, 1) : 0.0;
}

/** Kunci jawaban & bobot terbaru satu ujian. */
function exam_key_map(string $idUjian): array
{
    $keyMap = [];
    $order = [];
    $rows = db_all('SELECT id_soal, kunci_jawaban, bobot FROM questions WHERE id_ujian = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);
    foreach ($rows as $row) {
        $qId = trim(tostr($row['id_soal']));
        if ($qId === '') {
            continue;
        }
        $keyMap[$qId] = [
            'kunci' => strtoupper(trim(tostr($row['kunci_jawaban']))),
            'bobot' => (int) $row['bobot'] > 0 ? (int) $row['bobot'] : 1,
        ];
        $order[] = $qId;
    }
    return ['map' => $keyMap, 'order' => $order];
}

/** Apakah pemanggil boleh mengelola ujian (admin, pemilik, atau kolaborator). */
function can_manage_exam(array $exam, string $username): bool
{
    $role = current_role($username);
    if ($role === 'admin') {
        return true;
    }
    $userLower = strtolower(trim($username));
    $owner = strtolower(trim(tostr($exam['id_guru'] ?? '')));
    if ($owner === '' || $owner === $userLower) {
        return true;
    }
    return in_array($userLower, array_map('strtolower', split_list(tostr($exam['kolaborator'] ?? ''))), true);
}

/**
 * Rekapitulasi nilai satu ujian (padanan getExamResults()).
 */
function getExamResults($username, $token, $id_ujian, $includeDetail = true): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    try {
        $targetExamId = trim(tostr($id_ujian));
        $callerRole = current_role(tostr($username));

        if ($callerRole === 'guru' && $targetExamId !== '') {
            $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
            if ($examRow && !can_manage_exam($examRow, tostr($username))) {
                return err('Anda tidak memiliki hak akses untuk melihat rekap ujian ini.');
            }
        }

        // Maps data pengguna
        $usersMap = [];
        foreach (db_all('SELECT username, nama_lengkap, kelas, sesi FROM users') as $u) {
            $key = strtolower(tostr($u['username']));
            if ($key !== '') {
                $usersMap[$key] = [
                    'nama_lengkap' => tostr($u['nama_lengkap']) !== '' ? tostr($u['nama_lengkap']) : tostr($u['username']),
                    'kelas'        => tostr($u['kelas']) !== '' ? tostr($u['kelas']) : '-',
                    'sesi'         => tostr($u['sesi']) !== '' ? tostr($u['sesi']) : '-',
                ];
            }
        }

        // Ambil baris hasil (satu ujian atau semua bila id kosong)
        if ($targetExamId !== '') {
            $rows = db_all('SELECT * FROM results WHERE id_ujian = ? ORDER BY id_hasil ASC', [$targetExamId]);
        } else {
            $rows = db_all('SELECT * FROM results ORDER BY id_hasil ASC');
        }

        // Kelompokkan per siswa → per percobaan, sambil menandai duplikat
        $grouped = [];
        foreach ($rows as $row) {
            $userUname = trim(tostr($row['username_siswa']));
            $userKey = strtolower($userUname);
            if ($userKey === '') {
                continue;
            }
            $uInfo = $usersMap[$userKey] ?? ['nama_lengkap' => $userUname, 'kelas' => '-', 'sesi' => '-'];
            $attemptNo = get_result_attempt_number($row);
            $record = [
                'id_hasil'           => tostr($row['id_hasil']),
                'id_ujian'           => tostr($row['id_ujian']),
                'username_siswa'     => $userUname,
                'nama_siswa'         => $uInfo['nama_lengkap'],
                'kelas'              => $uInfo['kelas'],
                'sesi'               => $uInfo['sesi'],
                'waktu_mulai'        => tostr($row['waktu_mulai']),
                'waktu_submit'       => tostr($row['waktu_submit']),
                'jumlah_pelanggaran' => (int) $row['jumlah_pelanggaran'],
                'nilai_akhir'        => (float) $row['nilai_akhir'],
                'status'             => tostr($row['status']),
                // The LONGTEXT jawaban only dikirim saat benar-benar dibutuhkan
                // (halaman detail). Rekap daftar memakai $includeDetail = false.
                'detail_jawaban'     => $includeDetail ? tostr($row['detail_jawaban']) : '',
                'soal_terpilih'      => $includeDetail ? tostr($row['soal_terpilih']) : '',
                'percobaan_ke'       => $attemptNo,
            ];

            $attemptKey = (string) $attemptNo;
            // Kunci HARUS menyertakan id_ujian: saat rekap "semua ujian",
            // percobaan ke-1 ujian A dan ujian B tidak boleh bertabrakan.
            $groupKey = $userKey . '|' . strtolower(trim(tostr($row['id_ujian'])));
            $existing = $grouped[$groupKey]['attempts'][$attemptKey] ?? null;
            if ($existing === null) {
                $grouped[$groupKey]['attempts'][$attemptKey] = $record;
            } elseif ($record['status'] === 'SUBMITTED' && $existing['status'] !== 'SUBMITTED') {
                $grouped[$groupKey]['attempts'][$attemptKey] = $record;
            } elseif ($record['status'] === $existing['status'] && $record['nilai_akhir'] > $existing['nilai_akhir']) {
                $grouped[$groupKey]['attempts'][$attemptKey] = $record;
            }
        }

        // Duplikat lama dibersihkan oleh migrasi database (unique index),
        // bukan pada jalur baca. Jalur baca tidak boleh menghapus data.

        // Susun ringkasan per siswa
        $results = [];
        foreach ($grouped as $unameKey => $bundle) {
            $bestItem = null;
            $attemptCount = 0;
            $totalViolations = 0;
            $violationsPerAttempt = [];

            foreach ($bundle['attempts'] as $cand) {
                $attemptCount++;
                $totalViolations += (int) $cand['jumlah_pelanggaran'];
                $violationsPerAttempt[] = [
                    'percobaan_ke'       => (int) $cand['percobaan_ke'],
                    'jumlah_pelanggaran' => (int) $cand['jumlah_pelanggaran'],
                ];
                if ($bestItem === null) {
                    $bestItem = $cand;
                } elseif ($cand['status'] === 'SUBMITTED' && $bestItem['status'] !== 'SUBMITTED') {
                    $bestItem = $cand;
                } elseif ($cand['status'] === $bestItem['status'] && $cand['nilai_akhir'] > $bestItem['nilai_akhir']) {
                    $bestItem = $cand;
                }
            }
            if ($bestItem === null) {
                continue;
            }

            usort($violationsPerAttempt, static fn ($a, $b) => $a['percobaan_ke'] <=> $b['percobaan_ke']);

            $results[] = [
                'id_hasil'                   => $bestItem['id_hasil'],
                'id_ujian'                   => $bestItem['id_ujian'],
                'username_siswa'             => $bestItem['username_siswa'],
                'nama_siswa'                 => $bestItem['nama_siswa'],
                'kelas'                      => $bestItem['kelas'],
                'sesi'                       => $bestItem['sesi'] !== '' ? $bestItem['sesi'] : '-',
                'waktu_mulai'                => $bestItem['waktu_mulai'],
                'waktu_submit'               => $bestItem['waktu_submit'],
                'jumlah_pelanggaran'         => $totalViolations,
                'pelanggaran_percobaan'      => $violationsPerAttempt,
                'nilai_akhir'                => $bestItem['nilai_akhir'],
                'nilai_tertinggi'            => $bestItem['nilai_akhir'],
                'status'                     => $bestItem['status'],
                'detail_jawaban'             => $includeDetail ? $bestItem['detail_jawaban'] : '',
                'soal_terpilih'              => $includeDetail ? $bestItem['soal_terpilih'] : '',
                'percobaan_ke'               => $bestItem['percobaan_ke'],
                'jumlah_percobaan_dilakukan' => $attemptCount,
            ];
        }

        // Info ujian
        $examInfo = [
            'id_ujian'         => $targetExamId,
            'nama_ujian'       => $targetExamId,
            'kelas'            => '',
            'sesi'             => '',
            'tipe_peserta'     => 'general',
            'peserta_khusus'   => '',
            'kolaborator'      => '',
            'tingkat'          => '',
            'jumlah_percobaan' => 1,
        ];
        if ($targetExamId !== '') {
            $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
            if ($examRow) {
                $examInfo['nama_ujian'] = tostr($examRow['nama_ujian']) !== '' ? sanitize_plain_text($examRow['nama_ujian']) : $targetExamId;
                $examInfo['kelas'] = tostr($examRow['kelas']);
                $examInfo['sesi'] = tostr($examRow['sesi']);
                $examInfo['tipe_peserta'] = tostr($examRow['tipe_peserta']) !== '' ? strtolower(tostr($examRow['tipe_peserta'])) : 'general';
                $examInfo['peserta_khusus'] = tostr($examRow['peserta_khusus']);
                $examInfo['kolaborator'] = tostr($examRow['kolaborator']);
                $examInfo['tingkat'] = tostr($examRow['tingkat']);
                $examInfo['jumlah_percobaan'] = normalize_exam_attempts($examRow['jumlah_percobaan']);
            }
        }
        foreach ($results as &$rItem) {
            $rItem['jumlah_percobaan'] = $examInfo['jumlah_percobaan'];
        }
        unset($rItem);

        // Soal ujian untuk keperluan review jawaban siswa
        $examQuestions = [];
        if ($targetExamId !== '') {
            $textMap = exam_text_map($targetExamId);
            $qRows = db_all('SELECT * FROM questions WHERE id_ujian = ? ORDER BY urutan ASC, id_soal ASC', [$targetExamId]);
            foreach ($qRows as $qRow) {
                $qIdTeks = trim(tostr($qRow['id_teks']));
                $qTeksObj = $textMap[$qIdTeks] ?? null;
                $qObj = [
                    'id_soal'       => tostr($qRow['id_soal']),
                    'teks_soal'     => sanitize_html_fragment($qRow['teks_soal']),
                    'url_gambar'    => safe_media_url($qRow['url_gambar']),
                    'kunci_jawaban' => strtoupper(trim(tostr($qRow['kunci_jawaban']))),
                    'bobot'         => (int) $qRow['bobot'] > 0 ? (int) $qRow['bobot'] : 1,
                    'id_teks'       => $qIdTeks,
                    'nama_teks'     => $qTeksObj ? sanitize_plain_text($qTeksObj['nama_teks']) : '',
                    'teks_konteks'  => $qTeksObj ? sanitize_html_fragment($qTeksObj['isi_teks']) : '',
                ];
                foreach (option_letters() as $l) {
                    $qObj['opsi_' . $l] = sanitize_html_fragment($qRow['opsi_' . $l] ?? '');
                }
                $examQuestions[] = $qObj;
            }
        }

        return [
            'success'   => true,
            'results'   => $results,
            'exam'      => $examInfo,
            'questions' => $examQuestions,
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat rekapitulasi nilai: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Ambil jawaban lengkap SATU hasil ujian.
 *
 * Dipanggil hanya ketika guru/admin membuka halaman detail jawaban, sehingga
 * rekap daftar tidak perlu mengirim LONGTEXT detail_jawaban untuk semua siswa.
 */
function getExamResultDetail($username, $token, $id_hasil): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }
    $role = current_role(tostr($username));
    if (!in_array($role, ['admin', 'guru'], true)) {
        return err('Akses ditolak. Hanya Admin atau Guru yang dapat melihat jawaban.');
    }

    try {
        $row = db_one(
            'SELECT id_hasil, id_ujian, username_siswa, detail_jawaban, soal_terpilih
             FROM results WHERE id_hasil = ? LIMIT 1',
            [trim(tostr($id_hasil))]
        );
        if (!$row) {
            return err('Hasil ujian tidak ditemukan.');
        }
        if ($role === 'guru') {
            $exam = db_one('SELECT * FROM exams WHERE id_ujian = ?', [tostr($row['id_ujian'])]);
            if (!$exam || !can_manage_exam($exam, tostr($username))) {
                return err('Anda tidak memiliki hak akses untuk melihat jawaban ini.');
            }
        }
        return [
            'success' => true,
            'detail'  => [
                'id_hasil'       => tostr($row['id_hasil']),
                'id_ujian'       => tostr($row['id_ujian']),
                'username_siswa' => tostr($row['username_siswa']),
                'detail_jawaban' => tostr($row['detail_jawaban']),
                'soal_terpilih'  => tostr($row['soal_terpilih']),
            ],
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat detail jawaban: ' . client_error_message($e->getMessage()));
    }
}

/** Hapus seluruh hasil ujian seorang siswa. */
function deleteStudentExamResult($username, $token, $id_ujian, $username_siswa): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    $targetExamId = trim(tostr($id_ujian));
    $targetUser = strtolower(trim(tostr($username_siswa)));
    if ($targetExamId === '' || $targetUser === '') {
        return err('Data ujian atau siswa tidak valid.');
    }

    try {
        $deleted = db_exec(
            'DELETE FROM results WHERE id_ujian = ? AND username_siswa = ?',
            [$targetExamId, $targetUser]
        );
        if ($deleted === 0) {
            return err('Data hasil ujian siswa tidak ditemukan.');
        }
        log_activity(
            tostr($username),
            'Delete Student Result',
            'Menghapus data hasil ujian siswa ' . $targetUser . ' pada ujian ' . $targetExamId
        );
        return ['success' => true, 'message' => 'Data hasil ujian siswa berhasil dihapus.'];
    } catch (Throwable $e) {
        return err('Gagal menghapus data ujian siswa: ' . client_error_message($e->getMessage()));
    }
}

/** Riwayat pelanggaran seorang siswa pada satu ujian. */
function getStudentViolations($username, $token, $id_ujian, $username_siswa): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    $targetExamId = trim(tostr($id_ujian));
    $targetUser = strtolower(trim(tostr($username_siswa)));
    if ($targetExamId === '' || $targetUser === '') {
        return err('Data ujian atau siswa tidak valid.');
    }

    try {
        // Total pelanggaran = penjumlahan seluruh percobaan (maksimum per percobaan)
        $recordedViolations = 0;
        $perAttempt = [];
        $rows = db_all(
            'SELECT percobaan_ke, jumlah_pelanggaran FROM results
             WHERE id_ujian = ? AND username_siswa = ?',
            [$targetExamId, $targetUser]
        );
        $attemptViolMap = [];
        foreach ($rows as $row) {
            $atNo = normalize_exam_attempts($row['percobaan_ke']);
            $atViol = (int) $row['jumlah_pelanggaran'];
            if (!isset($attemptViolMap[$atNo]) || $atViol > $attemptViolMap[$atNo]) {
                $attemptViolMap[$atNo] = $atViol;
            }
        }
        ksort($attemptViolMap);
        foreach ($attemptViolMap as $atNo => $atViol) {
            $recordedViolations += $atViol;
            $perAttempt[] = ['percobaan_ke' => (int) $atNo, 'jumlah_pelanggaran' => (int) $atViol];
        }

        // Log pelanggaran terkait ujian ini, difilter di SQL (bukan memindai
        // seluruh log pengguna di PHP).
        $logsList = [];
        $logRows = db_all(
            "SELECT `timestamp`, username, jenis_kejadian, keterangan
             FROM logs
             WHERE username = ?
               AND keterangan LIKE ?
               AND (jenis_kejadian LIKE '%violation%'
                    OR jenis_kejadian LIKE '%tab%'
                    OR jenis_kejadian LIKE '%fullscreen%'
                    OR keterangan LIKE '%pelanggaran%'
                    OR jenis_kejadian LIKE '%auto submit%')
             ORDER BY id ASC",
            [$targetUser, '%' . $targetExamId . '%']
        );
        foreach ($logRows as $row) {
            $logsList[] = [
                'timestamp'  => dt_display(tostr($row['timestamp'])),
                'jenis'      => trim(tostr($row['jenis_kejadian'])),
                'keterangan' => trim(tostr($row['keterangan'])),
            ];
        }

        return [
            'success'         => true,
            'totalRecorded'   => $recordedViolations,
            'perAttempt'      => $perAttempt,
            'violations'      => $logsList,
        ];
    } catch (Throwable $e) {
        return err('Gagal mengambil log pelanggaran: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Hitung ulang nilai seluruh baris hasil ujian memakai kunci jawaban terbaru.
 */
function regradeExamResults($username, $token, $id_ujian): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $targetExamId = trim(tostr($id_ujian));
    if ($targetExamId === '') {
        return err('ID ujian tidak valid.');
    }

    try {
        $callerRole = current_role(tostr($username));
        if ($callerRole !== 'admin' && $callerRole !== 'guru') {
            return err('Hanya Admin atau Guru yang dapat menilai ulang hasil ujian.');
        }

        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }
        if ($callerRole === 'guru' && !can_manage_exam($examRow, tostr($username))) {
            return err('Anda tidak memiliki hak akses untuk menilai ulang ujian ini.');
        }
        $examName = tostr($examRow['nama_ujian']) !== '' ? tostr($examRow['nama_ujian']) : $targetExamId;

        $keys = exam_key_map($targetExamId);
        if (count($keys['order']) === 0) {
            return err('Ujian ini belum memiliki butir soal sehingga tidak dapat dinilai ulang.');
        }

        $evaluated = 0;
        $changed = 0;
        $skipped = 0;
        $affectedStudents = [];

        $rows = db_all('SELECT * FROM results WHERE id_ujian = ?', [$targetExamId]);
        foreach ($rows as $row) {
            $rowStatus = strtoupper(trim(tostr($row['status'])));
            if ($rowStatus !== 'SUBMITTED') {
                $skipped++;
                continue;
            }

            $answers = parse_answer_detail($row['detail_jawaban']);

            // Paket soal acak siswa (kosong = tanpa batas jumlah soal tampil)
            $selectedSet = null;
            $selRaw = tostr($row['soal_terpilih']);
            if ($selRaw !== '') {
                $tmp = [];
                foreach (explode(',', $selRaw) as $sidRaw) {
                    $sid = trim($sidRaw);
                    if ($sid !== '') {
                        $tmp[$sid] = true;
                    }
                }
                if (count($tmp) > 0) {
                    $selectedSet = $tmp;
                }
            }

            $nilaiBaru = compute_final_score($answers, $keys['map'], $keys['order'], $selectedSet);
            $nilaiLama = (float) $row['nilai_akhir'];
            $evaluated++;
            $affectedStudents[strtolower(trim(tostr($row['username_siswa'])))] = true;

            if (abs($nilaiBaru - $nilaiLama) > 0.049) {
                db_exec('UPDATE results SET nilai_akhir = ? WHERE id_hasil = ?', [$nilaiBaru, tostr($row['id_hasil'])]);
                $changed++;
            }
        }

        $studentCount = count($affectedStudents);
        log_activity(
            tostr($username),
            'Nilai Ulang',
            'Ujian: ' . $targetExamId . ' (' . $examName . ') | ' . $changed . ' nilai diperbarui dari '
            . $evaluated . ' hasil (' . $studentCount . ' siswa)'
        );

        $msg = $changed > 0
            ? 'Nilai ulang selesai: ' . $changed . ' hasil diperbarui dari ' . $evaluated . ' hasil milik ' . $studentCount . ' siswa.'
            : 'Nilai ulang selesai: tidak ada nilai yang berubah (' . $evaluated . ' hasil diperiksa, ' . $studentCount . ' siswa).';
        if ($skipped > 0) {
            $msg .= ' ' . $skipped . ' baris yang belum dikumpulkan dilewati.';
        }

        return [
            'success'    => true,
            'message'    => $msg,
            'exam_id'    => $targetExamId,
            'exam_name'  => $examName,
            'evaluated'  => $evaluated,
            'changed'    => $changed,
            'skipped'    => $skipped,
            'students'   => $studentCount,
        ];
    } catch (Throwable $e) {
        return err('Gagal menilai ulang: ' . client_error_message($e->getMessage()));
    }
}

<?php
/**
 * Modul Monitor Ujian: pemantauan langsung (live) pengerjaan siswa.
 *
 * Halaman ini menggantikan peran panel "buka blokir" yang dulu menempel pada
 * halaman Rekap Nilai. Rekap kini hanya berisi siswa yang SUDAH selesai,
 * sedangkan seluruh status (belum mulai, mengerjakan, terblokir, selesai)
 * dipantau di sini.
 */
declare(strict_types=1);

/** Status monitor baku untuk satu baris peserta. */
function monitor_status_label(string $status): string
{
    $s = strtoupper(trim($status));
    if ($s === 'SUBMITTED') {
        return 'SELESAI';
    }
    if ($s === 'TERBLOKIR') {
        return 'TERBLOKIR';
    }
    // Status apa pun yang belum final (mis. DRAFT) dianggap sedang mengerjakan.
    return $s === '' ? 'BELUM' : 'MENGERJAKAN';
}

/**
 * Apakah seorang siswa termasuk peserta ujian (target kelas/sesi/peserta khusus).
 *
 * Logika ini sama dengan penyaringan pada getStudentAvailableExams(), dipakai
 * ulang di sini agar halaman monitor bisa menampilkan siswa yang belum mulai.
 */
function exam_includes_student(array $examRow, string $kelas, string $sesi, string $username): bool
{
    $tipePeserta = tostr($examRow['tipe_peserta'] ?? '') !== '' ? strtolower(tostr($examRow['tipe_peserta'])) : 'general';
    $userLower = strtolower(trim($username));

    if ($tipePeserta === 'khusus') {
        return in_array($userLower, array_map('strtolower', split_list(tostr($examRow['peserta_khusus'] ?? ''))), true);
    }

    $classesRaw = trim(tostr($examRow['kelas'] ?? ''));
    if ($classesRaw !== '' && !in_array(strtolower($classesRaw), ['semua', 'all', 'semua kelas', '-'], true)) {
        $match = false;
        foreach (array_map('strtolower', split_list($classesRaw)) as $ac) {
            if ($ac === '') {
                continue;
            }
            if ($ac === strtolower($kelas) || in_array($ac, ['semua', 'all', 'semua kelas'], true)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            return false;
        }
    }

    $sessionsRaw = trim(tostr($examRow['sesi'] ?? ''));
    if ($sessionsRaw !== '' && !in_array(strtolower($sessionsRaw), ['semua', 'all', 'semua sesi', '-'], true)) {
        $match = false;
        foreach (array_map('strtolower', split_list($sessionsRaw)) as $as) {
            if ($as === '') {
                continue;
            }
            if ($as === strtolower($sesi) || in_array($as, ['semua', 'all', 'semua sesi'], true)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            return false;
        }
    }

    return true;
}

/** Hitung jumlah butir yang benar-benar sudah dijawab dari detail_jawaban. */
function monitor_count_answered(array $answers, ?array $selectedSet = null): int
{
    $count = 0;
    foreach ($answers as $soalId => $jawaban) {
        $sid = trim((string) $soalId);
        if ($sid === '') {
            continue;
        }
        if ($selectedSet !== null && !isset($selectedSet[$sid])) {
            continue;
        }
        if (trim(strtoupper(tostr($jawaban))) !== '') {
            $count++;
        }
    }
    return $count;
}

/**
 * Data monitor ujian (live).
 *
 * @param string $id_ujian kosong = semua ujian yang boleh dipantau pemanggil
 */
function getExamMonitor($username, $token, $id_ujian = ''): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid. Silakan login kembali.');
    }
    $role = current_role(tostr($username));
    if (!in_array($role, ['admin', 'guru'], true)) {
        return err('Akses ditolak. Hanya Admin atau Guru yang dapat memantau ujian.');
    }

    try {
        $targetExamId = trim(tostr($id_ujian));

        // ---- Daftar ujian yang boleh dipantau --------------------------------
        $examRows = [];
        if ($targetExamId !== '') {
            $row = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
            if (!$row) {
                return err('Ujian tidak ditemukan.');
            }
            if ($role === 'guru' && !can_manage_exam($row, tostr($username))) {
                return err('Anda tidak memiliki hak akses untuk memantau ujian ini.');
            }
            $examRows[$targetExamId] = $row;
        } else {
            foreach (db_all('SELECT * FROM exams ORDER BY waktu_mulai ASC') as $row) {
                $eid = tostr($row['id_ujian']);
                if ($eid === '') {
                    continue;
                }
                if ($role === 'guru' && !can_manage_exam($row, tostr($username))) {
                    continue;
                }
                $examRows[$eid] = $row;
            }
        }
        if (count($examRows) === 0) {
            return [
                'success'     => true,
                'rows'        => [],
                'summary'     => ['total' => 0, 'mengerjakan' => 0, 'terblokir' => 0, 'selesai' => 0, 'belum' => 0],
                'server_time' => now_dt(),
            ];
        }

        // ---- Data pendukung --------------------------------------------------
        $userMap = [];
        foreach (db_all("SELECT username, nama_lengkap, kelas, sesi FROM users WHERE LOWER(role) = 'siswa'") as $u) {
            $key = strtolower(trim(tostr($u['username'])));
            if ($key === '') {
                continue;
            }
            $userMap[$key] = [
                'nama'  => tostr($u['nama_lengkap']) !== '' ? tostr($u['nama_lengkap']) : tostr($u['username']),
                'kelas' => tostr($u['kelas']) !== '' ? tostr($u['kelas']) : '-',
                'sesi'  => tostr($u['sesi']) !== '' ? tostr($u['sesi']) : '-',
            ];
        }

        // Jumlah soal per ujian (untuk batas maksimal progres).
        $questionTotals = [];
        foreach (db_all('SELECT id_ujian, COUNT(*) AS total FROM questions GROUP BY id_ujian') as $q) {
            $questionTotals[tostr($q['id_ujian'])] = (int) $q['total'];
        }

        // Baris hasil: satu ujian atau seluruh ujian yang dipantau.
        $ids = array_keys($examRows);
        if ($targetExamId !== '') {
            $resultRows = db_all('SELECT * FROM results WHERE id_ujian = ? ORDER BY id_hasil ASC', [$targetExamId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $resultRows = db_all("SELECT * FROM results WHERE id_ujian IN ($placeholders) ORDER BY id_hasil ASC", $ids);
        }

        // Kelompokkan: satu baris per (ujian, siswa) memakai percobaan terakhir
        // yang paling relevan (SUBMITTED menang, lalu pelanggaran/progres terbaru).
        $grouped = [];
        foreach ($resultRows as $row) {
            $eid = tostr($row['id_ujian']);
            $uname = trim(tostr($row['username_siswa']));
            if ($eid === '' || $uname === '') {
                continue;
            }
            $key = $eid . '|' . strtolower($uname);
            $attempt = get_result_attempt_number($row);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['row' => $row, 'attempt' => $attempt, 'count' => 1];
                continue;
            }
            $grouped[$key]['count']++;
            $cur = $grouped[$key]['row'];
            $curStatus = strtoupper(trim(tostr($cur['status'])));
            $newStatus = strtoupper(trim(tostr($row['status'])));
            // Percobaan TERBARU selalu menang: siswa yang sedang mengerjakan
            // percobaan ke-2 harus tampil MENGERJAKAN (bukan SELESAI dari
            // percobaan ke-1 yang sudah dikumpulkan). Pada percobaan yang sama,
            // baris SUBMITTED yang diprioritaskan, lalu nilai tertinggi.
            $better = false;
            if ($attempt > $grouped[$key]['attempt']) {
                $better = true;
            } elseif ($attempt === $grouped[$key]['attempt']) {
                $better = ($newStatus === 'SUBMITTED' && $curStatus !== 'SUBMITTED')
                    || ($newStatus === $curStatus
                        && (float) $row['nilai_akhir'] > (float) $cur['nilai_akhir']);
            }
            if ($better) {
                $grouped[$key]['row'] = $row;
                $grouped[$key]['attempt'] = $attempt;
            }
        }

        $rows = [];
        $summary = ['total' => 0, 'mengerjakan' => 0, 'terblokir' => 0, 'selesai' => 0, 'belum' => 0];
        $nowTs = time();

        foreach ($examRows as $eid => $examRow) {
            // Batas maksimal butir yang dikerjakan siswa: pakai "Jumlah Soal Tampil"
            // bila diisi, karena bank soal bisa berisi lebih banyak butir daripada
            // yang disajikan ke siswa (sisanya tidak pernah dijawab).
            $examBankTotal = (int) ($questionTotals[$eid] ?? 0);
            $examTampil = (int) ($examRow['jumlah_soal_tampil'] ?? 0);
            $examTotalSoal = $examTampil > 0 ? min($examTampil, $examBankTotal) : $examBankTotal;

            // Kumpulkan peserta: baris hasil + peserta target yang belum mulai.
            $participants = [];
            foreach ($grouped as $key => $bundle) {
                if (strpos($key, $eid . '|') !== 0) {
                    continue;
                }
                $uname = trim(tostr($bundle['row']['username_siswa']));
                $participants[strtolower($uname)] = $bundle['row'];
            }

            // Siswa target ujian yang belum punya baris hasil (hanya bila satu
            // ujian dipilih, agar daftar "semua ujian" tetap ringkas).
            if ($targetExamId !== '') {
                foreach ($userMap as $unameKey => $u) {
                    if (isset($participants[$unameKey])) {
                        continue;
                    }
                    if (exam_includes_student($examRow, tostr($u['kelas']), tostr($u['sesi']), (string) $unameKey)) {
                        $participants[$unameKey] = null;
                    }
                }
            }

            foreach ($participants as $unameKey => $row) {
                $uInfo = $userMap[$unameKey] ?? [
                    'nama'  => $unameKey !== '' ? $unameKey : '-',
                    'kelas' => '-',
                    'sesi'  => '-',
                ];

                if ($row === null) {
                    $rows[] = [
                        'id_ujian'                   => $eid,
                        'nama_ujian'                 => sanitize_plain_text($examRow['nama_ujian']),
                        'username_siswa'             => $unameKey,
                        'nama_siswa'                 => $uInfo['nama'],
                        'kelas'                      => $uInfo['kelas'],
                        'sesi'                       => $uInfo['sesi'],
                        'status'                     => 'BELUM',
                        'id_hasil'                   => '',
                        'percobaan_ke'               => 0,
                        'jumlah_percobaan_dilakukan' => 0,
                        'answered'                   => 0,
                        'total_soal'                 => $examTotalSoal,
                        'jumlah_pelanggaran'         => 0,
                        'waktu_mulai'                => '',
                        'waktu_submit'               => '',
                        'sisa_detik'                 => 0,
                    ];
                    $summary['belum']++;
                    $summary['total']++;
                    continue;
                }

                $answers = exam_stored_answers($row);
                $selectedSet = null;
                $selectedRaw = tostr($row['soal_terpilih'] ?? '');
                if ($selectedRaw !== '') {
                    $tmp = [];
                    foreach (explode(',', $selectedRaw) as $sidRaw) {
                        $sid = trim($sidRaw);
                        if ($sid !== '') {
                            $tmp[$sid] = true;
                        }
                    }
                    if (count($tmp) > 0) {
                        $selectedSet = $tmp;
                    }
                }

                // Bila siswa sudah punya paket soal sendiri (soal_terpilih), jumlah
                // paket itulah yang benar-benar dikerjakan. Jika belum punya paket,
                // pakai batas "Jumlah Soal Tampil" (atau seluruh bank soal).
                $totalSoal = $selectedSet !== null ? count($selectedSet) : $examTotalSoal;
                $answered = monitor_count_answered($answers, $selectedSet);
                if ($answered > $totalSoal) {
                    $answered = $totalSoal;
                }

                $status = monitor_status_label(tostr($row['status']));
                $sisaDetik = 0;
                if ($status === 'MENGERJAKAN') {
                    $deadline = exam_result_deadline($examRow, $row);
                    $deadlineTs = ($deadline !== null && trim($deadline) !== '') ? strtotime($deadline) : false;
                    if ($deadlineTs !== false) {
                        $sisaDetik = max(0, $deadlineTs - $nowTs);
                    }
                }

                $rows[] = [
                    'id_ujian'                   => $eid,
                    'nama_ujian'                 => sanitize_plain_text($examRow['nama_ujian']),
                    'username_siswa'             => tostr($row['username_siswa']),
                    'nama_siswa'                 => $uInfo['nama'],
                    'kelas'                      => $uInfo['kelas'],
                    'sesi'                       => $uInfo['sesi'],
                    'status'                     => $status,
                    'id_hasil'                   => tostr($row['id_hasil']),
                    'percobaan_ke'               => get_result_attempt_number($row),
                    'jumlah_percobaan_dilakukan' => (int) ($grouped[$eid . '|' . $unameKey]['count'] ?? 1),
                    'answered'                   => $answered,
                    'total_soal'                 => $totalSoal,
                    'jumlah_pelanggaran'         => (int) $row['jumlah_pelanggaran'],
                    'waktu_mulai'                => format_datetime_iso($row['waktu_mulai']),
                    'waktu_submit'               => format_datetime_iso($row['waktu_submit']),
                    'sisa_detik'                 => $sisaDetik,
                ];

                if ($status === 'SELESAI') {
                    $summary['selesai']++;
                } elseif ($status === 'TERBLOKIR') {
                    $summary['terblokir']++;
                } else {
                    $summary['mengerjakan']++;
                }
                $summary['total']++;
            }
        }

        // Urutan: yang butuh perhatian lebih dulu (terblokir → mengerjakan →
        // belum mulai → selesai), lalu nama.
        $order = ['TERBLOKIR' => 0, 'MENGERJAKAN' => 1, 'BELUM' => 2, 'SELESAI' => 3];
        usort($rows, static function ($a, $b) use ($order) {
            $sa = $order[$a['status']] ?? 9;
            $sb = $order[$b['status']] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcasecmp($a['nama_siswa'], $b['nama_siswa']);
        });

        return [
            'success'     => true,
            'rows'        => $rows,
            'summary'     => $summary,
            'server_time' => now_dt(),
        ];
    } catch (Throwable $e) {
        error_log('[CBT MONITOR] getExamMonitor: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return err('Gagal memuat monitor ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Paksa selesai (force finish) satu peserta: jawaban yang tersimpan langsung
 * dinilai dan status diubah menjadi SUBMITTED, tanpa menunggu siswa menekan
 * tombol kirim. Dipakai pengawas untuk menutup ujian yang tertinggal.
 */
function forceFinishExam($username, $token, $id_ujian, $username_siswa): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid. Silakan login kembali.');
    }
    $role = current_role(tostr($username));
    if (!in_array($role, ['admin', 'guru'], true)) {
        return err('Akses ditolak. Hanya Admin atau Guru yang dapat menyelesaikan ujian siswa.');
    }

    $targetExamId = trim(tostr($id_ujian));
    $targetStudent = strtolower(trim(tostr($username_siswa)));
    if ($targetExamId === '' || $targetStudent === '') {
        return err('ID Ujian dan Username Siswa harus diisi.');
    }

    try {
        $examRow = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$targetExamId]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }
        if ($role === 'guru' && !can_manage_exam($examRow, tostr($username))) {
            return err('Anda tidak memiliki hak akses pada ujian ini.');
        }

        $record = find_result_record($targetExamId, $targetStudent);
        if ($record === null) {
            return err('Siswa belum memulai ujian ini.');
        }
        $row = $record['row'];
        if (strtoupper(trim(tostr($row['status']))) === 'SUBMITTED') {
            return err('Ujian siswa ini sudah berstatus selesai.');
        }

        $answers = exam_stored_answers($row);
        $selectedSet = null;
        $selectedRaw = tostr($row['soal_terpilih'] ?? '');
        if ($selectedRaw !== '') {
            $tmp = [];
            foreach (explode(',', $selectedRaw) as $sidRaw) {
                $sid = trim($sidRaw);
                if ($sid !== '') {
                    $tmp[$sid] = true;
                }
            }
            if (count($tmp) > 0) {
                $selectedSet = $tmp;
            }
        }

        $keyInfo = exam_key_map($targetExamId);
        $nilaiAkhir = compute_final_score($answers, $keyInfo['map'], $keyInfo['order'], $selectedSet);

        db_exec(
            'UPDATE results SET waktu_submit = ?, nilai_akhir = ?, status = ? WHERE id_hasil = ?',
            [now_dt(), $nilaiAkhir, 'SUBMITTED', tostr($row['id_hasil'])]
        );

        log_activity(
            tostr($username),
            'Paksa Selesai',
            'Ujian: ' . $targetExamId . ' | Siswa: ' . $targetStudent . ' | Nilai: ' . $nilaiAkhir
            . ' | Percobaan: ' . get_result_attempt_number($row)
        );

        return [
            'success' => true,
            'message' => 'Ujian siswa ' . $targetStudent . ' dipaksa selesai. Nilai akhir: ' . $nilaiAkhir . '.',
            'nilai_akhir' => $nilaiAkhir,
        ];
    } catch (Throwable $e) {
        return err('Gagal menyelesaikan ujian siswa: ' . client_error_message($e->getMessage()));
    }
}

<?php
/**
 * Modul guru/admin: pengelolaan ujian (padanan modul 6 pada Code.gs).
 */
declare(strict_types=1);

/** Susun objek ujian dari baris DB ke bentuk yang dipakai UI. */
function exam_to_client(array $row, int $jumlahSoal = 0, int $jumlahPeserta = 0): array
{
    $owner = trim(tostr($row['id_guru']));
    // Batas & tindakan pelanggaran kini GLOBAL (menu Pengaturan Admin), bukan per-ujian.
    $policy = violation_policy();
    return [
        'id_ujian'             => tostr($row['id_ujian']),
        'nama_ujian'           => sanitize_plain_text($row['nama_ujian']),
        'durasi_menit'         => (int) $row['durasi_menit'] > 0 ? (int) $row['durasi_menit'] : 60,
        'waktu_mulai'          => format_datetime_iso($row['waktu_mulai']),
        'waktu_selesai'        => format_datetime_iso($row['waktu_selesai']),
        'acak_soal'            => tostr($row['acak_soal']) !== '' ? tostr($row['acak_soal']) : 'Ya',
        'acak_opsi'            => tostr($row['acak_opsi']) !== '' ? tostr($row['acak_opsi']) : 'Ya',
        'token_ujian'          => tostr($row['token_ujian']),
        'kelas'                => tostr($row['kelas']),
        'sesi'                 => tostr($row['sesi']),
        'tipe_peserta'         => tostr($row['tipe_peserta']) !== '' ? strtolower(tostr($row['tipe_peserta'])) : 'general',
        'peserta_khusus'       => tostr($row['peserta_khusus']),
        'id_guru'              => $owner !== '' ? $owner : '-',
        'kolaborator'          => tostr($row['kolaborator']),
        'tingkat'              => tostr($row['tingkat']),
        'jumlah_soal_tampil'   => (int) $row['jumlah_soal_tampil'],
        'jumlah_percobaan'     => normalize_exam_attempts($row['jumlah_percobaan']),
        'tampilkan_pembahasan' => strtolower(tostr($row['tampilkan_pembahasan'])) === 'ya' ? 'Ya' : 'Tidak',
        'max_violations'       => $policy['max'],
        'tindakan_pelanggaran' => $policy['action'],
        'jumlah_soal'          => $jumlahSoal,
        'jumlah_peserta'       => $jumlahPeserta,
    ];
}

/** Hitung jumlah soal per ujian: [id_ujian => jumlah]. */
function question_counts(): array
{
    $counts = [];
    foreach (db_all('SELECT id_ujian, COUNT(*) AS total FROM questions GROUP BY id_ujian') as $row) {
        $counts[tostr($row['id_ujian'])] = (int) $row['total'];
    }
    return $counts;
}

/** Hitung peserta SUBMITTED unik per ujian: [id_ujian => jumlah]. */
function submitted_counts(): array
{
    $counts = [];
    $rows = db_all(
        "SELECT id_ujian, COUNT(DISTINCT LOWER(TRIM(username_siswa))) AS total
         FROM results WHERE status = 'SUBMITTED' GROUP BY id_ujian"
    );
    foreach ($rows as $row) {
        $counts[tostr($row['id_ujian'])] = (int) $row['total'];
    }
    return $counts;
}

/**
 * Daftar ujian yang boleh dilihat pemanggil.
 * Admin melihat semua; guru melihat ujian miliknya + yang ia jadi kolaborator.
 */
function getTeacherExams($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    try {
        $callerRole = current_role(tostr($username));
        $userLower = strtolower(trim(tostr($username)));

        $qCounts = question_counts();
        $submitCounts = submitted_counts();

        $rows = db_all('SELECT * FROM exams ORDER BY created_at ASC');
        $list = [];
        foreach ($rows as $row) {
            $rawId = tostr($row['id_ujian']);
            if ($rawId === '') {
                continue;
            }
            $owner = trim(tostr($row['id_guru']));
            $kolabList = array_map('strtolower', split_list(tostr($row['kolaborator'])));

            if ($callerRole === 'guru') {
                $isOwner = ($owner === '') || (strtolower($owner) === $userLower);
                $isKolab = in_array($userLower, $kolabList, true);
                if (!$isOwner && !$isKolab) {
                    continue;
                }
            }

            $exam = exam_to_client($row, $qCounts[$rawId] ?? 0, $submitCounts[$rawId] ?? 0);
            $exam['id_guru'] = $owner !== '' ? $owner : tostr($username);
            $list[] = $exam;
        }

        $catalog = catalog_lists(false);

        return array_merge(
            ['success' => true, 'exams' => $list],
            $catalog
        );
    } catch (Throwable $e) {
        return err('Gagal memuat daftar ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Buat / perbarui ujian (padanan saveExam()).
 */
function saveExam($username, $token, $examData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    $examData = is_array($examData) ? $examData : [];
    try {
        $isEdit = tostr($examData['id_ujian'] ?? '') !== '';

        $namaVal = limit_text(sanitize_plain_text($examData['nama_ujian'] ?? ''), 200);
        if ($namaVal === '') {
            return err('Nama ujian wajib diisi.');
        }
        $durasiVal = (int) ($examData['durasi_menit'] ?? 0);
        if ($durasiVal <= 0) {
            $durasiVal = 60;
        }
        if ($durasiVal > 1440) {
            return err('Durasi ujian maksimal 1440 menit (24 jam).');
        }
        $mulaiVal = to_db_datetime($examData['waktu_mulai'] ?? '');
        $selesaiVal = to_db_datetime($examData['waktu_selesai'] ?? '');
        if ($mulaiVal === null || $selesaiVal === null) {
            return err('Waktu mulai dan waktu selesai ujian wajib diisi dengan format yang valid.');
        }
        if (strtotime($selesaiVal) <= strtotime($mulaiVal)) {
            return err('Waktu selesai harus lebih besar daripada waktu mulai.');
        }
        $acakSoalVal = strtolower(trim(tostr($examData['acak_soal'] ?? ''))) === 'tidak' ? 'Tidak' : 'Ya';
        $acakOpsiVal = strtolower(trim(tostr($examData['acak_opsi'] ?? ''))) === 'tidak' ? 'Tidak' : 'Ya';
        $tokenVal = limit_text(strtoupper(sanitize_plain_text($examData['token_ujian'] ?? '')), 50);
        $tipePesertaVal = strtolower(tostr($examData['tipe_peserta'] ?? '')) === 'khusus' ? 'khusus' : 'general';
        $pesertaKhususVal = '';

        $kelasRaw = $examData['kelas'] ?? '';
        $kelasVal = is_array($kelasRaw)
            ? implode(', ', array_map('tostr', $kelasRaw))
            : sanitize_plain_text($kelasRaw);

        $sesiRaw = $examData['sesi'] ?? '';
        $sesiVal = is_array($sesiRaw)
            ? implode(', ', array_map('tostr', $sesiRaw))
            : limit_text(sanitize_plain_text($sesiRaw), 200);

        $tingkatVal = limit_text(sanitize_plain_text($examData['tingkat'] ?? ''), 50);

        // Jumlah soal yang ditampilkan (0 = semua)
        $jumlahSoalTampilVal = 0;
        $jumlahTampilRaw = $examData['jumlah_soal_tampil'] ?? '';
        if ($jumlahTampilRaw !== '' && $jumlahTampilRaw !== null) {
            if (!is_numeric($jumlahTampilRaw)) {
                return err('Jumlah soal tampil harus berupa angka bulat positif (0 = tampilkan semua soal).');
            }
            $jumlahSoalTampilVal = max(0, (int) $jumlahTampilRaw);
        }

        $tampilkanPembahasanVal = strtolower(trim(tostr($examData['tampilkan_pembahasan'] ?? ''))) === 'ya' ? 'Ya' : 'Tidak';
        $jumlahPercobaanVal = normalize_exam_attempts($examData['jumlah_percobaan'] ?? 1);
        // Kolom exams.max_violations / exams.tindakan_pelanggaran dipertahankan agar data
        // lama tetap terbaca, namun nilainya selalu mengikuti pengaturan GLOBAL.
        $policyGlobal = violation_policy();
        $maxViolationsVal = $policyGlobal['max'];
        $tindakanPelanggaranVal = $policyGlobal['action'];

        $kolabRaw = $examData['kolaborator'] ?? '';
        $kolaboratorVal = is_array($kolabRaw)
            ? implode(', ', array_filter(array_map('trim', array_map('tostr', $kolabRaw)), static fn ($s) => $s !== ''))
            : trim(tostr($kolabRaw));

        if ($tipePesertaVal === 'khusus') {
            $pkRaw = $examData['peserta_khusus'] ?? '';
            $pesertaKhususVal = is_array($pkRaw)
                ? implode(', ', array_filter(array_map('trim', array_map('tostr', $pkRaw)), static fn ($s) => $s !== ''))
                : trim(tostr($pkRaw));
            if ($pesertaKhususVal === '') {
                return err('Untuk ujian target peserta khusus, minimal satu siswa harus dipilih.');
            }
            if ($sesiVal === '') {
                $sesiVal = 'Semua Sesi';
            }
            if ($kelasVal === '') {
                $kelasVal = 'Peserta Khusus';
            }
        } elseif ($sesiVal === '') {
            return err('Target sesi peserta wajib dipilih atau ditentukan.');
        }

        $callerRole = current_role(tostr($username));
        $guruVal = tostr($username);
        if ($callerRole === 'admin' && tostr($examData['id_guru'] ?? '') !== '') {
            $guruVal = trim(tostr($examData['id_guru']));
        }

        if ($isEdit) {
            $idUjian = tostr($examData['id_ujian']);
            $existing = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$idUjian]);
            if (!$existing) {
                return err('Data ujian tidak ditemukan.');
            }

            $existingGuru = trim(tostr($existing['id_guru']));
            $existingKolabList = array_map('strtolower', split_list(tostr($existing['kolaborator'])));

            if ($callerRole === 'guru') {
                $userLower = strtolower(trim(tostr($username)));
                $isOwner = ($existingGuru === '') || (strtolower($existingGuru) === $userLower);
                $isKolab = in_array($userLower, $existingKolabList, true);
                if (!$isOwner && !$isKolab) {
                    return err('Anda tidak memiliki hak akses untuk mengedit ujian ini.');
                }
                if (!$isOwner && $isKolab) {
                    $guruVal = $existingGuru;
                }
            }

            db_exec(
                'UPDATE exams SET nama_ujian = ?, durasi_menit = ?, waktu_mulai = ?, waktu_selesai = ?,
                    acak_soal = ?, acak_opsi = ?, token_ujian = ?, kelas = ?, sesi = ?, tipe_peserta = ?,
                    peserta_khusus = ?, id_guru = ?, kolaborator = ?, tingkat = ?, jumlah_soal_tampil = ?,
                    tampilkan_pembahasan = ?, jumlah_percobaan = ?, max_violations = ?, tindakan_pelanggaran = ?
                 WHERE id_ujian = ?',
                [
                    $namaVal, $durasiVal, $mulaiVal, $selesaiVal, $acakSoalVal, $acakOpsiVal, $tokenVal,
                    $kelasVal, $sesiVal, $tipePesertaVal, $pesertaKhususVal, $guruVal, $kolaboratorVal,
                    $tingkatVal, $jumlahSoalTampilVal, $tampilkanPembahasanVal, $jumlahPercobaanVal,
                    $maxViolationsVal, $tindakanPelanggaranVal, $idUjian,
                ]
            );

            $targetLog = $tipePesertaVal === 'khusus'
                ? ' (Target: Peserta Khusus [' . $pesertaKhususVal . '])'
                : ($kelasVal !== '' ? ' (Kelas: ' . $kelasVal . ')' : '') . ($sesiVal !== '' ? ' (Sesi: ' . $sesiVal . ')' : '');
            log_activity(
                tostr($username),
                'Update Exam',
                'Mengupdate ujian: ' . $namaVal
                . ($tokenVal !== '' ? ' (Token: ' . $tokenVal . ')' : '')
                . ' (Pengampu: ' . $guruVal . ')'
                . ($kolaboratorVal !== '' ? ' (Kolaborator: ' . $kolaboratorVal . ')' : '')
                . $targetLog
                . ($jumlahSoalTampilVal > 0 ? ' (Jumlah Soal Tampil: ' . $jumlahSoalTampilVal . ' acak/soal)' : '')
                . ' (Jumlah Percobaan: ' . $jumlahPercobaanVal . ')'
                . ' (Maks Pelanggaran: ' . $maxViolationsVal . 'x / ' . $tindakanPelanggaranVal . ')'
            );
            return ['success' => true, 'message' => 'Ujian berhasil diperbarui.'];
        }

        $newExamId = new_id('EXM');
        db_exec(
            'INSERT INTO exams (id_ujian, nama_ujian, durasi_menit, waktu_mulai, waktu_selesai, acak_soal, acak_opsi,
                token_ujian, kelas, sesi, tipe_peserta, peserta_khusus, id_guru, kolaborator, tingkat,
                jumlah_soal_tampil, tampilkan_pembahasan, jumlah_percobaan, max_violations, tindakan_pelanggaran)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $newExamId, $namaVal, $durasiVal, $mulaiVal, $selesaiVal, $acakSoalVal, $acakOpsiVal, $tokenVal,
                $kelasVal, $sesiVal, $tipePesertaVal, $pesertaKhususVal, $guruVal, $kolaboratorVal, $tingkatVal,
                $jumlahSoalTampilVal, $tampilkanPembahasanVal, $jumlahPercobaanVal, $maxViolationsVal,
                $tindakanPelanggaranVal,
            ]
        );

        $targetLog2 = $tipePesertaVal === 'khusus'
            ? ' (Target: Peserta Khusus [' . $pesertaKhususVal . '])'
            : ($kelasVal !== '' ? ' (Kelas: ' . $kelasVal . ')' : '') . ($sesiVal !== '' ? ' (Sesi: ' . $sesiVal . ')' : '');
        log_activity(
            tostr($username),
            'Create Exam',
            'Membuat ujian baru: ' . $namaVal
            . ($tokenVal !== '' ? ' (Token: ' . $tokenVal . ')' : '')
            . ' (Pengampu: ' . $guruVal . ')'
            . ($kolaboratorVal !== '' ? ' (Kolaborator: ' . $kolaboratorVal . ')' : '')
            . $targetLog2
            . ($jumlahSoalTampilVal > 0 ? ' (Jumlah Soal Tampil: ' . $jumlahSoalTampilVal . ' acak/soal)' : '')
            . ' (Jumlah Percobaan: ' . $jumlahPercobaanVal . ')'
            . ' (Maks Pelanggaran: ' . $maxViolationsVal . 'x / ' . $tindakanPelanggaranVal . ')'
        );
        return ['success' => true, 'message' => 'Ujian berhasil dibuat.', 'id_ujian' => $newExamId];
    } catch (Throwable $e) {
        return err('Gagal menyimpan ujian: ' . client_error_message($e->getMessage()));
    }
}

/**
 * Hapus ujian beserta soal & hasil pengerjaan (padanan deleteExam()).
 */
function deleteExam($username, $token, $id_ujian): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    try {
        $idUjian = tostr($id_ujian);
        $examRow = db_one('SELECT id_ujian FROM exams WHERE id_ujian = ?', [$idUjian]);
        if (!$examRow) {
            return err('Ujian tidak ditemukan.');
        }

        // Kumpulkan gambar soal sebelum baris dihapus agar berkas media ikut dibersihkan.
        $urlsGambar = [];
        if (function_exists('media_delete_if_unused')) {
            foreach (db_all("SELECT url_gambar FROM questions WHERE id_ujian = ? AND url_gambar LIKE '%media.php?id=%'", [$idUjian]) as $rowQ) {
                $urlsGambar[] = tostr($rowQ['url_gambar']);
            }
        }

        db()->beginTransaction();
        db_exec('DELETE FROM exams WHERE id_ujian = ?', [$idUjian]);
        db_exec('DELETE FROM questions WHERE id_ujian = ?', [$idUjian]);
        db_exec('DELETE FROM teks_ujian WHERE id_ujian = ?', [$idUjian]);
        $deletedResultsCount = db_exec('DELETE FROM results WHERE id_ujian = ?', [$idUjian]);
        db()->commit();

        foreach ($urlsGambar as $urlGambarSoal) {
            media_delete_if_unused($urlGambarSoal);
        }

        log_activity(
            tostr($username),
            'Delete Exam',
            'Menghapus ujian ID: ' . $idUjian . ' beserta butir soal dan ' . $deletedResultsCount . ' data pengerjaan siswa terkait.'
        );
        return ['success' => true, 'message' => 'Ujian beserta bank soal dan seluruh data pengerjaan siswa berhasil dihapus.'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return err('Gagal menghapus ujian: ' . client_error_message($e->getMessage()));
    }
}

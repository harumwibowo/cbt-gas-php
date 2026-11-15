<?php
/**
 * Bank soal, import soal, serta teks/konteks/stimulan ujian
 * (padanan modul 8 pada Code.gs).
 */
declare(strict_types=1);

/** Huruf opsi yang didukung (A..J). */
function option_letters(): array
{
    return ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
}

/** Peta teks/konteks milik satu ujian: [id_teks => ['nama_teks','isi_teks']]. */
function exam_text_map(string $idUjian): array
{
    $map = [];
    if ($idUjian === '') {
        return $map;
    }
    foreach (db_all('SELECT id_teks, nama_teks, isi_teks FROM teks_ujian WHERE id_ujian = ?', [$idUjian]) as $row) {
        $idTxt = trim(tostr($row['id_teks']));
        if ($idTxt === '') {
            continue;
        }
        $map[$idTxt] = [
            'nama_teks' => tostr($row['nama_teks']),
            'isi_teks'  => tostr($row['isi_teks']),
        ];
    }
    return $map;
}

/** Daftar soal satu ujian lengkap dengan teks konteksnya. */
function getExamQuestions($username, $token, $id_ujian): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    try {
        $idUjian = tostr($id_ujian);
        $textMap = exam_text_map($idUjian);
        $rows = db_all('SELECT * FROM questions WHERE id_ujian = ? ORDER BY urutan ASC, id_soal ASC', [$idUjian]);

        $questions = [];
        foreach ($rows as $row) {
            $qIdTeks = trim(tostr($row['id_teks']));
            $qTeksObj = $textMap[$qIdTeks] ?? null;
            $item = [
                'id_soal'       => tostr($row['id_soal']),
                'id_ujian'      => tostr($row['id_ujian']),
                'teks_soal'     => tostr($row['teks_soal']),
                'url_gambar'    => tostr($row['url_gambar']),
                'kunci_jawaban' => strtoupper(tostr($row['kunci_jawaban'])),
                'bobot'         => (int) $row['bobot'] > 0 ? (int) $row['bobot'] : 1,
                'id_teks'       => $qIdTeks,
                'pembahasan'    => tostr($row['pembahasan']),
                'nama_teks'     => $qTeksObj ? $qTeksObj['nama_teks'] : '',
                'teks_konteks'  => $qTeksObj ? $qTeksObj['isi_teks'] : '',
            ];
            foreach (option_letters() as $l) {
                $item['opsi_' . $l] = tostr($row['opsi_' . $l] ?? '');
            }
            $questions[] = $item;
        }

        return ['success' => true, 'questions' => $questions];
    } catch (Throwable $e) {
        return err('Gagal memuat soal: ' . $e->getMessage());
    }
}

/** Urutan berikutnya untuk soal baru pada satu ujian. */
function next_question_order(string $idUjian): int
{
    return (int) db_val('SELECT COALESCE(MAX(urutan), 0) + 1 FROM questions WHERE id_ujian = ?', [$idUjian], 1);
}

/** Tambah / perbarui satu butir soal. */
function saveQuestion($username, $token, $qData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    $qData = is_array($qData) ? $qData : [];
    try {
        $isEdit = tostr($qData['id_soal'] ?? '') !== '';
        $kunci = strtoupper(trim(tostr($qData['kunci_jawaban'] ?? '')));
        if ($kunci === '') {
            $kunci = 'A';
        }
        $bobot = (int) ($qData['bobot'] ?? 0);
        if ($bobot <= 0) {
            $bobot = 1;
        }

        $opsi = [];
        foreach (option_letters() as $l) {
            $opsi[$l] = tostr($qData['opsi_' . $l] ?? '');
        }
        $idTeks = tostr($qData['id_teks'] ?? '');
        $urlGambar = tostr($qData['url_gambar'] ?? '');
        $teksSoal = tostr($qData['teks_soal'] ?? '');
        $pembahasan = tostr($qData['pembahasan'] ?? '');

        if ($isEdit) {
            $idSoal = tostr($qData['id_soal']);
            $exists = db_one('SELECT id_soal, url_gambar FROM questions WHERE id_soal = ?', [$idSoal]);
            if (!$exists) {
                return err('Soal tidak ditemukan.');
            }
            db_exec(
                'UPDATE questions SET teks_soal = ?, url_gambar = ?, id_teks = ?, opsi_a = ?, opsi_b = ?, opsi_c = ?,
                    opsi_d = ?, opsi_e = ?, opsi_f = ?, opsi_g = ?, opsi_h = ?, opsi_i = ?, opsi_j = ?,
                    kunci_jawaban = ?, bobot = ?, pembahasan = ?
                 WHERE id_soal = ?',
                [
                    $teksSoal, $urlGambar, $idTeks,
                    $opsi['a'], $opsi['b'], $opsi['c'], $opsi['d'], $opsi['e'],
                    $opsi['f'], $opsi['g'], $opsi['h'], $opsi['i'], $opsi['j'],
                    $kunci, $bobot, $pembahasan, $idSoal,
                ]
            );
            // Ganti gambar: hapus berkas lama dari tabel media bila tidak dipakai lagi.
            $urlLama = tostr($exists['url_gambar']);
            if ($urlLama !== $urlGambar && $urlLama !== '' && function_exists('media_delete_if_unused')) {
                media_delete_if_unused($urlLama);
            }
            return ['success' => true, 'message' => 'Soal berhasil diperbarui.'];
        }

        $idUjian = tostr($qData['id_ujian'] ?? '');
        if ($idUjian === '') {
            return err('ID Ujian tidak valid.');
        }
        $newQId = new_id('QST');
        db_exec(
            'INSERT INTO questions (id_soal, id_ujian, teks_soal, url_gambar, id_teks,
                opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, opsi_f, opsi_g, opsi_h, opsi_i, opsi_j,
                kunci_jawaban, bobot, pembahasan, urutan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $newQId, $idUjian, $teksSoal, $urlGambar, $idTeks,
                $opsi['a'], $opsi['b'], $opsi['c'], $opsi['d'], $opsi['e'],
                $opsi['f'], $opsi['g'], $opsi['h'], $opsi['i'], $opsi['j'],
                $kunci, $bobot, $pembahasan, next_question_order($idUjian),
            ]
        );
        return ['success' => true, 'message' => 'Soal baru berhasil ditambahkan.', 'id_soal' => $newQId];
    } catch (Throwable $e) {
        return err($e->getMessage());
    }
}

/** Hapus satu butir soal. */
function deleteQuestion($username, $token, $id_soal): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }

    try {
        $idSoal = tostr($id_soal);
        $existing = db_one('SELECT url_gambar FROM questions WHERE id_soal = ?', [$idSoal]);
        $deleted = db_exec('DELETE FROM questions WHERE id_soal = ?', [$idSoal]);
        if ($deleted === 0) {
            return err('Soal tidak ditemukan.');
        }
        // Bersihkan berkas gambar yang hanya dipakai soal ini.
        if ($existing && function_exists('media_delete_if_unused')) {
            media_delete_if_unused($existing['url_gambar']);
        }
        return ['success' => true, 'message' => 'Soal berhasil dihapus.'];
    } catch (Throwable $e) {
        return err($e->getMessage());
    }
}

/** Daftar teks/konteks/stimulan sebuah ujian. */
function getExamTexts($username, $token, $id_ujian): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }
    $idUjian = tostr($id_ujian);
    if ($idUjian === '') {
        return err('ID Ujian tidak valid.');
    }

    try {
        $texts = [];
        $rows = db_all('SELECT id_teks, id_ujian, nama_teks, isi_teks FROM teks_ujian WHERE id_ujian = ? ORDER BY id_teks ASC', [$idUjian]);
        foreach ($rows as $row) {
            $idTxt = trim(tostr($row['id_teks']));
            if ($idTxt === '') {
                continue;
            }
            $texts[] = [
                'id_teks'   => $idTxt,
                'id_ujian'  => $idUjian,
                'nama_teks' => tostr($row['nama_teks']),
                'isi_teks'  => tostr($row['isi_teks']),
            ];
        }
        return ['success' => true, 'texts' => $texts];
    } catch (Throwable $e) {
        return err('Gagal memuat teks/konteks: ' . $e->getMessage());
    }
}

/** Tambah / perbarui teks/konteks/stimulan. */
function saveExamText($username, $token, $textData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }
    $textData = is_array($textData) ? $textData : [];
    $idUjian = trim(tostr($textData['id_ujian'] ?? ''));
    if ($idUjian === '') {
        return err('ID Ujian tidak valid.');
    }

    $nama = trim(tostr($textData['nama_teks'] ?? ''));
    $isi = trim(tostr($textData['isi_teks'] ?? ''));
    if ($nama === '') {
        return err('Nama/Judul teks wajib diisi.');
    }
    if ($isi === '') {
        return err('Teks/Konteks/Stimulan wajib diisi.');
    }

    try {
        $idTeks = trim(tostr($textData['id_teks'] ?? ''));

        if ($idTeks !== '') {
            $exists = db_one('SELECT id_teks FROM teks_ujian WHERE id_teks = ?', [$idTeks]);
            if (!$exists) {
                return err('Teks/Konteks tidak ditemukan.');
            }
            db_exec('UPDATE teks_ujian SET nama_teks = ?, isi_teks = ?, id_ujian = ? WHERE id_teks = ?', [$nama, $isi, $idUjian, $idTeks]);
            log_activity(tostr($username), 'Edit Teks Ujian', 'Memperbarui teks/konteks "' . $nama . '" pada ujian ' . $idUjian);
            return ['success' => true, 'id_teks' => $idTeks, 'message' => 'Teks/Konteks berhasil diperbarui.'];
        }

        $newId = new_id('TXT');
        db_exec('INSERT INTO teks_ujian (id_teks, id_ujian, nama_teks, isi_teks) VALUES (?,?,?,?)', [$newId, $idUjian, $nama, $isi]);
        log_activity(tostr($username), 'Tambah Teks Ujian', 'Menambah teks/konteks "' . $nama . '" pada ujian ' . $idUjian);
        return ['success' => true, 'id_teks' => $newId, 'message' => 'Teks/Konteks berhasil ditambahkan.'];
    } catch (Throwable $e) {
        return err('Gagal menyimpan teks/konteks: ' . $e->getMessage());
    }
}

/** Hapus teks/konteks dan lepaskan dari soal yang memakainya. */
function deleteExamText($username, $token, $id_teks): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }
    $targetId = trim(tostr($id_teks));
    if ($targetId === '') {
        return err('ID teks tidak valid.');
    }

    try {
        $row = db_one('SELECT id_teks, nama_teks FROM teks_ujian WHERE id_teks = ?', [$targetId]);
        if (!$row) {
            return err('Teks/Konteks tidak ditemukan.');
        }
        $namaTeks = tostr($row['nama_teks']);

        db_exec('DELETE FROM teks_ujian WHERE id_teks = ?', [$targetId]);
        $released = db_exec('UPDATE questions SET id_teks = ? WHERE TRIM(id_teks) = ?', ['', $targetId]);

        log_activity(
            tostr($username),
            'Hapus Teks Ujian',
            'Menghapus teks/konteks "' . $namaTeks . '" (' . $released . ' butir soal dilepas dari teks)'
        );
        return [
            'success' => true,
            'message' => 'Teks/Konteks berhasil dihapus.' . ($released > 0 ? ' ' . $released . ' butir soal kini tanpa teks.' : ''),
        ];
    } catch (Throwable $e) {
        return err('Gagal menghapus teks/konteks: ' . $e->getMessage());
    }
}

/** Import massal butir soal dari Excel/CSV. */
function importQuestions($username, $token, $id_ujian, $questionsList): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    $idUjian = trim(tostr($id_ujian));
    if ($idUjian === '') {
        return err('ID Ujian tidak valid.');
    }
    if (!is_array($questionsList) || count($questionsList) === 0) {
        return err('Data soal yang akan diimpor kosong.');
    }

    try {
        $urutan = next_question_order($idUjian);
        $count = 0;

        foreach ($questionsList as $q) {
            if (!is_array($q)) {
                continue;
            }
            $teks = trim(tostr($q['teks_soal'] ?? ($q['soal'] ?? '')));
            if ($teks === '') {
                continue;
            }

            $kunci = strtoupper(trim(tostr($q['kunci_jawaban'] ?? '')));
            if ($kunci === '') {
                $kunci = 'A';
            }
            $bobot = (int) ($q['bobot'] ?? 0);
            if ($bobot <= 0) {
                $bobot = 1;
            }

            db_exec(
                'INSERT INTO questions (id_soal, id_ujian, teks_soal, url_gambar, id_teks,
                    opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, opsi_f, opsi_g, opsi_h, opsi_i, opsi_j,
                    kunci_jawaban, bobot, pembahasan, urutan)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('QST'), $idUjian, $teks, tostr($q['url_gambar'] ?? ''), tostr($q['id_teks'] ?? ''),
                    tostr($q['opsi_a'] ?? ''), tostr($q['opsi_b'] ?? ''), tostr($q['opsi_c'] ?? ''),
                    tostr($q['opsi_d'] ?? ''), tostr($q['opsi_e'] ?? ''), tostr($q['opsi_f'] ?? ''),
                    tostr($q['opsi_g'] ?? ''), tostr($q['opsi_h'] ?? ''), tostr($q['opsi_i'] ?? ''),
                    tostr($q['opsi_j'] ?? ''),
                    $kunci, $bobot, tostr($q['pembahasan'] ?? ''), $urutan++,
                ]
            );
            $count++;
        }

        if ($count === 0) {
            return err('Tidak ada baris soal yang valid untuk disimpan.');
        }

        log_activity(tostr($username), 'Import Questions', 'Berhasil mengimpor ' . $count . ' butir soal ke ujian ' . $idUjian);
        return ['success' => true, 'count' => $count, 'message' => 'Berhasil mengimpor ' . $count . ' butir soal dari Excel.'];
    } catch (Throwable $e) {
        return err('Gagal mengimpor soal: ' . $e->getMessage());
    }
}

/**
 * Simpan hasil generate soal AI ke bank soal (bobot selalu 1, sesuai aplikasi asal).
 */
function saveBulkQuestions($username, $token, $id_ujian, $questionsList): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi tidak valid.');
    }
    $idUjian = trim(tostr($id_ujian));
    if ($idUjian === '' || !is_array($questionsList) || count($questionsList) === 0) {
        return err('Data soal tidak valid atau kosong.');
    }

    try {
        $urutan = next_question_order($idUjian);
        $count = 0;

        foreach ($questionsList as $q) {
            if (!is_array($q)) {
                continue;
            }
            $kunci = strtoupper(trim(tostr($q['kunci_jawaban'] ?? '')));
            if ($kunci === '') {
                $kunci = 'A';
            }
            db_exec(
                'INSERT INTO questions (id_soal, id_ujian, teks_soal, url_gambar, id_teks,
                    opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, opsi_f, opsi_g, opsi_h, opsi_i, opsi_j,
                    kunci_jawaban, bobot, pembahasan, urutan)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    new_id('QST'), $idUjian, tostr($q['teks_soal'] ?? ''), tostr($q['url_gambar'] ?? ''), '',
                    tostr($q['opsi_a'] ?? ''), tostr($q['opsi_b'] ?? ''), tostr($q['opsi_c'] ?? ''),
                    tostr($q['opsi_d'] ?? ''), tostr($q['opsi_e'] ?? ''), tostr($q['opsi_f'] ?? ''),
                    tostr($q['opsi_g'] ?? ''), tostr($q['opsi_h'] ?? ''), tostr($q['opsi_i'] ?? ''),
                    tostr($q['opsi_j'] ?? ''),
                    $kunci, 1, tostr($q['pembahasan'] ?? ''), $urutan++,
                ]
            );
            $count++;
        }

        log_activity(tostr($username), 'AI Generate Questions', 'Berhasil menambahkan ' . $count . ' butir soal AI ke ujian ' . $idUjian);
        return ['success' => true, 'count' => $count, 'message' => 'Berhasil menyimpan ' . $count . ' butir soal ke bank soal.'];
    } catch (Throwable $e) {
        return err('Gagal menyimpan soal AI: ' . $e->getMessage());
    }
}

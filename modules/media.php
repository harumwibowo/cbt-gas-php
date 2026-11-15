<?php
/**
 * Modul media: unggah & penyajian gambar yang disimpan langsung di MySQL.
 *
 * Menggantikan praktik lama (menempel URL atau ID berkas Google Drive) pada
 * kolom `questions.url_gambar` dan `settings.logo_url`. Nilai kolom kini berisi
 * URL internal `media.php?id=MED-XXXXXXXX`, sehingga seluruh markup `<img src="...">`
 * yang sudah ada tetap bekerja tanpa perubahan, dan URL eksternal lama pun tetap
 * dapat ditampilkan.
 */
declare(strict_types=1);

/** URL publik sebuah media. */
function media_public_url(string $idMedia): string
{
    return 'media.php?id=' . rawurlencode($idMedia);
}

/**
 * Ambil ID media dari URL internal (media.php?id=...), atau null bila bukan media lokal.
 * Menerima juga bentuk absolut (http://host/path/media.php?id=...).
 */
function media_id_from_url($url): ?string
{
    $url = trim(tostr($url));
    if ($url === '') {
        return null;
    }
    if (preg_match('#(?:^|/)media\.php\?id=([A-Za-z0-9\-_]+)#i', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** Simpan byte gambar ke tabel media. Mengembalikan [id, url] (deduplikasi via hash). */
function media_store_blob(string $username, string $namaFile, string $mime, string $bytes): array
{
    $hash = hash('sha256', $bytes);
    $existing = db_one('SELECT id_media FROM media WHERE hash = ? LIMIT 1', [$hash]);
    if ($existing) {
        return ['id' => tostr($existing['id_media']), 'url' => media_public_url(tostr($existing['id_media']))];
    }

    $idMedia = new_id('MED');
    $stmt = db()->prepare(
        'INSERT INTO media (id_media, nama_file, mime, ukuran, hash, data, username) VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->bindValue(1, $idMedia);
    $stmt->bindValue(2, mb_substr($namaFile !== '' ? $namaFile : ($idMedia . '.' . (ALLOWED_IMAGE_MIMES[$mime] ?? 'bin')), 0, 255));
    $stmt->bindValue(3, $mime);
    $stmt->bindValue(4, strlen($bytes), PDO::PARAM_INT);
    $stmt->bindValue(5, $hash);
    $stmt->bindValue(6, $bytes, PDO::PARAM_LOB);
    $stmt->bindValue(7, mb_substr($username, 0, 100));
    $stmt->execute();

    return ['id' => $idMedia, 'url' => media_public_url($idMedia)];
}

/**
 * Validasi & simpan satu berkas hasil unggahan ($_FILES[...]).
 *
 * @return array{success:bool, id?:string, url?:string, mime?:string, ukuran?:int, nama_file?:string, message?:string}
 */
function media_store_upload(string $username, array $file, ?int $maxBytes = null): array
{
    $maxBytes = $maxBytes ?? MAX_UPLOAD_BYTES;

    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
        return err('Berkas gambar tidak ditemukan pada permintaan.');
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $pesan = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran berkas melebihi batas server (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran berkas melebihi batas formulir.',
            UPLOAD_ERR_PARTIAL    => 'Berkas hanya terunggah sebagian. Coba lagi.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada berkas yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis berkas sementara.',
            UPLOAD_ERR_EXTENSION  => 'Unggahan dihentikan oleh ekstensi PHP.',
        ][$errorCode] ?? ('Unggahan gagal (kode ' . $errorCode . ').');
        return err($pesan);
    }

    $tmpPath = $file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        return err('Berkas tidak valid (bukan hasil unggahan HTTP).');
    }

    $size = (int) ($file['size'] ?? filesize($tmpPath));
    if ($size <= 0) {
        return err('Berkas kosong.');
    }
    if ($size > $maxBytes) {
        return err('Ukuran gambar maksimal ' . round($maxBytes / 1048576, 1) . ' MB.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($tmpPath);
    }
    $mime = strtolower(trim($mime));

    // Fallback berdasarkan ekstensi bila deteksi MIME tidak tersedia.
    if ($mime === '' || $mime === 'application/octet-stream') {
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
        ][$ext] ?? '';
    }

    if (!isset(ALLOWED_IMAGE_MIMES[$mime])) {
        return err('Format gambar tidak didukung. Gunakan PNG, JPG, WebP, GIF, atau SVG.');
    }

    // Pastikan berkas benar-benar gambar (kecuali SVG yang berbasis teks).
    if ($mime !== 'image/svg+xml') {
        $info = @getimagesize($tmpPath);
        if ($info === false || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            return err('Berkas bukan gambar yang valid.');
        }
    }

    $bytes = (string) file_get_contents($tmpPath);
    if ($bytes === '') {
        return err('Gagal membaca isi berkas gambar.');
    }

    $namaFile = (string) ($file['name'] ?? '');
    $stored = media_store_blob($username, $namaFile, $mime, $bytes);

    return [
        'success'   => true,
        'id'        => $stored['id'],
        'url'       => $stored['url'],
        'mime'      => $mime,
        'ukuran'    => strlen($bytes),
        'nama_file' => $namaFile !== '' ? basename($namaFile) : $stored['id'],
        'message'   => 'Gambar berhasil diunggah dan disimpan di database.',
    ];
}

/** Hapus satu media berdasarkan ID. */
function media_delete_by_id(?string $idMedia): void
{
    $idMedia = trim(tostr($idMedia));
    if ($idMedia === '') {
        return;
    }
    try {
        db_exec('DELETE FROM media WHERE id_media = ?', [$idMedia]);
    } catch (Throwable $e) {
        error_log('media_delete_by_id: ' . $e->getMessage());
    }
}

/**
 * Hapus media lokal yang direferensikan sebuah URL (dipakai saat gambar soal/logo diganti
 * atau soal/ujian dihapus). URL eksternal lama diabaikan.
 */
function media_delete_by_url($url): void
{
    $idMedia = media_id_from_url($url);
    if ($idMedia !== null) {
        media_delete_by_id($idMedia);
    }
}

/**
 * Hapus media yang tidak lagi dirujuk oleh soal maupun pengaturan logo.
 * Aman dijalankan kapan saja (dipanggil manual dari skrip pemeliharaan).
 *
 * @return int jumlah berkas yang dibersihkan
 */
function media_purge_orphans(): int
{
    $used = [];
    foreach (db_all("SELECT url_gambar FROM questions WHERE url_gambar LIKE '%media.php?id=%'") as $row) {
        $idMedia = media_id_from_url($row['url_gambar']);
        if ($idMedia !== null) {
            $used[$idMedia] = true;
        }
    }
    $logoId = media_id_from_url(settings_map()['logo_url'] ?? '');
    if ($logoId !== null) {
        $used[$logoId] = true;
    }

    $deleted = 0;
    foreach (db_all('SELECT id_media FROM media') as $row) {
        $idMedia = tostr($row['id_media']);
        if (!isset($used[$idMedia])) {
            db_exec('DELETE FROM media WHERE id_media = ?', [$idMedia]);
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Hapus media lokal hanya bila tidak lagi dirujuk oleh soal mana pun
 * maupun oleh pengaturan logo sekolah.
 */
function media_delete_if_unused($url): void
{
    $idMedia = media_id_from_url($url);
    if ($idMedia === null) {
        return;
    }
    try {
        $dipakaiSoal = (int) db_val(
            "SELECT COUNT(*) FROM questions WHERE url_gambar LIKE ?",
            ['%' . $idMedia . '%'],
            0
        );
        $dipakaiLogo = media_id_from_url(settings_map()['logo_url'] ?? '') === $idMedia;
        if ($dipakaiSoal === 0 && !$dipakaiLogo) {
            media_delete_by_id($idMedia);
        }
    } catch (Throwable $e) {
        error_log('media_delete_if_unused: ' . $e->getMessage());
    }
}

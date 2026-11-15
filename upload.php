<?php
/**
 * Endpoint unggah gambar (multipart/form-data).
 *
 * Pemakaian:  POST upload.php
 *             field: username, token, file
 *
 * Berkas gambar disimpan langsung ke kolom LONGBLOB pada tabel `media`
 * (bukan ke disk, bukan ke Google Drive), lalu dikembalikan sebagai URL
 * internal `media.php?id=MED-XXXXXXXX` untuk dipakai pada url_gambar / logo_url.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/modules/media.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Gunakan metode POST.']);
        exit;
    }

    ensure_schema();

    $username = trim((string) ($_POST['username'] ?? ''));
    $token = trim((string) ($_POST['token'] ?? ''));

    if ($username === '' || $token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login kembali.']);
        exit;
    }

    if (!validate_session($username, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sesi kedaluwarsa. Silakan login kembali.']);
        exit;
    }

    $role = strtolower(trim(current_role($username)));
    if ($role !== 'admin' && $role !== 'guru') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Hanya Admin atau Guru yang dapat mengunggah gambar.']);
        exit;
    }

    $file = $_FILES['file'] ?? ($_FILES['gambar'] ?? null);
    if (!is_array($file)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tidak ada berkas yang dikirim (field "file").']);
        exit;
    }

    $result = media_store_upload($username, $file);

    if (empty($result['success'])) {
        http_response_code(422);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[CBT upload] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal mengunggah gambar: ' . $e->getMessage()]);
}

<?php
/**
 * Entry point aplikasi: menyajikan SPA (Index.html asli) dengan
 * shim RPC google.script.run → api.php disuntikkan otomatis.
 */
declare(strict_types=1);

// Kompresi respons (gzip/deflate) untuk HTML 650+ KB. ob_gzhandler otomatis
// menyesuaikan dengan Accept-Encoding klien; fallback buffer biasa bila zlib
// tidak tersedia. Ini menggantikan peran mod_deflate saat tidak tersedia.
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

require_once __DIR__ . '/config/config.php';

if (maintenance_active()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 60');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Pembaruan</title></head>'
        . '<body style="font-family:system-ui;text-align:center;padding:4rem">'
        . '<h1>Sedang diperbarui</h1><p>Aplikasi sedang dalam pembaruan. Silakan muat ulang beberapa saat lagi.</p>'
        . '</body></html>';
    exit;
}

try {
    ensure_schema();
} catch (Throwable $e) {
    error_log('[CBT] Bootstrap skema gagal: ' . $e->getMessage());
}

// Instalasi baru / database belum siap -> arahkan ke halaman setup.
if (setup_needs_wizard()) {
    header('Location: setup.php');
    exit;
}

$viewFile = APP_ROOT . '/Index.html';
if (!is_file($viewFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File tampilan Index.html tidak ditemukan.';
    exit;
}

$html = (string) file_get_contents($viewFile);

// Favicon mengikuti logo sekolah (disajikan oleh favicon.php dari database).
$faviconSeed = 'default';
try {
    $faviconLogo = trim(tostr(settings_map()['logo_url'] ?? ''));
    $faviconSeed = $faviconLogo !== '' ? substr(md5($faviconLogo), 0, 12) : 'default';
} catch (Throwable $e) {
    $faviconSeed = 'default';
}

// ETag/Last-Modified dari mtime Index.html + versi aplikasi + favicon + shim.
// Klien yang masih menyimpan salinan valid menerima 304 (tanpa HTML 650 KB).
$viewMtime = (int) @filemtime($viewFile);
$shimFile = APP_ROOT . '/assets/js/rpc-shim.js';
$shimVersion = is_file($shimFile) ? (string) @filemtime($shimFile) : '1';
$versionTag = function_exists('app_version_name') ? app_version_name() : (string) @file_get_contents(APP_ROOT . '/VERSION');
$etag = '"' . md5($viewMtime . '|' . $versionTag . '|' . $faviconSeed . '|' . $shimVersion) . '"';
$lastModified = gmdate('D, d M Y H:i:s', $viewMtime) . ' GMT';
$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
$notModified = $ifNoneMatch === $etag
    || ($ifModifiedSince !== '' && (int) @strtotime($ifModifiedSince) >= $viewMtime);
if ($notModified) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=300, must-revalidate');
    exit;
}
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=300, must-revalidate');

$faviconTag = '<link rel="icon" type="image/png" href="favicon.php?v=' . $faviconSeed . '" data-app-favicon="1">'
    . '<link rel="apple-touch-icon" href="favicon.php?v=' . $faviconSeed . '" data-app-favicon="1">';
// Penanda data-app-favicon dipakai agar penyisipan tidak dobel (Index.html juga
// memuat selektor link[rel="icon"] pada JavaScript-nya).
if (!str_contains($html, 'data-app-favicon')) {
    $headPos = strpos($html, '<head>');
    if ($headPos !== false) {
        $headPos += strlen('<head>');
        $html = substr($html, 0, $headPos) . $faviconTag . substr($html, $headPos);
    } else {
        $html = $faviconTag . $html;
    }
}

$shimTag = '<script src="assets/js/rpc-shim.js?v=' . $shimVersion . '"></script>';
// Sisipkan shim tepat sebelum </body> TERAKHIR (yang pertama bisa berada di
// dalam template literal HTML printer kartu ujian).
if (!str_contains($html, 'assets/js/rpc-shim.js')) {
    $pos = strrpos($html, '</body>');
    if ($pos !== false) {
        $html = substr($html, 0, $pos) . '  ' . $shimTag . "\n" . substr($html, $pos);
    } else {
        $html .= "\n" . $shimTag . "\n";
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// CSP tetap mengizinkan inline script karena Index.html adalah SPA satu berkas,
// tetapi mematikan object/plugin dan membatasi frame serta sumber eksternal.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
echo $html;

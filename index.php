<?php
/**
 * Entry point aplikasi: menyajikan SPA (Index.html asli) dengan
 * shim RPC google.script.run → api.php disuntikkan otomatis.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

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

$shimTag = '<script src="assets/js/rpc-shim.js"></script>';
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
header('X-Frame-Options: ALLOWALL');
echo $html;

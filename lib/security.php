<?php
/**
 * Helper keamanan dan validasi yang dipakai lintas modul.
 *
 * Fokus:
 *  - menyaring pesan exception agar detail internal tidak bocor ke klien;
 *  - sanitasi HTML yang disimpan (mencegah stored XSS);
 *  - validasi input dasar (username, panjang teks, angka).
 */
declare(strict_types=1);

/** ID korelasi per request untuk log dan pesan error. */
function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = 'REQ-' . strtoupper(bin2hex(random_bytes(6)));
    }
    return $id;
}

/**
 * Ubah pesan exception internal menjadi pesan aman untuk klien.
 * Pesan validasi yang memang ditujukan ke pengguna tetap dipertahankan.
 */
function client_error_message(string $message): string
{
    $raw = trim($message);
    if ($raw === '') {
        return 'Terjadi kesalahan pada server.';
    }

    $patterns = [
        'SQLSTATE',
        'PDOException',
        'PDO',
        'Unknown column',
        'Unknown database',
        'Access denied for user',
        'Duplicate entry',
        'doesn\'t exist',
        'Table \'',
        'Base table or view not found',
        'You have an error in your SQL syntax',
        'Call to undefined',
        'Trying to access array offset',
        'htmlspecialchars',
        'Stack trace',
        ' on line ',
        ' in file ',
        'C:\\',
        'G:\\',
        '/var/www',
        '/home/',
        'MySQL server has gone away',
        'Too many connections',
    ];
    foreach ($patterns as $needle) {
        if (stripos($raw, $needle) !== false) {
            error_log('[' . request_id() . '] Pesan internal disaring: ' . $raw);
            return 'Terjadi kesalahan pada server. Silakan coba lagi. (Ref: ' . request_id() . ')';
        }
    }

    // Jangan kirim pesan yang mengandung path absolut atau jejak teknis.
    if (preg_match('~(^|[\s(])(/|[A-Za-z]:\\\\)[^\s]+~', $raw)) {
        error_log('[' . request_id() . '] Pesan path disaring: ' . $raw);
        return 'Terjadi kesalahan pada server. Silakan coba lagi. (Ref: ' . request_id() . ')';
    }

    // Batasi panjang pesan agar respons tetap wajar.
    if (function_exists('mb_substr')) {
        return mb_substr($raw, 0, 500);
    }
    return substr($raw, 0, 500);
}

/**
 * Sanitasi HTML yang akan disimpan dan nantinya dirender dengan innerHTML.
 *
 * Ini bukan parser HTML penuh, tetapi menghapus vektor XSS paling berbahaya:
 * script/frame/form/style, atribut on*, URL javascript:, srcdoc, dan SVG/MathML.
 * Tag pemformatan umum (b, i, u, sup, sub, br, p, ul, ol, li, img, table, dll.)
 * tetap diizinkan agar soal yang kaya format tidak rusak.
 */
function sanitize_html_fragment($value): string
{
    $html = (string) $value;
    if ($html === '') {
        return '';
    }

    // Buang blok berbahaya beserta isinya.
    $html = (string) preg_replace(
        '#<(script|style|iframe|object|embed|form|textarea|select|button|svg|math|template|noscript|link|meta)\b[^>]*>.*?</\1\s*>#is',
        '',
        $html
    );
    // Buang tag berbahaya yang tidak berpasangan.
    $html = (string) preg_replace(
        '#</?(script|style|iframe|object|embed|form|textarea|select|button|svg|math|template|noscript|link|meta)\b[^>]*>#is',
        '',
        $html
    );
    // Buang event handler (onclick, onerror, onload, ...).
    $html = (string) preg_replace(
        '#\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is',
        '',
        $html
    );
    // Buang atribut srcdoc dan URL javascript:/vbscript:/data:text/html.
    $html = (string) preg_replace('#\s+srcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
    $html = (string) preg_replace(
        '#(href|src|action|formaction)\s*=\s*(["\']?)\s*(?:javascript|vbscript|data\s*:\s*text/html)\s*:[^"\'>\s]*#is',
        '$1=$2#',
        $html
    );
    // Jika masih ada tag yang tidak dikenal, biarkan: teks tetap di-escape oleh
    // browser saat dirender, dan vektor eksekusi utama sudah dihapus.

    return $html;
}

/** Teks biasa: buang seluruh tag HTML dan rapikan spasi. */
function sanitize_plain_text($value): string
{
    $text = strip_tags((string) $value);
    $text = str_replace(["\0", "\r"], '', $text);
    return trim($text);
}

/** Username yang aman: 3-50 karakter, huruf/angka/titik/garis bawah/strip. */
function is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{2,49}$/', strtolower(trim($username)));
}

/** Normalisasi username: huruf kecil, tanpa spasi tepi. */
function normalize_username($username): string
{
    return strtolower(trim(tostr($username)));
}

/** Batasi panjang teks (multibyte bila tersedia). */
function limit_text($value, int $max): string
{
    $text = (string) $value;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

/**
 * Cek apakah request state-changing berasal dari origin yang sama.
 * Frontend mengirim POST dengan header Origin pada browser modern.
 */
function api_request_is_same_origin(): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        // Sebagian browser/klien tidak mengirim Origin; Referer menjadi cadangan.
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return true;
        }
        $origin = $referer;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return true;
    }

    $parts = parse_url($origin);
    if ($parts === false || empty($parts['host'])) {
        return false;
    }
    $originHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return strcasecmp($originHost, $host) === 0;
}

/** URL gambar/media yang aman (relatif aplikasi atau http/https). */
function safe_media_url($value): string
{
    $url = trim((string) $value);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $url)) {
        return '';
    }
    // URL internal hasil upload.php berbentuk `media.php?id=MED-XXXXXXXX`
    // (opsional dengan parameter tambahan seperti `&download=1`). Tanpa cabang
    // ini, gambar yang baru diunggah akan dibuang saat soal disimpan.
    if (preg_match('#^media\.php(?:\?[A-Za-z0-9_.~%=&+\-]*)?$#i', $url)) {
        return limit_text($url, 1000);
    }
    if (str_starts_with($url, 'media/') || str_starts_with($url, 'uploads/')
        || str_starts_with($url, '/') || preg_match('#^https?://#i', $url)) {
        return limit_text($url, 1000);
    }
    return '';
}

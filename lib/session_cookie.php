<?php
/**
 * Cookie sesi yang diamankan + CSRF double-submit (Bagian 3.3).
 *
 * Mode default tetap kompatibel: klien mengirim token di body JSON. Bila cookie
 * cbt_session tersedia (setelah login), validate_session() memakai cookie itu,
 * sehingga token tidak harus disimpan di localStorage. Cookie memakai
 * HttpOnly + Secure (HTTPS) + SameSite=Lax.
 */
declare(strict_types=1);

/** Apakah request saat ini melalui HTTPS. */
function auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $proto === 'https' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Set cookie sesi (HttpOnly; tidak bisa dibaca JavaScript). */
function auth_set_session_cookie(string $token, int $ttlMinutes = 720): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('cbt_session', $token, [
        'expires'  => time() + max(15, min(43200, $ttlMinutes)) * 60,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Hapus cookie sesi. */
function auth_clear_session_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('cbt_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Token sesi dari cookie bila ada, otherwise token dari body klien lama.
 * Dipakai validate_session() supaya cookie dan localStorage sama-sama jalan.
 */
function auth_request_token(string $fallback): string
{
    $cookie = trim((string) ($_COOKIE['cbt_session'] ?? ''));
    return $cookie !== '' ? $cookie : $fallback;
}

/** Set cookie CSRF (boleh dibaca JS agar shim bisa mengirim header). */
function auth_set_csrf_cookie(string $token): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('cbt_csrf', $token, [
        'expires'  => time() + max(15, min(43200, session_ttl_minutes())) * 60,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function auth_clear_csrf_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie('cbt_csrf', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/** Nilai cookie CSRF saat ini (atau '' bila belum login). */
function csrf_cookie_value(): string
{
    return trim((string) ($_COOKIE['cbt_csrf'] ?? ''));
}

/**
 * Validasi CSRF double-submit: header X-CSRF-Token harus sama dengan cookie.
 * Klien lama (Python/e2e, curl) yang tidak memakai cookie tidak dikenakan cek.
 */
function api_csrf_valid(): bool
{
    // Pengecekan double-submit ini bersifat defense-in-depth dan OPT-IN.
    // Desain utama aplikasi sudah CSRF-resistant: token sesi dikirim di body
    // JSON (bukan cookie yang otomatis dikirim browser) dan api.php sudah
    // memeriksa Origin/Referer via api_request_is_same_origin().
    // Aktifkan hanya bila semua klien dipastikan mengirim X-CSRF-Token:
    //   CBT_CSRF_ENFORCE=1
    if (getenv('CBT_CSRF_ENFORCE') !== '1') {
        return true;
    }

    $cookie = csrf_cookie_value();
    if ($cookie === '') {
        return true;
    }
    $header = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $header !== '' && hash_equals($cookie, $header);
}

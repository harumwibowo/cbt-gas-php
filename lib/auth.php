<?php
/**
 * Autentikasi & manajemen sesi berbasis token (padanan modul 4 pada Code.gs).
 * Token disimpan pada kolom users.token_session (dipisah koma) sehingga
 * perilaku multi-sesi siswa tetap identik dengan aplikasi asal.
 */
declare(strict_types=1);

/** Respons sukses standar. */
function ok(array $extra = []): array
{
    return array_merge(['success' => true], $extra);
}

/** Respons gagal standar. */
function err(string $message, array $extra = []): array
{
    return array_merge(['success' => false, 'message' => client_error_message($message)], $extra);
}

// ---------------------------------------------------------------------------
// Password: hash (bcrypt/argon2) + salinan terenkripsi untuk kartu ujian
// ---------------------------------------------------------------------------

/** True bila nilai sudah berupa hash password (bukan plain text data lama). */
function is_password_hash($value): bool
{
    $v = tostr($value);
    return str_starts_with($v, '$2y$')
        || str_starts_with($v, '$2a$')
        || str_starts_with($v, '$2b$')
        || str_starts_with($v, '$argon2');
}

/** Hash password baru (PASSWORD_DEFAULT). */
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Verifikasi password. Mendukung data lama yang masih plain text (hasil migrasi
 * dari Google Sheets) sehingga pengguna tidak perlu reset password.
 */
function verify_password(string $plain, $stored): bool
{
    $s = tostr($stored);
    if ($s === '') {
        return false;
    }
    if (is_password_hash($s)) {
        return password_verify($plain, $s);
    }
    return hash_equals($s, $plain);
}

/** Pasangan [hash, null] untuk kolom password & password_enc (legacy). */
function password_fields(string $plain): array
{
    // Password TIDAK lagi disimpan dalam bentuk yang dapat didekripsi.
    // Kolom password_enc dipertahankan hanya untuk kompatibilitas skema dan
    // selalu diisi NULL.
    return [hash_password($plain), null];
}

/**
 * Password asli untuk ditampilkan pada kartu ujian.
 *
 * Fitur ini sengaja dinonaktifkan: menyimpan password yang dapat didekripsi
 * membuat seluruh akun berisiko bila database dan kunci enkripsi bocor.
 * Gunakan reset password / PIN ujian sekali pakai sebagai gantinya.
 */
function password_for_display(array $row): string
{
    return '';
}

/** Naikkan penyimpanan password lama (plain text) menjadi hash. */
function upgrade_legacy_password(array $row, string $plain): void
{
    $stored = tostr($row['password'] ?? '');
    try {
        if (is_password_hash($stored)) {
            if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password = ? WHERE id_user = ?', [hash_password($plain), tostr($row['id_user'])]);
            }
            return;
        }
        [$hash, $enc] = password_fields($plain);
        db_exec('UPDATE users SET password = ?, password_enc = NULL WHERE id_user = ?', [$hash, tostr($row['id_user'])]);
        log_activity(tostr($row['username']), 'Upgrade Password', 'Penyimpanan password ditingkatkan ke hash.');
    } catch (Throwable $e) {
        error_log('Gagal upgrade hash password: ' . $e->getMessage());
    }
}

/**
 * Ambil satu baris user dengan cache per request.
 *
 * validate_session(), current_role() dan rbac_authorize() sebelumnya masing-
 * masing memanggil find_user() sehingga satu action bisa 2-3x SELECT users
 * yang sama. Cache ini hanya hidup selama satu request dan dibatalkan otomatis
 * saat data user diubah (lihat current_user_invalidate()).
 */
function current_user(string $username, bool $fresh = false): ?array
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return null;
    }
    if (!isset($GLOBALS['CBT_USER_CACHE']) || !is_array($GLOBALS['CBT_USER_CACHE'])) {
        $GLOBALS['CBT_USER_CACHE'] = [];
    }
    if (!$fresh && array_key_exists($key, $GLOBALS['CBT_USER_CACHE'])) {
        return $GLOBALS['CBT_USER_CACHE'][$key];
    }
    $row = find_user($key);
    $GLOBALS['CBT_USER_CACHE'][$key] = $row;
    return $row;
}

/** Batalkan cache user (sebuah username atau seluruhnya). */
function current_user_invalidate(?string $username = null): void
{
    if ($username === null) {
        $GLOBALS['CBT_USER_CACHE'] = [];
        return;
    }
    unset($GLOBALS['CBT_USER_CACHE'][strtolower(trim($username))]);
}

/** Ambil satu baris user berdasarkan username (case-insensitive). */
function find_user(string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return null;
    }
    // Jalur cepat memakai index unik username. Fallback LOWER(TRIM()) hanya
    // untuk data lama yang belum dinormalisasi.
    $row = db_one('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
    if ($row !== null) {
        return $row;
    }
    return db_one('SELECT * FROM users WHERE LOWER(TRIM(username)) = ? LIMIT 1', [$username]);
}

/**
 * Validasi session token (padanan validateSession()).
 *
 * Token disimpan sebagai hash SHA-256 pada tabel sessions, memiliki masa
 * berlaku absolut + idle timeout, dan selalu memeriksa status akun. Token
 * lama dari kolom users.token_session (sebelum upgrade) tetap diterima sekali
 * lalu dimigrasikan ke tabel sessions.
 */
function validate_session($username, $token): bool
{
    $username = strtolower(trim(tostr($username)));
    // Cookie HttpOnly lebih dipercaya bila tersedia; klien lama tetap bisa
    // mengirim token di body (kompatibilitas shim/Apps Script).
    $token = auth_request_token(tostr($token));
    if ($username === '' || $token === '') {
        return false;
    }

    try {
        $user = current_user($username);
        if (!$user) {
            return false;
        }
        if (strtolower(trim(tostr($user['status_aktif']))) === 'nonaktif') {
            return false;
        }

        $hash = session_token_hash($token);
        $row = db_one(
            'SELECT id_session, last_seen_at, expires_at, revoked_at
             FROM sessions WHERE token_hash = ? AND username = ? LIMIT 1',
            [$hash, $username]
        );
        if ($row !== null) {
            if (tostr($row['revoked_at']) !== '') {
                return false;
            }
            $now = time();
            $expiresTs = auth_datetime_ts($row['expires_at']);
            $lastSeenTs = auth_datetime_ts($row['last_seen_at']);
            $idleLimit = max(1, session_idle_minutes()) * 60;
            if ($expiresTs === null || $now > $expiresTs
                || ($lastSeenTs !== null && ($now - $lastSeenTs) > $idleLimit)) {
                session_revoke_by_hash($hash);
                return false;
            }
            // Hindari write pada setiap request: perbarui last_seen maksimal
            // sekali per 5 menit.
            if ($lastSeenTs === null || ($now - $lastSeenTs) > 300) {
                db_exec('UPDATE sessions SET last_seen_at = ? WHERE id_session = ?', [now_dt(), tostr($row['id_session'])]);
            }
            return true;
        }

        // Kompatibilitas token lama (users.token_session berupa token mentah).
        $tokens = parse_session_tokens($user['token_session'] ?? '');
        if (in_array($token, $tokens, true)) {
            session_create($username, $token, auth_client_ip(), auth_user_agent());
            $remaining = array_values(array_filter($tokens, static fn ($t) => $t !== $token));
            db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $remaining), tostr($user['id_user'])]);
            return true;
        }
    } catch (Throwable $e) {
        error_log('Error validateSession: ' . $e->getMessage());
    }
    return false;
}

/** Status sesi untuk heartbeat klien. Fail-closed saat terjadi kesalahan. */
function get_session_status($username, $token): array
{
    try {
        $valid = validate_session($username, $token);

        // Dipakai klien untuk memaksa ganti password default sebelum memakai aplikasi.
        $mustChange = false;
        if ($valid) {
            $row = db_one(
                'SELECT must_change_password FROM users WHERE username = ?',
                [strtolower(trim(tostr($username)))]
            );
            $mustChange = $row ? (int) ($row['must_change_password'] ?? 0) === 1 : false;
        }

        return [
            'success'             => true,
            'valid'               => $valid,
            'must_change_password' => $mustChange,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'valid' => false, 'message' => 'Status sesi tidak dapat diperiksa.'];
    }
}

// ---------------------------------------------------------------------------
// Sesi berbasis tabel sessions (hash token, masa berlaku, idle timeout)
// ---------------------------------------------------------------------------

/** Hash token sesi yang disimpan di database. */
function session_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Masa berlaku absolut sesi (menit). */
function session_ttl_minutes(): int
{
    $v = (int) (getenv('CBT_SESSION_TTL_MINUTES') ?: 720);
    return max(15, min(43200, $v));
}

/** Batas idle sesi (menit). */
function session_idle_minutes(): int
{
    $v = (int) (getenv('CBT_SESSION_IDLE_MINUTES') ?: 120);
    return max(5, min(10080, $v));
}

/** IP klien (dicatat untuk audit sesi). */
function auth_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return substr($ip, 0, 45);
}

/** User-Agent yang dikirim browser ke server (tidak dapat dipalsukan klien). */
function auth_user_agent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

/** Buat baris sesi baru. */
function session_create(string $username, string $token, string $ip = '', string $userAgent = ''): void
{
    $username = strtolower(trim($username));
    $now = time();
    db_exec(
        'INSERT INTO sessions (id_session, username, token_hash, created_at, last_seen_at, expires_at, ip, user_agent)
         VALUES (?,?,?,?,?,?,?,?)',
        [
            new_id('SES'),
            $username,
            session_token_hash($token),
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now + (session_ttl_minutes() * 60)),
            substr($ip, 0, 45),
            substr($userAgent, 0, 255),
        ]
    );
}

/** Cabut satu sesi berdasarkan hash token. */
function session_revoke_by_hash(string $hash): void
{
    db_exec('UPDATE sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL', [now_dt(), $hash]);
}

/** Cabut sesi berdasarkan token mentah (logout perangkat ini). */
function session_revoke_token(string $token): void
{
    if ($token === '') {
        return;
    }
    session_revoke_by_hash(session_token_hash($token));
}

/** Cabut seluruh sesi seorang pengguna. */
function session_revoke_all(string $username): void
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return;
    }
    db_exec('UPDATE sessions SET revoked_at = ? WHERE username = ? AND revoked_at IS NULL', [now_dt(), $username]);
}

/** Jumlah sesi aktif seorang pengguna. */
function session_count_active(string $username): int
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return 0;
    }
    try {
        return (int) db_val(
            'SELECT COUNT(*) FROM sessions
             WHERE username = ? AND revoked_at IS NULL AND expires_at > ?',
            [$username, now_dt()],
            0
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/** Timestamp aman dari nilai DATETIME/NULL. */
function auth_datetime_ts($val): ?int
{
    $raw = tostr($val);
    if ($raw === '' || str_starts_with($raw, '0000')) {
        return null;
    }
    $ts = strtotime($raw);
    return $ts === false ? null : $ts;
}

/**
 * Pertahankan hanya $keep sesi terbaru untuk seorang pengguna.
 * @return int jumlah sesi yang dicabut
 */
function session_trim_to(string $username, int $keep): int
{
    $username = strtolower(trim($username));
    $keep = max(0, $keep);
    try {
        $sessions = db_all(
            'SELECT id_session FROM sessions
             WHERE username = ? AND revoked_at IS NULL AND expires_at > ?
             ORDER BY created_at DESC, id_session DESC',
            [$username, now_dt()]
        );
        $removed = 0;
        foreach ($sessions as $idx => $row) {
            if ($idx < $keep) {
                continue;
            }
            db_exec('UPDATE sessions SET revoked_at = ? WHERE id_session = ?', [now_dt(), tostr($row['id_session'])]);
            $removed++;
        }
        return $removed;
    } catch (Throwable $e) {
        error_log('session_trim_to: ' . $e->getMessage());
        return 0;
    }
}

/** Catat percobaan login (untuk rate limiting). */
function login_note_attempt(string $username, bool $success): void
{
    try {
        db_exec(
            'INSERT INTO login_attempts (username, ip, attempted_at, success) VALUES (?,?,?,?)',
            [strtolower(trim($username)), auth_client_ip(), now_dt(), $success ? 1 : 0]
        );
        if ($success) {
            db_exec('DELETE FROM login_attempts WHERE username = ? OR ip = ?', [strtolower(trim($username)), auth_client_ip()]);
        }
    } catch (Throwable $e) {
        error_log('login_note_attempt: ' . $e->getMessage());
    }
}

/** True bila percobaan login untuk user/IP ini sedang dibatasi. */
function login_is_throttled(string $username): bool
{
    try {
        $since = date('Y-m-d H:i:s', time() - 900);
        $count = (int) db_val(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND attempted_at >= ? AND (username = ? OR ip = ?)',
            [$since, strtolower(trim($username)), auth_client_ip()],
            0
        );
        return $count >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

/** Bersihkan sesi/percobaan login yang sudah lama (best-effort). */
function session_cleanup(): void
{
    try {
        db_exec('DELETE FROM sessions WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        db_exec('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    } catch (Throwable $e) {
        error_log('session_cleanup: ' . $e->getMessage());
    }
}


/** Role user yang sedang aktif (huruf kecil) atau '' bila tidak ditemukan. */
function current_role(string $username): string
{
    $user = current_user($username);
    return $user ? strtolower(trim((string) $user['role'])) : '';
}

/**
 * Login pengguna (padanan login()).
 */
function login($username, $password, $userAgent = ''): array
{
    try {
        $username = strtolower(trim(tostr($username)));
        $password = trim(tostr($password));

        if ($username === '' || $password === '') {
            return err('Username atau password salah.');
        }

        if (login_is_throttled($username)) {
            return err('Terlalu banyak percobaan login. Coba lagi dalam 15 menit.');
        }

        $user = find_user($username);
        if (!$user) {
            // Tetap jalankan verifikasi dummy agar waktu respons seragam dan
            // username tidak dapat dienumerasi dari pesan/waktu.
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
            login_note_attempt($username, false);
            log_activity($username, 'Login Gagal', 'Username tidak dikenali.');
            return err('Username atau password salah.');
        }
        if (!verify_password($password, $user['password'] ?? '')) {
            login_note_attempt($username, false);
            log_activity(tostr($user['username']), 'Login Gagal', 'Password salah.');
            return err('Username atau password salah.');
        }

        // Data lama (plain text) otomatis diubah menjadi hash saat login berhasil.
        upgrade_legacy_password($user, $password);

        $status = strtolower(tostr($user['status_aktif']));
        if ($status !== '' && $status !== 'aktif') {
            login_note_attempt($username, false);
            return err('Akun Anda berstatus non-aktif. Hubungi Admin.');
        }

        $isStudent = strtolower(trim(tostr($user['role']))) === 'siswa';

        // Pembatasan browser login (hanya siswa). User-Agent diambil dari
        // header HTTP server, bukan argumen yang dapat dipalsukan klien.
        if ($isStudent) {
            $allowedBrowsers = get_allowed_student_browsers();
            $serverUa = auth_user_agent() !== '' ? auth_user_agent() : tostr($userAgent);
            if (!is_student_browser_allowed($serverUa, $allowedBrowsers)) {
                $detectedBrowser = detect_user_agent_browser($serverUa);
                $detectedLabel = $detectedBrowser !== '' ? strtoupper($detectedBrowser) : 'Tidak Dikenali';
                log_activity(
                    tostr($user['username']),
                    'Login Ditolak',
                    'Browser tidak diizinkan (' . $detectedLabel . '). Browser diizinkan: ' . $allowedBrowsers
                );
                login_note_attempt($username, false);
                return err(
                    'Login ditolak. Browser yang Anda gunakan (' . $detectedLabel . ') tidak termasuk browser yang diizinkan.'
                    . ' Browser yang diizinkan: ' . $allowedBrowsers . '. Silakan hubungi pengawas/guru.'
                );
            }
        }

        // 2FA opsional untuk Admin/Guru: password benar belum menghasilkan sesi.
        // Klien harus mengirim kode TOTP lewat verify2fa(challenge, code).
        $secret2fa = trim(tostr($user['two_factor_secret'] ?? ''));
        if ($secret2fa !== '' && !$isStudent) {
            $challenge = strtoupper(bin2hex(random_bytes(16)));
            cbt_cache_set('cbt.2fa.' . $challenge, [
                'username' => strtolower((string) $user['username']),
                'created'  => time(),
            ], 300);
            log_activity(tostr($user['username']), 'Login 2FA', 'Password benar, menunggu kode TOTP.');
            return [
                'success'      => true,
                'requires_2fa' => true,
                'challenge'    => $challenge,
                'message'      => 'Masukkan kode autentikasi dua faktor.',
            ];
        }

        [$sessionToken, $kickedCount] = auth_issue_session($user, tostr($userAgent));

        return auth_login_response($user, $sessionToken, $kickedCount, tostr($userAgent));
    } catch (Throwable $e) {
        error_log('Login error: ' . $e->getMessage());
        return err('Kesalahan server saat login. Coba lagi beberapa saat lagi.');
    }
}

/**
 * Buat sesi baru untuk user yang sudah terverifikasi (dipakai login & verify2fa).
 *
 * @return array{0:string,1:int} [token, jumlah sesi lama yang dikeluarkan]
 */
function auth_issue_session(array $user, string $userAgent = ''): array
{
    $isStudent = strtolower(trim(tostr($user['role']))) === 'siswa';
    $maxSessions = $isStudent ? get_max_student_sessions() : 3;
    $oldSessionCount = session_count_active((string) $user['username']);
    $sessionToken = new_session_token();

    session_trim_to((string) $user['username'], max(0, $maxSessions - 1));
    session_create(strtolower((string) $user['username']), $sessionToken, auth_client_ip(), auth_user_agent());
    if (tostr($user['token_session'] ?? '') !== '') {
        db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($user['id_user'])]);
    }

    login_note_attempt(strtolower(trim(tostr($user['username']))), true);
    if (random_int(1, 20) === 1) {
        session_cleanup();
    }

    // Cookie HttpOnly + CSRF double-submit (Bagian 3.3).
    auth_set_session_cookie($sessionToken, session_ttl_minutes());
    auth_set_csrf_cookie(bin2hex(random_bytes(16)));

    $detected = detect_user_agent_browser(auth_user_agent() !== '' ? auth_user_agent() : $userAgent);
    log_activity(
        tostr($user['username']),
        'Login',
        'Login berhasil dari browser/perangkat' . ($detected !== '' ? ' (' . strtoupper($detected) . ')' : '') . '.'
    );

    return [$sessionToken, max(0, $oldSessionCount - max(0, $maxSessions - 1))];
}

/** Bentuk respons login standar (dipakai login & verify2fa). */
function auth_login_response(array $user, string $sessionToken, int $kickedCount, string $userAgent = ''): array
{
        return [
            'success' => true,
            'token'   => $sessionToken,
            'user'    => [
                'id_user'      => tostr($user['id_user']),
                'username'     => tostr($user['username']),
                'nama_lengkap' => tostr($user['nama_lengkap']),
                'role'         => tostr($user['role']) !== '' ? tostr($user['role']) : 'Siswa',
                'kelas'        => tostr($user['kelas']) !== '' ? tostr($user['kelas']) : '-',
                'status_aktif' => tostr($user['status_aktif']) !== '' ? tostr($user['status_aktif']) : 'Aktif',
                'sesi'         => tostr($user['sesi'] ?? '') !== '' ? tostr($user['sesi']) : '-',
            ],
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];
}

// ---------------------------------------------------------------------------
// 2FA, perangkat aktif, dan kebijakan password (Bagian 3.3)
// ---------------------------------------------------------------------------

/**
 * Validasi kebijakan password. Mengembalikan null bila valid.
 * Panjang minimum bisa diatur lewat CBT_PASSWORD_MIN_LENGTH (default 8).
 */
function password_policy_error(string $plain, string $username = ''): ?string
{
    $min = (int) (getenv('CBT_PASSWORD_MIN_LENGTH') ?: 8);
    $min = max(8, min(64, $min));
    if (mb_strlen($plain) < $min) {
        return 'Password minimal ' . $min . ' karakter.';
    }
    if ($username !== '' && strcasecmp($plain, $username) === 0) {
        return 'Password tidak boleh sama dengan username.';
    }
    $common = ['123456', '12345678', '123456789', 'password', 'password123', 'qwerty',
               'admin', 'admin123', '111111', 'abc123', 'letmein'];
    if (in_array(strtolower($plain), $common, true)) {
        return 'Password terlalu umum. Pilih password yang lebih kuat.';
    }
    return null;
}

/** Truthy bila user berperan Admin atau Guru. */
function auth_is_staff(string $username): bool
{
    return in_array(current_role($username), ['admin', 'guru'], true);
}

/** Mulai pendaftaran 2FA: buat rahasia TOTP (belum aktif sampai dikonfirmasi). */
function setup_two_factor($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }
    if (!auth_is_staff(tostr($username))) {
        return err('2FA hanya tersedia untuk Admin atau Guru.');
    }
    try {
        $secret = totp_new_secret();
        $user = current_user(tostr($username), true);
        if (!$user) {
            return err('Akun tidak ditemukan.');
        }
        db_exec('UPDATE users SET two_factor_secret = ? WHERE id_user = ?', [$secret, tostr($user['id_user'])]);
        current_user_invalidate(tostr($username));
        log_activity(tostr($username), 'Setup 2FA', 'Rahasia TOTP dibuat, menunggu konfirmasi kode.');
        return [
            'success'      => true,
            'secret'       => $secret,
            'provisioning' => totp_provisioning_uri($secret, tostr($user['username'])),
            'message'      => 'Pindai QR/URI di aplikasi Authenticator, lalu konfirmasi dengan kode 6 digit.',
        ];
    } catch (Throwable $e) {
        return err('Gagal menyiapkan 2FA: ' . client_error_message($e->getMessage()));
    }
}

/** Konfirmasi pendaftaran 2FA dengan kode dari aplikasi Authenticator. */
function confirm_two_factor($username, $token, $code): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }
    $user = current_user(tostr($username), true);
    $secret = trim(tostr($user['two_factor_secret'] ?? ''));
    if ($secret === '' || !totp_verify($secret, tostr($code))) {
        return err('Kode TOTP tidak valid.');
    }
    log_activity(tostr($username), 'Aktifkan 2FA', 'Autentikasi dua faktor diaktifkan.');
    return ['success' => true, 'enabled' => true, 'message' => '2FA berhasil diaktifkan.'];
}

/** Selesaikan login 2FA setelah password benar (challenge dari login()). */
function verify_two_factor($username, $challenge, $code): array
{
    $username = strtolower(trim(tostr($username)));
    $challenge = strtoupper(trim(tostr($challenge)));
    $pending = $challenge !== '' ? cbt_cache_get('cbt.2fa.' . $challenge) : null;
    if (!is_array($pending) || strcasecmp((string) ($pending['username'] ?? ''), $username) !== 0
        || (time() - (int) ($pending['created'] ?? 0)) > 300) {
        return err('Tantangan 2FA tidak valid atau kedaluwarsa. Silakan login lagi.');
    }

    $user = current_user($username, true);
    $secret = trim(tostr($user['two_factor_secret'] ?? ''));
    if ($secret === '' || !totp_verify($secret, tostr($code))) {
        login_note_attempt($username, false);
        log_activity($username, 'Login 2FA Gagal', 'Kode TOTP salah.');
        return err('Kode autentikasi dua faktor salah.');
    }

    cbt_cache_delete('cbt.2fa.' . $challenge);
    [$sessionToken, $kickedCount] = auth_issue_session($user ?: [], '');
    return auth_login_response($user ?: [], $sessionToken, $kickedCount);
}

/** Nonaktifkan 2FA (membutuhkan sesi + kode TOTP). */
function disable_two_factor($username, $token, $code): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }
    $user = current_user(tostr($username), true);
    $secret = trim(tostr($user['two_factor_secret'] ?? ''));
    if ($secret === '' || !totp_verify($secret, tostr($code))) {
        return err('Kode TOTP tidak valid.');
    }
    db_exec('UPDATE users SET two_factor_secret = NULL WHERE id_user = ?', [tostr($user['id_user'])]);
    current_user_invalidate(tostr($username));
    log_activity(tostr($username), 'Nonaktifkan 2FA', 'Autentikasi dua faktor dinonaktifkan.');
    return ['success' => true, 'enabled' => false];
}

/** Keluarkan semua perangkat/token milik pengguna ini. */
function logout_all_devices($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }
    $name = strtolower(trim(tostr($username)));
    session_revoke_all($name);
    $user = current_user($name, true);
    if ($user) {
        db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($user['id_user'])]);
    }
    auth_clear_session_cookie();
    auth_clear_csrf_cookie();
    log_activity($name, 'Logout Semua Perangkat', 'Seluruh sesi aktif dicabut.');
    return ['success' => true, 'message' => 'Semua perangkat telah dikeluarkan.'];
}

/** Daftar perangkat/sesi aktif pengguna saat ini (tanpa token mentah). */
function get_active_sessions($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }
    $currentHash = session_token_hash(auth_request_token(tostr($token)));
    $rows = db_all(
        'SELECT id_session, created_at, last_seen_at, expires_at, ip, user_agent, token_hash
         FROM sessions WHERE username = ? AND revoked_at IS NULL AND expires_at > ?
         ORDER BY last_seen_at DESC',
        [strtolower(trim(tostr($username))), now_dt()]
    );
    $sessions = [];
    foreach ($rows as $row) {
        $sessions[] = [
            'id_session'   => tostr($row['id_session']),
            'created_at'   => tostr($row['created_at']),
            'last_seen_at' => tostr($row['last_seen_at']),
            'expires_at'   => tostr($row['expires_at']),
            'ip'           => tostr($row['ip']),
            'user_agent'   => tostr($row['user_agent']),
            'is_current'   => hash_equals(tostr($row['token_hash']), $currentHash),
        ];
    }
    return ['success' => true, 'sessions' => $sessions, 'total' => count($sessions)];
}

/**
 * Logout: hapus token perangkat ini saja (padanan logout()).
 */
function logout($username, $token): array
{
    try {
        session_revoke_token(auth_request_token(tostr($token)));

        // Bersihkan juga token lama pada kolom kompatibilitas.
        $user = find_user(tostr($username));
        if ($user) {
            $tokens = parse_session_tokens($user['token_session'] ?? '');
            $remaining = array_values(array_filter($tokens, static fn ($t) => $t !== tostr($token)));
            db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $remaining), $user['id_user']]);
        }
        log_activity(tostr($username) !== '' ? tostr($username) : 'User', 'Logout', 'Pengguna telah keluar dari sistem.');
        auth_clear_session_cookie();
        auth_clear_csrf_cookie();
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('Logout error: ' . $e->getMessage());
        return err('Gagal keluar dari sistem.');
    }
}

/**
 * Alias endpoint getSessionStatus (nama action dari klien Apps Script).
 */
function getSessionStatus($username, $token): array
{
    return get_session_status($username, $token);
}

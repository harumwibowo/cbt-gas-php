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
    $token = tostr($token);
    if ($username === '' || $token === '') {
        return false;
    }

    try {
        $user = find_user($username);
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
        return ['success' => true, 'valid' => validate_session($username, $token)];
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
    $user = find_user($username);
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

        $maxSessions = $isStudent ? get_max_student_sessions() : 3;
        $oldSessionCount = session_count_active((string) $user['username']);
        $sessionToken = new_session_token();

        // Sisakan ruang untuk sesi baru, lalu buat sesi.
        session_trim_to((string) $user['username'], max(0, $maxSessions - 1));
        session_create(strtolower((string) $user['username']), $sessionToken, auth_client_ip(), auth_user_agent());
        // Token mentah gaya lama tidak disimpan lagi.
        if (tostr($user['token_session'] ?? '') !== '') {
            db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($user['id_user'])]);
        }

        $kickedCount = max(0, $oldSessionCount - max(0, $maxSessions - 1));

        login_note_attempt($username, true);
        if (random_int(1, 20) === 1) {
            session_cleanup();
        }

        $detected = detect_user_agent_browser(auth_user_agent() !== '' ? auth_user_agent() : tostr($userAgent));
        log_activity(
            tostr($user['username']),
            'Login',
            'Login berhasil dari browser/perangkat' . ($detected !== '' ? ' (' . strtoupper($detected) . ')' : '') . '.'
            . ($kickedCount > 0 ? ' ' . $kickedCount . ' sesi login lama dikeluarkan (batas maksimal ' . $maxSessions . ' sesi).' : '')
        );

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
        ];
    } catch (Throwable $e) {
        error_log('Login error: ' . $e->getMessage());
        return err('Kesalahan server saat login. Coba lagi beberapa saat lagi.');
    }
}

/**
 * Logout: hapus token perangkat ini saja (padanan logout()).
 */
function logout($username, $token): array
{
    try {
        session_revoke_token(tostr($token));

        // Bersihkan juga token lama pada kolom kompatibilitas.
        $user = find_user(tostr($username));
        if ($user) {
            $tokens = parse_session_tokens($user['token_session'] ?? '');
            $remaining = array_values(array_filter($tokens, static fn ($t) => $t !== tostr($token)));
            db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $remaining), $user['id_user']]);
        }
        log_activity(tostr($username) !== '' ? tostr($username) : 'User', 'Logout', 'Pengguna telah keluar dari sistem.');
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

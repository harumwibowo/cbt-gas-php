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
    return array_merge(['success' => false, 'message' => $message], $extra);
}

/** Ambil satu baris user berdasarkan username (case-insensitive). */
function find_user(string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return null;
    }
    return db_one('SELECT * FROM users WHERE LOWER(TRIM(username)) = ? LIMIT 1', [$username]);
}

/**
 * Validasi session token (padanan validateSession()).
 */
function validate_session($username, $token): bool
{
    $username = tostr($username);
    $token = tostr($token);
    if ($username === '' || $token === '') {
        return false;
    }

    try {
        $user = find_user($username);
        if (!$user) {
            return false;
        }
        $tokens = parse_session_tokens($user['token_session'] ?? '');
        return in_array($token, $tokens, true);
    } catch (Throwable $e) {
        error_log('Error validateSession: ' . $e->getMessage());
    }
    return false;
}

/** Status sesi untuk heartbeat klien (fail-open bila terjadi kesalahan). */
function get_session_status($username, $token): array
{
    try {
        return ['success' => true, 'valid' => validate_session($username, $token)];
    } catch (Throwable $e) {
        return ['success' => false, 'valid' => true, 'message' => $e->getMessage()];
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

        $user = find_user($username);
        if (!$user) {
            return err('Username tidak ditemukan.');
        }
        if (tostr($user['password']) !== $password) {
            return err('Password salah. Silakan periksa kembali.');
        }

        $status = strtolower(tostr($user['status_aktif']));
        if ($status !== '' && $status !== 'aktif') {
            return err('Akun Anda berstatus non-aktif. Hubungi Admin.');
        }

        $isStudent = strtolower(trim(tostr($user['role']))) === 'siswa';

        // Pembatasan browser login (hanya siswa)
        if ($isStudent) {
            $allowedBrowsers = get_allowed_student_browsers();
            if (!is_student_browser_allowed(tostr($userAgent), $allowedBrowsers)) {
                $detectedBrowser = detect_user_agent_browser(tostr($userAgent));
                $detectedLabel = $detectedBrowser !== '' ? strtoupper($detectedBrowser) : 'Tidak Dikenali';
                log_activity(
                    tostr($user['username']),
                    'Login Ditolak',
                    'Browser tidak diizinkan (' . $detectedLabel . '). Browser diizinkan: ' . $allowedBrowsers
                );
                return err(
                    'Login ditolak. Browser yang Anda gunakan (' . $detectedLabel . ') tidak termasuk browser yang diizinkan.'
                    . ' Browser yang diizinkan: ' . $allowedBrowsers . '. Silakan hubungi pengawas/guru.'
                );
            }
        }

        $maxSessions = $isStudent ? get_max_student_sessions() : 1;
        $oldTokens = parse_session_tokens($user['token_session'] ?? '');
        $sessionToken = new_session_token();
        $newTokenList = build_session_token_list($oldTokens, $sessionToken, $maxSessions);

        db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $newTokenList), $user['id_user']]);

        $kickedCount = 0;
        foreach ($oldTokens as $oldToken) {
            if (!in_array($oldToken, $newTokenList, true)) {
                $kickedCount++;
            }
        }

        $detected = detect_user_agent_browser(tostr($userAgent));
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
        return err('Kesalahan server saat login: ' . $e->getMessage());
    }
}

/**
 * Logout: hapus token perangkat ini saja (padanan logout()).
 */
function logout($username, $token): array
{
    try {
        $user = find_user(tostr($username));
        if ($user) {
            $tokens = parse_session_tokens($user['token_session'] ?? '');
            $remaining = array_values(array_filter($tokens, static fn ($t) => $t !== tostr($token)));
            db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $remaining), $user['id_user']]);
        }
        log_activity(tostr($username) !== '' ? tostr($username) : 'User', 'Logout', 'Pengguna telah keluar dari sistem.');
        return ['success' => true];
    } catch (Throwable $e) {
        return err($e->getMessage());
    }
}

/**
 * Alias endpoint getSessionStatus (nama action dari klien Apps Script).
 */
function getSessionStatus($username, $token): array
{
    return get_session_status($username, $token);
}

<?php
/**
 * Kelola 2FA/TOTP lewat CLI (Bagian 3.3).
 *
 *   php tools/2fa.php status admin
 *   php tools/2fa.php enable admin     -> rahasia Base32 + URI otpauth
 *   php tools/2fa.php code admin       -> kode TOTP saat ini (uji aplikasi)
 *   php tools/2fa.php disable admin
 *
 * Untuk pengguna non-CLI, endpoint setup2fa/confirm2fa/verify2fa/disable2fa
 * tersedia lewat api.php (lihat README).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya CLI.\n");
}

require_once dirname(__DIR__) . '/config/config.php';

$cmd = strtolower(trim((string) ($argv[1] ?? 'status')));
$username = strtolower(trim((string) ($argv[2] ?? '')));

if ($username === '') {
    fwrite(STDERR, "Pemakaian: php tools/2fa.php <status|enable|code|disable> <username>\n");
    exit(1);
}

$user = find_user($username);
if (!$user) {
    fwrite(STDERR, "User tidak ditemukan: {$username}\n");
    exit(1);
}
$role = strtolower(trim(tostr($user['role'])));
if (!in_array($role, ['admin', 'guru'], true)) {
    fwrite(STDERR, "2FA hanya untuk Admin/Guru.\n");
    exit(1);
}

$secret = trim(tostr($user['two_factor_secret'] ?? ''));

switch ($cmd) {
    case 'status':
        echo '2FA ' . $username . ': ' . ($secret !== '' ? 'AKTIF' : 'nonaktif') . "\n";
        break;
    case 'enable':
        $secret = totp_new_secret();
        db_exec('UPDATE users SET two_factor_secret = ? WHERE id_user = ?', [$secret, tostr($user['id_user'])]);
        echo "Rahasia Base32 : {$secret}\n";
        echo 'URI otpauth   : ' . totp_provisioning_uri($secret, $username) . "\n";
        echo "Kode saat ini : " . totp_code($secret) . "\n";
        echo "Simpan rahasia di aplikasi Authenticator, lalu verifikasi lewat verify2fa.\n";
        break;
    case 'code':
        if ($secret === '') {
            fwrite(STDERR, "2FA belum aktif untuk {$username}.\n");
            exit(1);
        }
        echo totp_code($secret) . "\n";
        break;
    case 'disable':
        db_exec('UPDATE users SET two_factor_secret = NULL WHERE id_user = ?', [tostr($user['id_user'])]);
        echo "2FA dinonaktifkan untuk {$username}.\n";
        break;
    default:
        fwrite(STDERR, "Perintah tidak dikenal: {$cmd}\n");
        exit(1);
}

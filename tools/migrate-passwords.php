<?php
/**
 * Utilitas CLI: migrasi & pemulihan password.
 *
 *   php tools/migrate-passwords.php                 # ubah semua password plain text → hash
 *   php tools/migrate-passwords.php --dry-run       # hanya laporkan, tanpa mengubah
 *   php tools/migrate-passwords.php --reset admin   # set password baru untuk 1 akun
 *
 * Jalankan dari root proyek. Aman dijalankan berulang.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya dapat dijalankan dari CLI.\n");
}

require_once __DIR__ . '/../config/config.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);

$resetUser = null;
$resetPass = null;
$resetIdx = array_search('--reset', $args, true);
if ($resetIdx !== false && isset($args[$resetIdx + 1])) {
    $resetUser = $args[$resetIdx + 1];
    $resetPass = $args[$resetIdx + 2] ?? null;
}

echo "=== CBT password tool ===\n";

try {
    ensure_schema();
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal menyiapkan skema: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- reset manual
if ($resetUser !== null) {
    $user = db_one('SELECT * FROM users WHERE LOWER(TRIM(username)) = ?', [strtolower(trim($resetUser))]);
    if (!$user) {
        fwrite(STDERR, "Pengguna '{$resetUser}' tidak ditemukan.\n");
        exit(1);
    }
    if ($resetPass === null) {
        $resetPass = 'admin123';
    }
    if (strlen($resetPass) < 5) {
        fwrite(STDERR, "Password minimal 5 karakter.\n");
        exit(1);
    }
    [$hash, $enc] = password_fields($resetPass);
    db_exec('UPDATE users SET password = ?, password_enc = ?, token_session = NULL WHERE id_user = ?',
        [$hash, $enc, tostr($user['id_user'])]);
    echo "Password '{$resetUser}' disetel ulang dan semua sesinya diakhiri.\n";
    exit(0);
}

// ---------------------------------------------------------------- migrasi massal
$rows = db_all('SELECT id_user, username, password, password_enc FROM users ORDER BY username');
$converted = 0;
$already = 0;
$noEnc = 0;

foreach ($rows as $row) {
    $stored = tostr($row['password']);
    $username = tostr($row['username']);
    $id = tostr($row['id_user']);

    if ($stored === '') {
        continue;
    }

    if (is_password_hash($stored)) {
        $already++;
        // Lengkapi salinan terenkripsi bila belum ada (diperlukan kartu ujian).
        if (tostr($row['password_enc']) === '') {
            $noEnc++;
        }
        continue;
    }

    if ($dryRun) {
        echo "  [dry-run] {$username}: plain text → hash\n";
        $converted++;
        continue;
    }

    [$hash, $enc] = password_fields($stored);
    db_exec('UPDATE users SET password = ?, password_enc = ? WHERE id_user = ?', [$hash, $enc, $id]);
    echo "  {$username}: plain text → hash\n";
    $converted++;
}

echo "\nRingkasan: {$converted} dikonversi, {$already} sudah hash";
echo $dryRun ? " (dry-run, tidak ada perubahan)\n" : "\n";
if ($noEnc > 0) {
    printf(
        "%d akun sudah memakai hash tetapi belum punya salinan terenkripsi; password kartu ujiannya\n"
        . "tidak dapat ditampilkan sampai password diubah (atau set ulang: --reset <username> <password>).\n",
        $noEnc
    );
}
echo "Selesai.\n";

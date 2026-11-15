<?php
/**
 * Pembaruan aplikasi langsung dari repositori GitHub.
 *
 * Alur: unduh arsip tar.gz branch utama → bandingkan versi → cadangkan berkas
 * yang akan ditimpa → salin → periksa sintaks PHP → (gagal = kembalikan cadangan).
 *
 * Berkas yang TIDAK pernah ditimpa: config/database.json, config/pass.key,
 * config/installed.json, storage/ (cadangan), .git/.
 */
declare(strict_types=1);

const UPDATE_REPO_SLUG    = 'harumwibowo/cbt-gas-php';
const UPDATE_BRANCH       = 'main';
const UPDATE_BACKUP_DIR   = 'storage/backups';
const UPDATE_TIMEOUT      = 60;

/** Berkas token GitHub (untuk repositori privat) — tidak pernah dikirim ke klien. */
function update_token_path(): string
{
    return APP_ROOT . '/config/update_token.txt';
}

/** Token GitHub opsional: environment CBT_UPDATE_TOKEN atau config/update_token.txt. */
function update_token(): string
{
    $env = getenv('CBT_UPDATE_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $file = update_token_path();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }
    }
    return '';
}

/** Simpan/hapus token GitHub (hanya untuk Admin, lihat setUpdateToken). */
function update_token_store(string $token): bool
{
    $token = trim($token);
    $file = update_token_path();
    if ($token === '') {
        if (is_file($file)) {
            return @unlink($file);
        }
        return true;
    }
    if (@file_put_contents($file, $token . "
") === false) {
        return false;
    }
    @chmod($file, 0600);
    return true;
}

/** Header HTTP untuk unduhan/API (menyertakan token bila ada). */
function update_http_headers(): array
{
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: cbt-gas-php-updater'];
    $token = update_token();
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    return $headers;
}

/**
 * URL arsip sumber pembaruan.
 * Repo publik: codeload. Repo privat (token tersedia): endpoint API tarball.
 * CBT_UPDATE_URL menimpa keduanya (dipakai untuk uji/mirror internal).
 */
function update_archive_url(?string $ref = null): string
{
    $override = getenv('CBT_UPDATE_URL');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }
    if ($ref !== null && $ref !== '') {
        return 'https://codeload.github.com/' . UPDATE_REPO_SLUG . '/tar.gz/' . $ref;
    }
    if (update_token() !== '') {
        return 'https://api.github.com/repos/' . UPDATE_REPO_SLUG . '/tarball/' . UPDATE_BRANCH;
    }
    return 'https://codeload.github.com/' . UPDATE_REPO_SLUG . '/tar.gz/refs/heads/' . UPDATE_BRANCH;
}

/** Folder kerja pembaruan sementara (dibuat & dibersihkan per pemanggilan). */
function update_temp_dir(): string
{
    $base = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cbt-update-' . bin2hex(random_bytes(4));
    if (!@mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException('Tidak dapat membuat folder sementara di ' . sys_get_temp_dir());
    }
    return $base;
}

/** Hapus folder rekursif. */
function update_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            update_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** Unduh URL ke berkas tujuan (butuh ekstensi curl atau allow_url_fopen). */
function update_download(string $url, string $destination): void
{
    if (function_exists('curl_init')) {
        $fp = @fopen($destination, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Tidak dapat menulis berkas unduhan: ' . $destination);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => UPDATE_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'cbt-gas-php-updater',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => update_http_headers(),
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        // Status 0 = protokol non-HTTP (mis. file:// untuk uji/mirror internal).
        if ($ok === false || ($status !== 200 && $status !== 0)) {
            $hint = ($status === 404 || $status === 401 || $status === 403)
                ? ' Repositori bersifat privat atau token GitHub belum diisi: isi token pada kartu Pembaruan Aplikasi.'
                : '';
            throw new RuntimeException('Unduhan gagal (HTTP ' . $status . ').' . $hint . ' ' . $err);
        }
        return;
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => UPDATE_TIMEOUT, 'user_agent' => 'cbt-gas-php-updater', 'follow_location' => 1],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Unduhan gagal dan ekstensi curl tidak tersedia.');
    }
    if (@file_put_contents($destination, $data) === false) {
        throw new RuntimeException('Tidak dapat menulis berkas unduhan: ' . $destination);
    }
}

/** Info commit terakhir dari API GitHub (best-effort; null bila gagal/limit). */
function update_remote_commit(): ?array
{
    $url = 'https://api.github.com/repos/' . UPDATE_REPO_SLUG . '/commits/' . UPDATE_BRANCH;
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'cbt-gas-php-updater',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => update_http_headers(),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) {
            return null;
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || empty($json['sha'])) {
            return null;
        }
        return [
            'sha'     => (string) $json['sha'],
            'short'   => substr((string) $json['sha'], 0, 7),
            'date'    => (string) ($json['commit']['committer']['date'] ?? ''),
            'message' => trim((string) strtok((string) ($json['commit']['message'] ?? ''), "\n")),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Baca satu nilai pengaturan ('' bila tidak ada). */
function update_setting_get(string $key): string
{
    $map = settings_map();
    return tostr($map[strtolower(trim($key))] ?? '');
}

/** Tulis beberapa nilai pengaturan sekaligus (INSERT/UPDATE per kunci). */
function update_setting_put(array $pairs): void
{
    foreach ($pairs as $key => $value) {
        $kunci = strtolower(trim((string) $key));
        if ($kunci === '') {
            continue;
        }
        $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE LOWER(kunci) = ?', [$kunci], 0);
        if ($exists > 0) {
            db_exec('UPDATE settings SET nilai = ? WHERE LOWER(kunci) = ?', [tostr($value), $kunci]);
        } else {
            db_exec('INSERT INTO settings (kunci, nilai, keterangan) VALUES (?,?,?)',
                [$kunci, tostr($value), 'Pembaruan aplikasi']);
        }
    }
}

/**
 * Kunci pengaturan versi dipisah per repositori.
 *
 * Dua instalasi CBT (mis. versi native dan versi Laravel) dapat memakai satu database
 * yang sama; tanpa pemisahan ini status versi keduanya saling menimpa.
 */
function update_version_key(string $suffix): string
{
    $slug = strtolower(trim((string) UPDATE_REPO_SLUG));

    return $slug === '' ? $suffix : $suffix . ':' . $slug;
}

/** Versi terpasang (dari tabel settings; kosong bila belum pernah diperbarui). */
function update_installed_version(): array
{
    $version = update_setting_get(update_version_key('app_version'));
    $updatedAt = update_setting_get(update_version_key('app_updated_at'));
    $source = update_setting_get(update_version_key('app_update_source'));
    if ($version === '') {
        // Kompatibilitas dengan kunci lama sebelum pemisahan per repositori.
        $version = update_setting_get('app_version');
        $updatedAt = update_setting_get('app_updated_at');
        $source = update_setting_get('app_update_source');
    }
    return [
        'version'    => $version,
        'updated_at' => $updatedAt,
        'source'     => $source,
        'short'      => $version !== '' ? substr($version, 0, 7) : '',
    ];
}

/** Simpan versi baru ke settings. */
function update_store_version(string $version, string $source): void
{
    update_setting_put([
        update_version_key('app_version')       => $version,
        update_version_key('app_updated_at')    => now_dt(),
        update_version_key('app_update_source') => $source,
    ]);
}

/** Daftar berkas relatif yang boleh/tidak boleh ditimpa. */
function update_should_copy(string $relativePath): bool
{
    $rel = str_replace('\\', '/', $relativePath);
    $always_skip = [
        'config/database.json',
        'config/pass.key',
        'config/installed.json',
    ];
    if (in_array($rel, $always_skip, true)) {
        return false;
    }
    if (str_starts_with($rel, 'storage/') || str_starts_with($rel, '.git/') || $rel === '.git') {
        return false;
    }
    if ($rel === '.htaccess' || str_ends_with($rel, '/.htaccess')) {
        return true;
    }
    // Hanya berkas aplikasi yang di-deploy.
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, ['php', 'html', 'js', 'css', 'sql', 'md', 'json', 'txt', 'gitattributes', 'gitignore'], true)
        || in_array(basename($rel), ['.gitignore', '.gitattributes'], true);
}

/**
 * Terapkan pembaruan.
 *
 * @param bool $force Paksa salin walau versi sama (mis. untuk memperbaiki berkas rusak).
 * @return array{success:bool,message:string,...}
 */
function update_apply(string $username, string $token, bool $force = false): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    if (current_role($username) !== 'admin') {
        return err('Akses ditolak. Hanya Admin yang dapat memperbarui aplikasi.');
    }

    $root = APP_ROOT;
    $tmp = '';
    $backupDir = '';

    try {
        $tmp = update_temp_dir();

        // ------------------------------------------- 1. cek versi lalu unduh
        $remote = update_remote_commit();
        $installed = update_installed_version();
        $remoteSha = is_array($remote) && isset($remote['sha']) ? tostr($remote['sha']) : '';

        $url = update_archive_url($remoteSha !== '' ? $remoteSha : null);
        $archive = $tmp . '/source.tar.gz';
        update_download($url, $archive);

        $payloadHash = hash_file('sha256', $archive) ?: '';
        $newVersion = $remoteSha !== '' ? $remoteSha : $payloadHash;

        if (!$force && $installed['version'] !== '' && $installed['version'] === $newVersion) {
            update_rmdir($tmp);
            return [
                'success'         => true,
                'up_to_date'      => true,
                'message'         => 'Aplikasi sudah versi terbaru (' . substr($newVersion, 0, 7) . '). Tidak ada perubahan.',
                'current_version' => $installed['version'],
                'latest_version'  => $newVersion,
            ];
        }

        // ---------------------------------------------------------- 2. ekstrak
        $extractDir = $tmp . '/src';
        @mkdir($extractDir, 0700, true);
        try {
            $phar = new PharData($archive);
            $phar->extractTo($extractDir, null, true);
        } catch (Throwable $e) {
            throw new RuntimeException('Gagal mengekstrak arsip pembaruan: ' . $e->getMessage());
        }

        $entries = @scandir($extractDir) ?: [];
        $sourceRoot = '';
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($extractDir . '/' . $entry)) {
                $sourceRoot = $extractDir . '/' . $entry;
                break;
            }
        }
        if ($sourceRoot === '' || !is_file($sourceRoot . '/index.php')) {
            throw new RuntimeException('Isi arsip tidak dikenali (index.php tidak ditemukan).');
        }

        // ------------------------------------------------- 3. daftar berkas baru
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceRoot) + 1));
            if (!update_should_copy($relative)) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        if (!isset($files['index.php']) || !isset($files['api.php'])) {
            throw new RuntimeException('Arsip pembaruan tidak lengkap (index.php/api.php tidak ada).');
        }

        // -------------------------------------------- 4. cek folder dapat ditulis
        $notWritable = [];
        foreach (array_keys($files) as $relative) {
            $target = $root . '/' . $relative;
            $dir = dirname($target);
            if (is_file($target) ? !is_writable($target) : !is_writable($dir)) {
                $notWritable[] = $relative;
            }
        }
        if ($notWritable !== []) {
            throw new RuntimeException(
                'Berkas berikut tidak dapat ditulis web server: ' . implode(', ', array_slice($notWritable, 0, 5))
                . (count($notWritable) > 5 ? '…' : '') . '. Perbaiki izin berkas lalu coba lagi.'
            );
        }

        // ------------------------------------------------------- 5. cadangkan
        $backupDir = $root . '/' . UPDATE_BACKUP_DIR . '/update-' . date('Ymd-His');
        if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Tidak dapat membuat folder cadangan di ' . UPDATE_BACKUP_DIR);
        }
        $backedUp = 0;
        foreach (array_keys($files) as $relative) {
            $target = $root . '/' . $relative;
            if (!is_file($target)) {
                continue;
            }
            $copyTo = $backupDir . '/' . $relative;
            if (!is_dir(dirname($copyTo))) {
                @mkdir(dirname($copyTo), 0700, true);
            }
            if (@copy($target, $copyTo)) {
                $backedUp++;
            }
        }

        // ------------------------------------------------------------- 6. salin
        $written = [];
        $added = [];
        foreach ($files as $relative => $source) {
            $target = $root . '/' . $relative;
            $isNew = !is_file($target);
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (!@copy($source, $target)) {
                update_rmdir($tmp);
                return [
                    'success'  => false,
                    'message'  => 'Gagal menulis ' . $relative . '. Sebagian berkas sudah diperbarui; '
                        . 'cadangan tersedia di ' . UPDATE_BACKUP_DIR . '/' . basename($backupDir) . '.',
                    'backup'   => UPDATE_BACKUP_DIR . '/' . basename($backupDir),
                ];
            }
            $written[] = $relative;
            if ($isNew) {
                $added[] = $relative;
            }
        }

        // --------------------------------------------------- 7. periksa sintaks
        $lintFailures = update_lint_files($written);
        if ($lintFailures !== []) {
            update_rollback($backupDir, $written, $added);
            update_rmdir($tmp);
            return [
                'success'  => false,
                'message'  => 'Pembaruan dibatalkan: berkas baru gagal diperiksa sintaksnya ('
                    . implode(', ', array_slice($lintFailures, 0, 3)) . '). Seluruh berkas dikembalikan ke versi sebelumnya.',
                'rollback' => true,
            ];
        }

        // ------------------------------------------------------------- 8. simpan
        update_store_version($newVersion, 'github');
        update_rmdir($tmp);

        log_activity(tostr($username), 'Update Aplikasi', 'Versi ' . substr($newVersion, 0, 7)
            . ' (' . count($written) . ' berkas)');

        return [
            'success'         => true,
            'up_to_date'      => false,
            'message'         => 'Aplikasi berhasil diperbarui ke versi ' . substr($newVersion, 0, 7) . '.',
            'current_version' => $newVersion,
            'previous'        => $installed['version'],
            'remote'          => $remote,
            'files_updated'   => count($written),
            'files_added'     => count($added),
            'backup'          => UPDATE_BACKUP_DIR . '/' . basename($backupDir),
            'backed_up'       => $backedUp,
        ];
    } catch (Throwable $e) {
        if ($tmp !== '' && is_dir($tmp)) {
            update_rmdir($tmp);
        }
        error_log('[CBT update] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Pembaruan gagal: ' . $e->getMessage()];
    }
}

/** Periksa sintaks berkas PHP yang baru ditulis (butuh exec/shell_exec). */
function update_lint_files(array $relativePaths): array
{
    // Diperiksa dengan parser PHP sendiri (token_get_all + TOKEN_PARSE) agar tidak
    // bergantung pada proses eksternal: PHP_BINARY bernilai httpd.exe saat mod_php.
    $failures = [];
    foreach ($relativePaths as $relative) {
        if (!str_ends_with(strtolower($relative), '.php')) {
            continue;
        }
        $source = @file_get_contents(APP_ROOT . '/' . $relative);
        if (!is_string($source)) {
            $failures[] = $relative;
            continue;
        }
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            $failures[] = $relative;
        }
    }

    return $failures;
}

/** Kembalikan berkas ke cadangan; berkas baru dihapus. */
function update_rollback(string $backupDir, array $written, array $added): void
{
    foreach ($written as $relative) {
        $target = APP_ROOT . '/' . $relative;
        $source = $backupDir . '/' . $relative;
        if (is_file($source)) {
            @copy($source, $target);
        }
    }
    foreach ($added as $relative) {
        $target = APP_ROOT . '/' . $relative;
        if (is_file($target)) {
            @unlink($target);
        }
    }
    error_log('[CBT update] rollback dijalankan, cadangan: ' . $backupDir);
}

// --------------------------------------------------------------------- RPC --

/** Info versi terpasang + versi terbaru di GitHub (tanpa mengubah apa pun). */
function getApplicationVersion($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    if (current_role($username) !== 'admin') {
        return err('Akses ditolak. Hanya Admin yang dapat melihat versi aplikasi.');
    }

    $installed = update_installed_version();
    $remote = update_remote_commit();
    $tokenSet = update_token() !== '';

    return [
        'success'         => true,
        'token_set'       => $tokenSet,
        'repo_private'    => $remote === null && !$tokenSet,
        'needs_token'     => $remote === null && !$tokenSet,
        'current_version' => $installed['version'],
        'current_short'   => $installed['short'],
        'updated_at'      => $installed['updated_at'],
        'latest_version'  => $remote['sha'] ?? '',
        'latest_short'    => $remote['short'] ?? '',
        'latest_date'     => $remote['date'] ?? '',
        'latest_message'  => $remote['message'] ?? '',
        'update_available' => $remote !== null && $installed['version'] !== $remote['sha'],
        'repo'            => 'https://github.com/' . UPDATE_REPO_SLUG,
    ];
}

/** Simpan token GitHub (untuk repositori privat). Admin saja. */
function setUpdateToken($username, $token, $githubToken): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    if (current_role($username) !== 'admin') {
        return err('Akses ditolak. Hanya Admin yang dapat mengubah token pembaruan.');
    }

    $value = trim(tostr($githubToken));
    if ($value !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,255}$/', $value)) {
        return err('Format token tidak valid. Gunakan Personal Access Token GitHub (huruf, angka, - dan _).');
    }
    if (!update_token_store($value)) {
        return err('Token tidak dapat disimpan. Pastikan folder config/ dapat ditulis web server.');
    }

    log_activity(tostr($username), 'Update Aplikasi', $value === '' ? 'Token GitHub dihapus' : 'Token GitHub diperbarui');

    return [
        'success'   => true,
        'token_set' => $value !== '',
        'message'   => $value === ''
            ? 'Token GitHub dihapus. Pembaruan hanya bisa mengambil repositori publik.'
            : 'Token GitHub tersimpan. Coba tombol Cek Versi sekarang.',
    ];
}

/** Tombol "Update" di halaman Pengaturan admin. */
function updateApplication($username, $token, $force = false): array
{
    return update_apply(tostr($username), tostr($token), (bool) $force);
}

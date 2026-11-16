<?php
/**
 * Pembaruan aplikasi langsung dari repositori GitHub (publik — tanpa token).
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

/** Header HTTP untuk unduhan/API (repositori publik: tanpa autentikasi). */
function update_http_headers(): array
{
    return ['Accept: application/vnd.github+json', 'User-Agent: cbt-gas-php-updater'];
}

/**
 * URL arsip sumber pembaruan (repositori publik → codeload).
 * CBT_UPDATE_URL menimpa (dipakai untuk uji/mirror internal).
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
                ? ' Repositori atau branch tidak ditemukan: pastikan repo tetap publik dan branch "'
                    . UPDATE_BRANCH . '" tersedia.'
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
/**
 * Status HTTP terakhir saat membaca API GitHub (0 = tidak terhubung).
 *
 * Dipakai membedakan "repositori tidak ditemukan/privat" (404) dari "kuota API habis"
 * (403/429) agar pesan pada kartu Pembaruan tidak menyesatkan.
 */
function update_api_status(?int $set = null): int
{
    static $status = 0;
    if ($set !== null) {
        $status = $set;
    }

    return $status;
}

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
        update_api_status($status);
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

/** Versi terpasang (dari tabel settings) + nama versi rilis (berkas VERSION). */
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

    // Nama versi rilis selalu dibaca dari berkas VERSION yang ikut diperbarui,
    // sehingga angkanya sama dengan versi terpasang terkini (bukan hash commit).
    $name = app_version_name();

    return [
        'version'    => $version,
        'updated_at' => $updatedAt,
        'source'     => $source,
        'short'      => $version !== '' ? substr($version, 0, 7) : '',
        'name'       => $name,
        'label'      => 'v' . $name,
    ];
}

/** Simpan versi baru ke settings. */
function update_store_version(string $version, string $source, string $name = ''): void
{
    $pairs = [
        update_version_key('app_version')       => $version,
        update_version_key('app_updated_at')    => now_dt(),
        update_version_key('app_update_source') => $source,
    ];
    if ($name !== '') {
        $pairs[update_version_key('app_version_name')] = $name;
    }
    update_setting_put($pairs);
}

/**
 * Nama versi rilis yang tersedia di GitHub (berkas VERSION di branch utama).
 * Best-effort: '' bila tidak dapat dihubungi.
 */
function update_remote_version_name(?string $ref = null): string
{
    static $cache = [];
    $key = (string) ($ref ?? '');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = '';

    if (trim((string) UPDATE_REPO_SLUG) === '') {
        return '';
    }

    $path = $ref !== null && $ref !== '' ? $ref : 'refs/heads/' . UPDATE_BRANCH;
    $url = 'https://raw.githubusercontent.com/' . UPDATE_REPO_SLUG . '/' . $path . '/VERSION';

    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cbt-ver-' . bin2hex(random_bytes(4)) . '.txt';
    try {
        update_download($url, $probe);
        $cache[$key] = version_name_from_file($probe);
    } catch (Throwable $e) {
        $cache[$key] = '';
    } finally {
        if (is_file($probe)) {
            @unlink($probe);
        }
    }

    return $cache[$key];
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
    $base = basename($rel);
    if ($base === 'VERSION') {
        // Berkas penanda versi rilis (tanpa ekstensi) wajib ikut diperbarui.
        return true;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, ['php', 'html', 'js', 'css', 'sql', 'md', 'json', 'txt', 'gitattributes', 'gitignore'], true)
        || in_array(basename($rel), ['.gitignore', '.gitattributes'], true);
}

/**
 * Bersihkan sisa token lama.
 *
 * Fitur token dihapus karena repositori kini publik; berkas token lama di
 * instalasi yang sudah diperbarui tidak boleh tertinggal di disk.
 */
function update_cleanup_legacy_token(): void
{
    $file = APP_ROOT . '/config/update_token.txt';
    if (is_file($file)) {
        @unlink($file);
        error_log('[CBT update] sisa token lama dihapus: config/update_token.txt');
    }
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

    update_cleanup_legacy_token();

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
                'message'         => 'Aplikasi sudah versi terbaru (' . $installed['label'] . '). Tidak ada perubahan.',
                'current_version' => $installed['version'],
                'current_name'    => $installed['name'],
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

        // Nama versi rilis dari arsip (berkas VERSION) — dipakai pada pesan hasil
        // dan disimpan ke settings sebagai riwayat versi terpasang.
        $newName = version_name_from_file($sourceRoot . '/VERSION');
        $newLabel = $newName !== '' ? 'v' . $newName : substr($newVersion, 0, 7);

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
        update_store_version($newVersion, $remoteSha !== '' ? 'github' : 'archive', $newName);
        update_rmdir($tmp);

        log_activity(tostr($username), 'Update Aplikasi', 'Versi ' . $newLabel
            . ' (' . count($written) . ' berkas)');

        return [
            'success'         => true,
            'up_to_date'      => false,
            'message'         => 'Aplikasi berhasil diperbarui ke versi ' . $newLabel . '.',
            'current_version' => $newVersion,
            'current_name'    => $newName,
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
    $repoConfigured = trim((string) UPDATE_REPO_SLUG) !== '';
    $remote = update_remote_commit();
    $apiStatus = update_api_status();
    $remoteSha = is_string($remote['sha'] ?? null) ? (string) $remote['sha'] : '';

    // Nama versi rilis terbaru di GitHub (berkas VERSION). Kosong bila gagal.
    $latestName = $remoteSha !== '' ? update_remote_version_name($remoteSha) : '';
    if ($latestName === '' && $remoteSha !== '') {
        $latestName = update_remote_version_name();
    }

    $nameChanged = $latestName !== '' && $installed['name'] !== '' && $latestName !== $installed['name'];

    return [
        'success'         => true,
        'api_status'      => $apiStatus,
        'repo_configured' => $repoConfigured,
        'current_version' => $installed['version'],
        'current_short'   => $installed['short'],
        'current_name'    => $installed['name'],
        'current_label'   => $installed['label'],
        'updated_at'      => $installed['updated_at'],
        'latest_version'  => $remoteSha,
        'latest_short'    => $remote['short'] ?? '',
        'latest_name'     => $latestName,
        'latest_label'    => $latestName !== '' ? ('v' . $latestName) : '',
        'latest_date'     => $remote['date'] ?? '',
        'latest_message'  => $remote['message'] ?? '',
        // Versi baru = nama rilis berbeda; bila nama sama namun commit berbeda,
        // berkas tetap diperbarui (pembaruan tanpa naik nomor versi).
        'version_changed'  => $nameChanged,
        'commit_changed'   => $remote !== null && $installed['version'] !== $remoteSha,
        'update_available' => $remote !== null && $installed['version'] !== $remoteSha,
        'repo'            => 'https://github.com/' . UPDATE_REPO_SLUG,
    ];
}

/** Tombol "Update" di halaman Pengaturan admin. */
function updateApplication($username, $token, $force = false): array
{
    return update_apply(tostr($username), tostr($token), (bool) $force);
}

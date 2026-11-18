<?php
/**
 * Halaman setup instalasi baru.
 *
 * Menentukan apakah aplikasi sudah terpasang, menguji koneksi database yang
 * dimasukkan pengguna, lalu menyimpan kredensial ke config/database.json
 * (tidak perlu mengedit berkas PHP apa pun).
 */
declare(strict_types=1);

/** Lokasi berkas kredensial hasil setup. */
function setup_config_path(): string
{
    return CBT_DB_CONFIG_FILE;
}

/** Baca kredensial tersimpan (array kosong bila belum ada). */
function read_setup_config(): array
{
    if (!is_file(setup_config_path())) {
        return [];
    }
    $raw = @file_get_contents(setup_config_path());
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

/** Simpan kredensial ke config/database.json (0600 bila memungkinkan). */
function write_setup_config(array $config): bool
{
    $payload = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $path = setup_config_path();

    if (is_file($path) && !is_writable($path)) {
        return false;
    }
    // Tulis ke berkas sementara lalu rename atomik: config lama tidak pernah
    // tertinggal setengah tertulis bila proses berhenti.
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $written = @file_put_contents($tmp, $payload);
    if ($written === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    return true;
}

/** Data kredensial dari input pengguna (dengan validasi & default). */
function setup_normalize_input(array $input): array
{
    $host = trim(tostr($input['host'] ?? ''));
    $name = trim(tostr($input['name'] ?? ''));
    $user = trim(tostr($input['user'] ?? ''));
    $port = (int) tostr($input['port'] ?? 3306);

    return [
        'host'      => $host !== '' ? $host : '127.0.0.1',
        'port'      => ($port >= 1 && $port <= 65535) ? $port : 3306,
        'name'      => $name !== '' ? $name : 'cbt_gas',
        'user'      => $user !== '' ? $user : 'root',
        'pass'      => tostr($input['pass'] ?? ''),
        'installed' => tostr($input['installed'] ?? '') !== '' ? tostr($input['installed']) : date('c'),
        'version'   => 1,
    ];
}

/** Validasi nama database (hindari backtick/injeksi). */
function setup_valid_db_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
}

/** PDO ke server MySQL tanpa memilih database. */
function setup_server_pdo(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']);
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
}

/**
 * Uji kredensial tanpa mengubah apa pun (kecuali $createDb = true).
 *
 * @return array{ok:bool,message:string,version?:string,db_exists?:bool,created?:bool,tables?:int}
 */
function setup_db_test(array $c, bool $createDb = false): array
{
    if (!setup_valid_db_name((string) $c['name'])) {
        return ['ok' => false, 'message' => 'Nama database hanya boleh huruf, angka, dan garis bawah.'];
    }

    try {
        $server = setup_server_pdo($c);
        $version = tostr($server->query('SELECT VERSION()')->fetchColumn());

        $existsStmt = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $existsStmt->execute([$c['name']]);
        $dbExists = ((int) $existsStmt->fetchColumn()) > 0;
        $created = false;

        if (!$dbExists) {
            if (!$createDb) {
                return [
                    'ok'        => false,
                    'message'   => 'Database "' . $c['name'] . '" belum ada. Buat dulu di phpMyAdmin, atau centang "Buat database jika belum ada".',
                    'version'   => $version,
                    'db_exists' => false,
                ];
            }
            $server->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $dbExists = true;
            $created = true;
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        $tableCount = count($pdo->query('SHOW TABLES')->fetchAll());

        return [
            'ok'        => true,
            'message'   => 'Koneksi database berhasil.',
            'version'   => $version,
            'db_exists' => $dbExists,
            'created'   => $created,
            'tables'    => $tableCount,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Gagal terhubung ke MySQL: ' . $e->getMessage()];
    }
}

/**
 * Pasang aplikasi: simpan kredensial lalu buat tabel + data awal memakai
 * koneksi yang baru dimasukkan pengguna.
 */
function setup_run(array $input, bool $createDb = true): array
{
    $config = setup_normalize_input($input);

    $test = setup_db_test($config, $createDb);
    if (!$test['ok']) {
        return ['success' => false, 'message' => $test['message']];
    }

    // Simpan kredensial lebih dulu agar request berikutnya memakai database ini.
    if (!write_setup_config($config)) {
        return [
            'success' => false,
            'message' => 'Kredensial tidak dapat disimpan. Pastikan folder config/ dapat ditulis web server, '
                . 'atau isi manual config/database.json.',
        ];
    }

    try {
        // Pakai koneksi baru untuk proses instalasi pada request ini.
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $GLOBALS['CBT_PDO'] = $pdo;

        ensure_schema();

        $tableCount = count($pdo->query('SHOW TABLES')->fetchAll());
        $adminCount = (int) db_val("SELECT COUNT(*) FROM users WHERE LOWER(TRIM(role)) = 'admin'", [], 0);
        $userCount = (int) db_val('SELECT COUNT(*) FROM users', [], 0);
        $settingsCount = (int) db_val('SELECT COUNT(*) FROM settings', [], 0);

        if ($adminCount === 0) {
            return ['success' => false, 'message' => 'Instalasi gagal: akun admin tidak terbentuk. Periksa izin database.'];
        }

        @file_put_contents(
            APP_ROOT . '/config/installed.json',
            json_encode([
                'installed_at' => date('c'),
                'php'          => PHP_VERSION,
                'database'     => $config['name'],
                'host'         => $config['host'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return [
            'success'  => true,
            'message'  => 'Instalasi berhasil! Silakan login dengan admin / admin123.',
            'tables'   => $tableCount,
            'users'    => $userCount,
            'settings' => $settingsCount,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Instalasi gagal: ' . $e->getMessage()];
    }
}

/**
 * True bila aplikasi belum siap dipakai:
 *  - kredensial belum pernah dikonfigurasi (tidak ada database.json & tidak ada env), atau
 *  - database tidak dapat dihubungi, atau
 *  - belum ada akun Admin.
 */
function setup_needs_wizard(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $hasConfigFile = is_file(setup_config_path());
    if (!$hasConfigFile && !CBT_DB_FROM_ENV) {
        $cache = true;
        return true;
    }

    try {
        $hasUsersTable = (bool) db_val("SHOW TABLES LIKE 'users'", [], false);
        if (!$hasUsersTable) {
            $cache = true;
            return true;
        }
        $adminCount = (int) db_val("SELECT COUNT(*) FROM users WHERE LOWER(TRIM(role)) = 'admin'", [], 0);
        $cache = $adminCount === 0;
        return $cache;
    } catch (Throwable $e) {
        // Hanya galat "database belum ada / kredensial salah" yang berarti perlu wizard.
        // Galat lain (mis. koneksi habis saat ujian ramai) bukan alasan menampilkan
        // halaman setup, jadi diteruskan sebagai kesalahan server.
        $message = $e->getMessage();
        $belumSiap = preg_match('/(1049|1045|1044|2002|10061|Unknown database|Access denied|Connection refused|Connection timed out)/i', $message);
        if (!$belumSiap) {
            throw new RuntimeException('Database tidak dapat diakses: ' . $message, 0, $e);
        }
        error_log('[CBT setup] database belum siap: ' . $message);
        $cache = true;
        return true;
    }
}

/**
 * Apakah aksi setup (test/install/force) boleh dijalankan.
 *
 * Instalasi pertama selalu boleh. Setelah aplikasi terpasang, hanya request
 * dari localhost atau saat CBT_ALLOW_REINSTALL=1 yang boleh menjalankan ulang
 * setup, sehingga endpoint install tidak dapat dipicu dari internet.
 */
function setup_actions_allowed(): bool
{
    if (getenv('CBT_ALLOW_REINSTALL') === '1') {
        return true;
    }

    // Instalasi pertama (belum ada config dan bukan dari environment) selalu
    // boleh, termasuk dari host lain.
    if (!is_file(setup_config_path()) && !CBT_DB_FROM_ENV) {
        return true;
    }

    // Setelah config ada, aksi setup/install hanya untuk localhost. Kondisi
    // database tidak dipakai sebagai alasan membuka akses dari internet.
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

/** Kredensial yang akan ditampilkan di form (tanpa membocorkan password). */
function setup_form_defaults(): array
{
    $saved = read_setup_config();
    return [
        'host' => (string) ($saved['host'] ?? '127.0.0.1'),
        'port' => (string) ($saved['port'] ?? '3306'),
        'name' => (string) ($saved['name'] ?? 'cbt_gas'),
        'user' => (string) ($saved['user'] ?? 'root'),
        'pass' => '',
        'has_pass' => tostr($saved['pass'] ?? '') !== '',
    ];
}

/** Ringkasan lingkungan server untuk ditampilkan di halaman setup. */
function setup_environment_checks(): array
{
    $checks = [
        ['label' => 'PHP ' . PHP_VERSION . ' (butuh 8.0+)', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => ''],
        ['label' => 'Ekstensi pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'detail' => 'wajib'],
        ['label' => 'Ekstensi json', 'ok' => extension_loaded('json'), 'detail' => 'wajib'],
        ['label' => 'Ekstensi fileinfo', 'ok' => extension_loaded('fileinfo'), 'detail' => 'validasi unggah gambar'],
        ['label' => 'Ekstensi gd', 'ok' => extension_loaded('gd'), 'detail' => 'favicon 64x64'],
        ['label' => 'Ekstensi curl', 'ok' => extension_loaded('curl'), 'detail' => 'AI Gemini & update aplikasi'],
        ['label' => 'Ekstensi openssl', 'ok' => extension_loaded('openssl'), 'detail' => 'kartu ujian (password terenkripsi)'],
        ['label' => 'Folder config/ dapat ditulis', 'ok' => is_writable(APP_ROOT . '/config'), 'detail' => 'menyimpan database.json'],
    ];
    return $checks;
}

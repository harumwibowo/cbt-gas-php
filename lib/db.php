<?php
/**
 * Lapisan database: PDO singleton + bootstrap skema (padanan CreateDb()).
 */
declare(strict_types=1);

/** @var PDO|null */
$GLOBALS['CBT_PDO'] = null;

/**
 * Koneksi PDO tunggal ke database aplikasi (dibuat otomatis bila belum ada).
 */
function db(): PDO
{
    if ($GLOBALS['CBT_PDO'] instanceof PDO) {
        return $GLOBALS['CBT_PDO'];
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsnServer = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);

    try {
        $server = new PDO($dsnServer, DB_USER, DB_PASS, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (PDOException $e) {
        throw new RuntimeException('Gagal terhubung ke MySQL: ' . $e->getMessage(), 0, $e);
    }

    $pdo = new PDO($dsnServer . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $options);
    $GLOBALS['CBT_PDO'] = $pdo;

    return $pdo;
}

/** Ambil semua baris. */
function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Ambil satu baris (atau null). */
function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Ambil satu nilai skalar. */
function db_val(string $sql, array $params = [], $default = null)
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

/** Jalankan INSERT/UPDATE/DELETE, kembalikan jumlah baris terpengaruh. */
function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Baris terakhir yang di-insert (auto increment). */
function db_last_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Buat tabel & kolom yang belum ada (padanan idempotent CreateDb()).
 * Dipanggil sekali per request oleh api.php/index.php secara ringan (cache statis).
 */
function ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $sqlFile = APP_ROOT . '/database/schema.sql';
    if (!is_file($sqlFile)) {
        throw new RuntimeException('File skema database tidak ditemukan: ' . $sqlFile);
    }

    $sql = (string) file_get_contents($sqlFile);
    // Buang komentar baris (--) agar tiap statement terpisah bersih.
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        db()->exec($statement);
    }

    // Migrasi ringan: pastikan kolom baru tersedia pada instalasi lama.
    $columns = [
        'users' => [
            'sesi'         => "VARCHAR(50) NOT NULL DEFAULT '-'",
            'tingkat'      => "VARCHAR(50) NOT NULL DEFAULT '-'",
            'password_enc' => 'TEXT NULL',
        ],
    ];
    foreach ($columns as $table => $defs) {
        $existing = array_column(
            db_all('SHOW COLUMNS FROM `' . $table . '`'),
            'Field'
        );
        foreach ($defs as $name => $ddl) {
            if (!in_array($name, $existing, true)) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$name` $ddl");
            }
        }
    }

    seed_defaults();
    $done = true;
}

/**
 * Seed data default: master sesi, akun admin, pengaturan sistem.
 * Padanan bagian akhir CreateDb() pada Code.gs.
 */
function seed_defaults(): void
{
    // Master sesi default
    $sesiCount = (int) db_val('SELECT COUNT(*) FROM sesi', [], 0);
    if ($sesiCount === 0) {
        db_exec('INSERT INTO sesi (id_sesi, nama_sesi, keterangan) VALUES (?,?,?)', ['SES-1', 'Sesi 1', 'Sesi Pagi (07.30 - 09.30)']);
        db_exec('INSERT INTO sesi (id_sesi, nama_sesi, keterangan) VALUES (?,?,?)', ['SES-2', 'Sesi 2', 'Sesi Siang (10.00 - 12.00)']);
    }

    // Akun admin default
    $hasAdmin = (int) db_val('SELECT COUNT(*) FROM users WHERE username = ?', ['admin'], 0);
    if ($hasAdmin === 0) {
        // Password disimpan sebagai hash; password_enc hanya dipakai untuk
        // mencetak password siswa pada kartu ujian (AES-256-GCM).
        db_exec(
            'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [new_id('USR'), 'admin', hash_password('admin123'), enc_secret('admin123') ?? '',
             'Administrator CBT', 'Admin', 'Semua', '', 'Aktif', 'Semua', '-']
        );
    }

    // Pengaturan default
    $defaults = [
        ['nama_sekolah', 'SMA / SMK Digital Platform', 'Nama sekolah atau institusi penyelenggara'],
        ['logo_url', '', 'URL gambar logo sekolah'],
        ['tahun_ajaran', '2026/2027', 'Tahun ajaran / semester aktif'],
        ['max_student_sessions', '1', 'Batas maksimal sesi login bersamaan untuk siswa (jumlah perangkat berbeda)'],
        ['allowed_student_browsers', '', 'Daftar browser yang diizinkan untuk login siswa, dipisah koma (mis. SEB, Chrome). Kosong = semua browser diizinkan'],
        ['wajib_fullscreen', 'Ya', 'Wajib mode layar penuh selama ujian (Ya/Tidak)'],
        ['deteksi_tab_switch', 'Ya', 'Deteksi berpindah tab atau kehilangan fokus (Ya/Tidak)'],
        ['blokir_aksi', 'Ya', 'Blokir klik kanan, copy, paste, dan pintasan keyboard (Ya/Tidak)'],
        ['dynamic_watermark', 'Ya', 'Tampilkan watermark identitas siswa melayang di area soal (Ya/Tidak)'],
        ['zona_waktu', 'WIB', 'Zona waktu Indonesia untuk seluruh tanggal & jam aplikasi (WIB / WITA / WIT)'],
        ['max_violations', '3', 'Batas maksimal pelanggaran keamanan sebelum tindakan dijalankan (berlaku untuk SEMUA ujian)'],
        ['tindakan_pelanggaran', 'auto_submit', 'Tindakan saat batas maksimal pelanggaran tercapai (auto_submit / auto_blokir / record_only)'],
        ['gemini_api_key', '', 'Google Gemini API Key untuk fitur generate soal AI'],
        ['gemini_model', 'gemini-2.0-flash', 'Model Gemini yang digunakan untuk generate konten'],
    ];
    foreach ($defaults as $row) {
        $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE kunci = ?', [strtolower($row[0])], 0);
        if ($exists === 0) {
            db_exec('INSERT INTO settings (kunci, nilai, keterangan) VALUES (?,?,?)', [strtolower($row[0]), $row[1], $row[2]]);
        }
    }
}

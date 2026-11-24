<?php
/**
 * Lapisan database: PDO singleton + bootstrap skema (padanan CreateDb()).
 */
declare(strict_types=1);

/** @var PDO|null */
$GLOBALS['CBT_PDO'] = null;

/**
 * Koneksi PDO tunggal ke database aplikasi. Database TIDAK dibuat otomatis;
 * bila belum ada, arahkan pengguna ke wizard setup.
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
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // 1049 = database belum ada. Arahkan ke wizard setup, jangan membuat
        // database secara otomatis pada setiap koneksi runtime.
        $message = 'Gagal terhubung ke database "' . DB_NAME . '". '
            . 'Jalankan halaman setup atau buat database terlebih dahulu. '
            . '(kode ' . (int) $e->getCode() . ')';
        throw new RuntimeException($message, 0, $e);
    }

    $GLOBALS['CBT_PDO'] = $pdo;

    return $pdo;
}

/** Hitung query untuk metrik observability (Bagian 3.5). */
function db_query_count_inc(): void
{
    $GLOBALS['CBT_QUERY_COUNT'] = (int) ($GLOBALS['CBT_QUERY_COUNT'] ?? 0) + 1;
}

/** Ambil semua baris. */
function db_all(string $sql, array $params = []): array
{
    db_query_count_inc();
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Ambil satu baris (atau null). */
function db_one(string $sql, array $params = []): ?array
{
    db_query_count_inc();
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Ambil satu nilai skalar. */
function db_val(string $sql, array $params = [], $default = null)
{
    db_query_count_inc();
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

/** Jalankan INSERT/UPDATE/DELETE, kembalikan jumlah baris terpengaruh. */
function db_exec(string $sql, array $params = []): int
{
    db_query_count_inc();
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
 * Versi skema target (dari config, dengan fallback untuk pemakaian CLI mandiri).
 */
function schema_target_version(): int
{
    return defined('CBT_SCHEMA_VERSION') ? (int) CBT_SCHEMA_VERSION : 12;
}

/**
 * Pemeriksaan skema ringan pada jalur request.
 *
 * TIDAK menjalankan CREATE TABLE/SHOW COLUMNS/seed lagi. Cukup satu query
 * SELECT MAX(version) dari schema_migrations (di-cache singkat via APCu/file),
 * lalu bandingkan dengan CBT_SCHEMA_VERSION. Bootstrap penuh hanya terjadi
 * sekali bila skema belum ada/tertinggal, misalnya setelah instalasi baru.
 */
function ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cached = cbt_cache_get('cbt.schema.version');
    if (is_numeric($cached) && (int) $cached >= schema_target_version()) {
        $done = true;
        return;
    }

    $version = null;
    try {
        $version = db_val('SELECT MAX(version) FROM schema_migrations', [], null);
    } catch (Throwable $e) {
        // Tabel schema_migrations belum ada -> instalasi perlu bootstrap penuh.
        error_log('[CBT schema] tabel versi belum tersedia: ' . $e->getMessage());
    }

    if ($version !== null && (int) $version >= schema_target_version()) {
        cbt_cache_set('cbt.schema.version', (int) $version, 300);
        $done = true;
        return;
    }

    // Fallback satu kali untuk instalasi lama/fresh. Jalur normal seharusnya
    // sudah menjalankan migrasi lewat setup.php atau tools/migrate.php.
    schema_bootstrap();
    $done = true;
}

/**
 * Instalasi/migrasi skema penuh (DDL + penyesuaian kolom + seed).
 *
 * Hanya dipanggil dari setup.php (instalasi via wizard) dan tools/migrate.php.
 * Jangan pernah memanggil fungsi ini dari api.php, index.php, upload.php,
 * apalagi media.php: DDL memicu metadata lock dan mahal saat ujian serentak.
 */
function schema_bootstrap(): void
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
        'results' => [
            'deadline_at' => 'DATETIME NULL',
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

    db_apply_migrations();
    seed_defaults();

    // Simpan versi hasil bootstrap apa adanya. Bila sebagian migrasi gagal,
    // request berikutnya akan mencoba lagi (tidak ditandai sukses palsu).
    $actual = db_val('SELECT MAX(version) FROM schema_migrations', [], 0);
    cbt_cache_set('cbt.schema.version', (int) $actual, 300);
    $done = true;
}

/** True bila sebuah index sudah ada pada tabel. */
function db_index_exists(string $table, string $index): bool
{
    try {
        foreach (db_all('SHOW INDEX FROM `' . $table . '`') as $row) {
            if (strcasecmp((string) ($row['Key_name'] ?? ''), $index) === 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        error_log('[CBT schema] gagal membaca index: ' . $e->getMessage());
    }
    return false;
}

/** Apakah migrasi dengan nomor tertentu sudah dijalankan. */
function db_migration_applied(int $version): bool
{
    try {
        return (int) db_val('SELECT COUNT(*) FROM schema_migrations WHERE version = ?', [$version], 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Tandai migrasi selesai. */
function db_migration_mark(int $version): void
{
    try {
        db_exec('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [$version]);
    } catch (Throwable $e) {
        error_log('[CBT schema] gagal menandai migrasi ' . $version . ': ' . $e->getMessage());
    }
}

/**
 * Migrasi data satu kali. Dijalankan setelah tabel dipastikan ada.
 *
 * Versi 1: normalisasi kolom results + hapus duplikat lama + unique index
 *          (id_ujian, username_siswa, percobaan_ke). Ini menggantikan praktik
 *          lama yang menghapus duplikat pada jalur baca.
 * Versi 2: index tambahan untuk rekap dan log.
 * Versi 7-v10: fitur arsitektur (2FA, audit, idempotency, token per siswa,
 *             batas minimal pengerjaan ujian).
 */
function db_apply_migrations(): void
{
    // ---- v1: results unik per percobaan ------------------------------------
    if (!db_migration_applied(1)) {
        try {
            db()->exec("UPDATE results SET username_siswa = LOWER(TRIM(username_siswa)) WHERE username_siswa <> LOWER(TRIM(username_siswa))");
            db()->exec("UPDATE results SET id_ujian = TRIM(id_ujian) WHERE id_ujian <> TRIM(id_ujian)");

            if (!db_index_exists('results', 'uq_result_attempt')) {
                $duplicates = db_all(
                    'SELECT id_ujian, username_siswa, percobaan_ke, COUNT(*) AS total
                     FROM results
                     GROUP BY id_ujian, username_siswa, percobaan_ke
                     HAVING total > 1'
                );
                foreach ($duplicates as $dup) {
                    $rows = db_all(
                        "SELECT id_hasil FROM results
                         WHERE id_ujian = ? AND username_siswa = ? AND percobaan_ke = ?
                         ORDER BY (status = 'SUBMITTED') DESC, nilai_akhir DESC, id_hasil ASC",
                        [
                            (string) $dup['id_ujian'],
                            (string) $dup['username_siswa'],
                            (int) $dup['percobaan_ke'],
                        ]
                    );
                    $keep = true;
                    foreach ($rows as $row) {
                        if ($keep) {
                            $keep = false;
                            continue;
                        }
                        db_exec('DELETE FROM results WHERE id_hasil = ?', [(string) $row['id_hasil']]);
                    }
                }
                db()->exec('ALTER TABLE results ADD UNIQUE KEY uq_result_attempt (id_ujian, username_siswa, percobaan_ke)');
            }
            db_migration_mark(1);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v1 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v2: index pendukung ------------------------------------------------
    if (!db_migration_applied(2)) {
        try {
            if (!db_index_exists('results', 'idx_results_exam_status')) {
                db()->exec('ALTER TABLE results ADD KEY idx_results_exam_status (id_ujian, status)');
            }
            if (!db_index_exists('logs', 'idx_logs_time')) {
                db()->exec('ALTER TABLE logs ADD KEY idx_logs_time (`timestamp`)');
            }
            if (!db_index_exists('logs', 'idx_logs_type_time')) {
                db()->exec('ALTER TABLE logs ADD KEY idx_logs_type_time (jenis_kejadian, `timestamp`)');
            }
            if (!db_index_exists('exams', 'idx_exams_created')) {
                db()->exec('ALTER TABLE exams ADD KEY idx_exams_created (created_at)');
            }
            if (!db_index_exists('questions', 'idx_questions_exam_order')) {
                db()->exec('ALTER TABLE questions ADD KEY idx_questions_exam_order (id_ujian, urutan)');
            }
            db_migration_mark(2);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v2 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v3: hapus seluruh password yang dapat didekripsi -------------------
    if (!db_migration_applied(3)) {
        try {
            db()->exec('UPDATE users SET password_enc = NULL WHERE password_enc IS NOT NULL');
            db_migration_mark(3);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v3 belum selesai: ' . $e->getMessage());
        }
    }

    // Versi 4: token sesi mentah di users.token_session tidak dipakai lagi.
    // Semua sesi aktif sudah punya baris ber-hash di tabel sessions.
    if (!db_migration_applied(4)) {
        try {
            db_exec('UPDATE users SET token_session = NULL WHERE token_session IS NOT NULL');
            db_migration_mark(4);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v4 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v5: normalisasi data lama + index pendukung (optimasi 2.3) ----------
    // Setelah data dinormalisasi, query cukup memakai `kolom = ?`. Collation
    // utf8mb4_unicode_ci sudah case-insensitive dan VARCHAR mengabaikan spasi
    // akhir, sehingga LOWER()/TRIM() tidak lagi diperlukan (dan tidak membatalkan
    // penggunaan index).
    if (!db_migration_applied(5)) {
        try {
            // UPDATE IGNORE: bila ada benturan pada kunci unik, baris lama
            // dilewati daripada seluruh migrasi gagal.
            $normalize = [
                'UPDATE IGNORE users SET username = LOWER(TRIM(username)) WHERE username <> LOWER(TRIM(username))',
                "UPDATE users SET role = LOWER(TRIM(role)) WHERE role <> LOWER(TRIM(role))",
                "UPDATE users SET status_aktif = LOWER(TRIM(status_aktif)) WHERE status_aktif <> LOWER(TRIM(status_aktif))",
                'UPDATE sessions SET username = LOWER(TRIM(username)) WHERE username <> LOWER(TRIM(username))',
                'UPDATE IGNORE results SET username_siswa = LOWER(TRIM(username_siswa)) WHERE username_siswa <> LOWER(TRIM(username_siswa))',
                'UPDATE results SET id_ujian = TRIM(id_ujian) WHERE id_ujian <> TRIM(id_ujian)',
                'UPDATE IGNORE exams SET id_ujian = TRIM(id_ujian) WHERE id_ujian <> TRIM(id_ujian)',
                "UPDATE exams SET id_guru = LOWER(TRIM(id_guru)) WHERE id_guru <> LOWER(TRIM(id_guru))",
                'UPDATE questions SET id_ujian = TRIM(id_ujian) WHERE id_ujian <> TRIM(id_ujian)',
                "UPDATE questions SET id_teks = TRIM(id_teks) WHERE id_teks <> TRIM(id_teks)",
                'UPDATE logs SET username = LOWER(TRIM(username)) WHERE username <> LOWER(TRIM(username))',
                'UPDATE IGNORE settings SET kunci = LOWER(TRIM(kunci)) WHERE kunci <> LOWER(TRIM(kunci))',
            ];
            foreach ($normalize as $sql) {
                try {
                    db()->exec($sql);
                } catch (Throwable $e) {
                    error_log('[CBT schema] normalisasi v5 dilewati: ' . $e->getMessage());
                }
            }

            $indexes = [
                'results' => [
                    'idx_results_student'  => '(username_siswa, id_ujian)',
                    'idx_results_deadline' => '(deadline_at)',
                ],
                'exams' => [
                    'idx_exams_guru_created' => '(id_guru, created_at)',
                ],
                'users' => [
                    'idx_users_role_status' => '(role, status_aktif)',
                ],
                // Dedup hash sudah dijamin media_store_blob(); index biasa cukup
                // untuk pencarian cepat tanpa menghapus baris lama yang masih
                // dirujuk oleh soal/logo.
                'media' => [
                    'idx_media_hash' => '(hash)',
                ],
            ];
            foreach ($indexes as $table => $defs) {
                foreach ($defs as $name => $cols) {
                    if (!db_index_exists($table, $name)) {
                        db()->exec('ALTER TABLE `' . $table . '` ADD KEY `' . $name . '` ' . $cols);
                    }
                }
            }
            db_migration_mark(5);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v5 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v6: log pelanggaran terstruktur + retensi (optimasi 2.10) -----------
    if (!db_migration_applied(6)) {
        try {
            $existing = array_column(db_all('SHOW COLUMNS FROM `logs`'), 'Field');
            if (!in_array('exam_id', $existing, true)) {
                db()->exec('ALTER TABLE logs ADD COLUMN `exam_id` VARCHAR(40) NULL AFTER `keterangan`');
            }
            if (!in_array('ip', $existing, true)) {
                db()->exec("ALTER TABLE logs ADD COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '' AFTER `exam_id`");
            }
            if (!in_array('user_agent', $existing, true)) {
                db()->exec("ALTER TABLE logs ADD COLUMN `user_agent` VARCHAR(255) NOT NULL DEFAULT '' AFTER `ip`");
            }
            if (!db_index_exists('logs', 'idx_logs_exam_time')) {
                db()->exec('ALTER TABLE logs ADD KEY idx_logs_exam_time (`exam_id`, `timestamp`)');
            }
            // Isi exam_id dari keterangan lama bila memungkinkan (format:
            // "Ujian: MED/... | ..."). Tidak menghapus baris apa pun.
            db()->exec("UPDATE logs SET exam_id = SUBSTRING_INDEX(SUBSTRING_INDEX(keterangan, ' | ', 1), 'Ujian: ', -1)
                        WHERE exam_id IS NULL AND keterangan LIKE 'Ujian: %'");
            db_migration_mark(6);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v6 belum selesai: ' . $e->getMessage());
        }
    }

    // v7-v9 (arsitektur): struktur sudah ada di schema.sql untuk instalasi baru;
    // blok ini hanya menambahinya pada instalasi lama yang masih di v6.
    if (!db_migration_applied(7)) {
        try {
            $cols = array_column(db_all('SHOW COLUMNS FROM `users`'), 'Field');
            if (!in_array('two_factor_secret', $cols, true)) {
                db()->exec('ALTER TABLE users ADD COLUMN `two_factor_secret` VARCHAR(64) NULL');
            }
            if (!in_array('must_change_password', $cols, true)) {
                db()->exec("ALTER TABLE users ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!db_index_exists('answer_audit', 'idx_answer_audit_hasil')) {
                db()->exec('CREATE TABLE IF NOT EXISTS answer_audit (
                    id BIGINT NOT NULL AUTO_INCREMENT, id_hasil VARCHAR(40) NOT NULL,
                    id_soal VARCHAR(40) NOT NULL, jawaban VARCHAR(500) NULL,
                    changed_at DATETIME NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT "",
                    PRIMARY KEY (id), KEY idx_answer_audit_hasil (id_hasil, changed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
            db_migration_mark(7);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v7 belum selesai: ' . $e->getMessage());
        }
    }

    if (!db_migration_applied(8)) {
        try {
            $cols = array_column(db_all('SHOW COLUMNS FROM `results`'), 'Field');
            if (!in_array('idempotency_key', $cols, true)) {
                db()->exec('ALTER TABLE results ADD COLUMN `idempotency_key` VARCHAR(64) NULL');
            }
            if (!db_index_exists('results', 'idx_results_idempotency')) {
                db()->exec('ALTER TABLE results ADD KEY idx_results_idempotency (`idempotency_key`)');
            }
            db_migration_mark(8);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v8 belum selesai: ' . $e->getMessage());
        }
    }

    if (!db_migration_applied(9)) {
        try {
            db()->exec('CREATE TABLE IF NOT EXISTS exam_tokens (
                id_ujian VARCHAR(40) NOT NULL, username_siswa VARCHAR(100) NOT NULL,
                token_hash CHAR(64) NOT NULL DEFAULT "", expires_at DATETIME NOT NULL,
                used_at DATETIME NULL, created_at DATETIME NOT NULL,
                PRIMARY KEY (id_ujian, username_siswa), KEY idx_exam_tokens_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            db_exec("INSERT IGNORE INTO settings (kunci, nilai, keterangan) VALUES
                ('token_per_siswa', 'Tidak', 'Gunakan token sekali pakai per siswa (Ya/Tidak). Kosong = token ujian global')");
            db_migration_mark(9);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v9 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v10: batas minimal pengerjaan per ujian ---------------------------
    if (!db_migration_applied(10)) {
        try {
            $cols = array_column(db_all('SHOW COLUMNS FROM `exams`'), 'Field');
            if (!in_array('min_pengerjaan_menit', $cols, true)) {
                db()->exec("ALTER TABLE exams ADD COLUMN `min_pengerjaan_menit` INT NOT NULL DEFAULT 0
                    COMMENT 'Batas minimal pengerjaan (menit) sebelum tombol kirim terbuka. 0 = tanpa batas' AFTER `durasi_menit`");
            }
            db_migration_mark(10);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v10 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v11: kebijakan pelaksanaan ujian dipusatkan di tabel settings ------
    if (!db_migration_applied(11)) {
        try {
            // Sebelumnya durasi/percobaan/pembahasan diatur per-ujian. Saat beralih
            // ke global, pakai nilai paling longgar dari ujian yang sudah ada agar
            // siswa tidak tiba-tiba mendapat waktu/kesempatan lebih sedikit.
            $durasiLama = (int) db_val('SELECT MAX(durasi_menit) FROM exams', [], 0);
            $percobaanLama = (int) db_val('SELECT MAX(jumlah_percobaan) FROM exams', [], 0);
            $pembahasanAda = (int) db_val("SELECT COUNT(*) FROM exams WHERE LOWER(TRIM(tampilkan_pembahasan)) = 'ya'", [], 0);
            $durasiSeed = normalize_exam_duration($durasiLama > 0 ? $durasiLama : 60);
            $percobaanSeed = normalize_exam_attempts($percobaanLama > 0 ? $percobaanLama : 1);
            $pembahasanSeed = $pembahasanAda > 0 ? 'Ya' : 'Tidak';
            // Nilai default dulu ditulis (aman bila baris sudah ada), lalu
            // disesuaikan dengan data ujian lama HANYA bila nilainya masih default.
            db_exec("INSERT IGNORE INTO settings (kunci, nilai, keterangan) VALUES
                ('durasi_ujian', '60', 'Durasi pengerjaan ujian dalam menit untuk SEMUA ujian (1-1440)'),
                ('min_pengerjaan_ujian', '0', 'Batas minimal pengerjaan (menit) sebelum tombol kirim terbuka, berlaku untuk SEMUA ujian. 0 = tanpa batas'),
                ('jumlah_percobaan', '1', 'Jumlah maksimal percobaan pengerjaan untuk SEMUA ujian (1-99)'),
                ('tampilkan_pembahasan', 'Tidak', 'Tampilkan kunci jawaban & pembahasan ke siswa setelah ujian dikumpulkan (Ya/Tidak) untuk SEMUA ujian')");
            if ($durasiSeed !== 60) {
                db_exec("UPDATE settings SET nilai = ? WHERE kunci = 'durasi_ujian' AND nilai = '60'", [(string) $durasiSeed]);
            }
            if ($percobaanSeed !== 1) {
                db_exec("UPDATE settings SET nilai = ? WHERE kunci = 'jumlah_percobaan' AND nilai = '1'", [(string) $percobaanSeed]);
            }
            if ($pembahasanSeed === 'Ya') {
                db_exec("UPDATE settings SET nilai = 'Ya' WHERE kunci = 'tampilkan_pembahasan' AND nilai = 'Tidak'");
            }
            cbt_cache_delete('cbt.settings.map');
            db_migration_mark(11);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v11 belum selesai: ' . $e->getMessage());
        }
    }

    // ---- v12: waktu blokir per hasil ujian --------------------------------
    // Dipakai agar buka blokir tidak mereset waktu_mulai: deadline digeser
    // sebesar durasi blokir, batas minimal pengerjaan tetap dari awal ujian.
    if (!db_migration_applied(12)) {
        try {
            $cols = array_column(db_all('SHOW COLUMNS FROM `results`'), 'Field');
            if (!in_array('blocked_at', $cols, true)) {
                db()->exec('ALTER TABLE results ADD COLUMN `blocked_at` DATETIME NULL
                    COMMENT \'Waktu auto-blokir pelanggaran (v12) - dipakai untuk menggeser deadline saat blokir dibuka\'
                    AFTER `deadline_at`');
            }
            db_migration_mark(12);
        } catch (Throwable $e) {
            error_log('[CBT schema] migrasi v12 belum selesai: ' . $e->getMessage());
        }
    }
}

/**
 * Status semua versi migrasi (dipakai CLI & health check).
 */
function db_migrations_status(): array
{
    $out = [];
    for ($version = 1; $version <= schema_target_version(); $version++) {
        $out[] = [
            'version' => $version,
            'file'    => 'v' . $version . ' · database/schema.sql',
            'applied' => db_migration_applied($version),
        ];
    }
    return $out;
}

/**
 * Rollback satu versi (hanya struktur v7-v12; v1-v6 mengandung logika data).
 * Selalu gunakan backup di storage/backups/ untuk pemulihan penuh.
 */
function db_rollback_migration(int $version): void
{
    if ($version === 12) {
        $cols = array_column(db_all('SHOW COLUMNS FROM `results`'), 'Field');
        if (in_array('blocked_at', $cols, true)) {
            db()->exec('ALTER TABLE results DROP COLUMN blocked_at');
        }
    } elseif ($version === 11) {
        db_exec("DELETE FROM settings WHERE kunci IN ('durasi_ujian','min_pengerjaan_ujian','jumlah_percobaan','tampilkan_pembahasan')");
        cbt_cache_delete('cbt.settings.map');
    } elseif ($version === 10) {
        $cols = array_column(db_all('SHOW COLUMNS FROM `exams`'), 'Field');
        if (in_array('min_pengerjaan_menit', $cols, true)) {
            db()->exec('ALTER TABLE exams DROP COLUMN min_pengerjaan_menit');
        }
    } elseif ($version === 9) {
        db()->exec('DROP TABLE IF EXISTS exam_tokens');
        db_exec("DELETE FROM settings WHERE kunci = 'token_per_siswa'");
    } elseif ($version === 8) {
        if (db_index_exists('results', 'idx_results_idempotency')) {
            db()->exec('DROP INDEX idx_results_idempotency ON results');
        }
        $cols = array_column(db_all('SHOW COLUMNS FROM `results`'), 'Field');
        if (in_array('idempotency_key', $cols, true)) {
            db()->exec('ALTER TABLE results DROP COLUMN idempotency_key');
        }
    } elseif ($version === 7) {
        db()->exec('DROP TABLE IF EXISTS answer_audit');
        $cols = array_column(db_all('SHOW COLUMNS FROM `users`'), 'Field');
        foreach (['two_factor_secret', 'must_change_password'] as $col) {
            if (in_array($col, $cols, true)) {
                db()->exec("ALTER TABLE users DROP COLUMN `{$col}`");
            }
        }
    } else {
        throw new InvalidArgumentException('Rollback hanya tersedia untuk versi 7, 8, 9, 10, 11, dan 12.');
    }
    db_exec('DELETE FROM schema_migrations WHERE version = ?', [$version]);
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
        // Password disimpan sebagai hash satu arah. password_enc (legacy)
        // selalu diisi NULL karena tidak ada lagi password yang dapat dipulihkan.
        db_exec(
            'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [new_id('USR'), 'admin', hash_password('admin123'), null,
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
        ['durasi_ujian', '60', 'Durasi pengerjaan ujian dalam menit untuk SEMUA ujian (1-1440)'],
        ['min_pengerjaan_ujian', '0', 'Batas minimal pengerjaan (menit) sebelum tombol kirim terbuka, berlaku untuk SEMUA ujian. 0 = tanpa batas'],
        ['jumlah_percobaan', '1', 'Jumlah maksimal percobaan pengerjaan untuk SEMUA ujian (1-99)'],
        ['tampilkan_pembahasan', 'Tidak', 'Tampilkan kunci jawaban & pembahasan ke siswa setelah ujian dikumpulkan (Ya/Tidak) untuk SEMUA ujian'],
        ['gemini_api_key', '', 'Google Gemini API Key untuk fitur generate soal AI'],
        ['gemini_model', 'gemini-2.0-flash', 'Model Gemini yang digunakan untuk generate konten'],
        ['token_per_siswa', 'Tidak', 'Gunakan token sekali pakai per siswa (Ya/Tidak). Kosong = token ujian global'],
    ];
    foreach ($defaults as $row) {
        $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE kunci = ?', [strtolower($row[0])], 0);
        if ($exists === 0) {
            db_exec('INSERT INTO settings (kunci, nilai, keterangan) VALUES (?,?,?)', [strtolower($row[0]), $row[1], $row[2]]);
        }
    }
    cbt_cache_delete('cbt.settings.map');
}

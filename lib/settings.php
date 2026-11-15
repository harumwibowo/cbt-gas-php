<?php
/**
 * Pengaturan aplikasi (tabel settings) + turunan yang dipakai modul lain.
 */
declare(strict_types=1);

/** Nilai default pengaturan (padanan getSettings()). */
function settings_defaults(): array
{
    return [
        'nama_sekolah'             => 'SMA / SMK Digital Platform',
        'logo_url'                 => '',
        'tahun_ajaran'             => '2026/2027',
        'zona_waktu'               => 'WIB',
        'max_student_sessions'     => '1',
        'allowed_student_browsers' => '',
        'wajib_fullscreen'         => 'Ya',
        'deteksi_tab_switch'       => 'Ya',
        'blokir_aksi'              => 'Ya',
        'dynamic_watermark'        => 'Ya',
        'max_violations'           => '3',
        'tindakan_pelanggaran'     => 'auto_submit',
        'gemini_api_key'           => '',
        'gemini_model'             => 'gemini-2.0-flash',
    ];
}

/**
 * Ambil seluruh pengaturan sebagai array asosiatif (kunci huruf kecil).
 */
function settings_map(): array
{
    $settings = settings_defaults();
    try {
        $rows = db_all('SELECT kunci, nilai FROM settings');
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row['kunci']));
            if ($key !== '') {
                $settings[$key] = tostr($row['nilai']);
            }
        }
    } catch (Throwable $e) {
        error_log('Gagal membaca settings: ' . $e->getMessage());
    }
    return $settings;
}

/** Endpoint publik getSettings: { success, settings }. */
function get_settings(): array
{
    return [
        'success'  => true,
        'settings' => settings_map(),
        'timezone' => timezone_info(),
    ];
}

/** Batas maksimum sesi login siswa (1..50). */
function get_max_student_sessions(): int
{
    $settings = settings_map();
    $limit = (int) tostr($settings['max_student_sessions'] ?? '1');
    if ($limit < 1) {
        $limit = 1;
    }
    return min($limit, 50);
}

/** Daftar browser yang diizinkan untuk siswa (string dipisah koma). */
function get_allowed_student_browsers(): string
{
    $settings = settings_map();
    return trim(tostr($settings['allowed_student_browsers'] ?? ''));
}

/**
 * Buang sesi siswa terlama agar tidak melebihi batas (padanan enforceStudentSessionLimit()).
 * @return int jumlah sesi yang dikeluarkan
 */
function enforce_student_session_limit(int $limit): int
{
    if ($limit < 1) {
        $limit = 1;
    }
    $rows = db_all("SELECT id_user, token_session FROM users WHERE LOWER(TRIM(role)) = 'siswa'");
    $trimmed = 0;
    foreach ($rows as $row) {
        $tokens = parse_session_tokens($row['token_session']);
        if (count($tokens) <= $limit) {
            continue;
        }
        $kept = array_slice($tokens, -$limit);
        $trimmed += count($tokens) - count($kept);
        db_exec('UPDATE users SET token_session = ? WHERE id_user = ?', [implode(',', $kept), $row['id_user']]);
    }
    return $trimmed;
}

/** Deskripsi tiap kunci pengaturan (dipakai saat menyimpan kunci baru). */
function settings_descriptions(): array
{
    return [
        'nama_sekolah'             => 'Nama sekolah atau institusi penyelenggara',
        'logo_url'                 => 'URL gambar logo sekolah',
        'tahun_ajaran'             => 'Tahun ajaran / semester aktif',
        'max_student_sessions'     => 'Batas maksimal sesi login bersamaan untuk siswa (jumlah perangkat berbeda)',
        'allowed_student_browsers' => 'Daftar browser yang diizinkan untuk login siswa, dipisah koma (mis. SEB, Chrome). Kosong = semua browser diizinkan',
        'wajib_fullscreen'         => 'Wajib mode layar penuh selama ujian (Ya/Tidak)',
        'deteksi_tab_switch'       => 'Deteksi berpindah tab atau kehilangan fokus (Ya/Tidak)',
        'blokir_aksi'              => 'Blokir klik kanan, copy, paste, dan pintasan keyboard (Ya/Tidak)',
        'zona_waktu'               => 'Zona waktu Indonesia untuk seluruh tanggal & jam aplikasi (WIB / WITA / WIT)',
        'max_violations'           => 'Batas maksimal pelanggaran keamanan sebelum tindakan dijalankan (berlaku untuk SEMUA ujian)',
        'tindakan_pelanggaran'     => 'Tindakan saat batas maksimal pelanggaran tercapai (auto_submit / auto_blokir / record_only)',
        'dynamic_watermark'        => 'Tampilkan watermark identitas siswa melayang di area soal (Ya/Tidak)',
        'gemini_api_key'           => 'Google Gemini API Key untuk fitur generate soal AI',
        'gemini_model'             => 'Model Gemini yang digunakan untuk generate konten',
    ];
}

/**
 * Simpan pembaruan pengaturan oleh Admin (padanan saveSettings()).
 */
function save_settings($username, $token, $settingsData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    // Pengaturan bersifat global (sekolah) — hanya Admin yang boleh mengubahnya.
    if (current_role(tostr($username)) !== 'admin') {
        return err('Akses ditolak. Hanya Admin yang dapat mengubah pengaturan.');
    }

    $settingsData = is_array($settingsData) ? $settingsData : [];

    try {
        $sessionLimitUpdated = false;
        $logoLama = tostr(settings_map()['logo_url'] ?? '');
        $zonaLama = app_timezone_label();
        $zonaBerubah = false;

        foreach ($settingsData as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }
            $val = tostr($value);

            if ($normalizedKey === 'max_student_sessions') {
                $limitNum = (int) $val;
                if ($limitNum < 1) {
                    $limitNum = 1;
                }
                $val = (string) min($limitNum, 50);
                $sessionLimitUpdated = true;
            }

            if ($normalizedKey === 'zona_waktu') {
                $val = normalize_timezone_label($val);
                if ($val !== $zonaLama) {
                    $zonaBerubah = true;
                }
            }

            // Kebijakan anti-curang GLOBAL: batas & tindakan pelanggaran.
            if ($normalizedKey === 'max_violations') {
                $val = (string) normalize_exam_max_violations($val);
            }
            if ($normalizedKey === 'tindakan_pelanggaran') {
                $val = normalize_violation_action($val);
            }

            $exists = (int) db_val('SELECT COUNT(*) FROM settings WHERE kunci = ?', [$normalizedKey], 0);
            if ($exists > 0) {
                db_exec('UPDATE settings SET nilai = ? WHERE kunci = ?', [$val, $normalizedKey]);
            } else {
                $desc = settings_descriptions()[$normalizedKey] ?? 'Pengaturan sistem';
                db_exec('INSERT INTO settings (kunci, nilai, keterangan) VALUES (?,?,?)', [$normalizedKey, $val, $desc]);
            }
        }

        // Pastikan pembacaan kebijakan pelanggaran berikutnya memakai nilai baru.
        violation_policy(true);

        // Terapkan zona waktu baru pada request berikutnya (dan segera untuk sisa request ini).
        apply_app_timezone();
        if ($zonaBerubah) {
            log_activity(
                tostr($username),
                'Ubah Zona Waktu',
                'Zona waktu aplikasi diubah dari ' . $zonaLama . ' (' . (timezone_options()[$zonaLama] ?? '-') . ')'
                . ' menjadi ' . app_timezone_label() . ' (' . app_timezone() . ').'
            );
        }

        $finalSessionLimit = get_max_student_sessions();

        // Ganti logo: berkas media lama dibersihkan bila tidak lagi dirujuk.
        if (function_exists('media_delete_if_unused')) {
            $logoBaru = tostr(settings_map()['logo_url'] ?? '');
            if ($logoBaru !== $logoLama && $logoLama !== '') {
                media_delete_if_unused($logoLama);
            }
        }
        $trimmedSessions = 0;
        if ($sessionLimitUpdated) {
            $trimmedSessions = enforce_student_session_limit($finalSessionLimit);
        }

        log_activity(
            tostr($username),
            'Update Settings',
            'Pengaturan sistem sekolah & keamanan diperbarui.'
            . ($sessionLimitUpdated
                ? ' Batas sesi login siswa: ' . $finalSessionLimit . ' perangkat'
                  . ($trimmedSessions > 0 ? ' (' . $trimmedSessions . ' sesi terlama dikeluarkan)' : '') . '.'
                : '')
        );

        return [
            'success' => true,
            'message' => 'Pengaturan berhasil disimpan!'
                . ($trimmedSessions > 0 ? ' ' . $trimmedSessions . ' sesi login siswa terlama telah dikeluarkan.' : ''),
        ];
    } catch (Throwable $e) {
        return err('Gagal menyimpan pengaturan: ' . $e->getMessage());
    }
}

/**
 * Alias endpoint getSettings (nama action dari klien Apps Script).
 */
function getSettings(): array
{
    return get_settings();
}

/**
 * Alias endpoint saveSettings (nama action dari klien Apps Script).
 */
function saveSettings($username, $token, $settingsData): array
{
    return save_settings($username, $token, $settingsData);
}

/**
 * Kebijakan anti-curang GLOBAL (berlaku untuk seluruh ujian).
 *
 * Sebelumnya nilai ini tersimpan per-ujian pada kolom `exams.max_violations` dan
 * `exams.tindakan_pelanggaran`; kini dipusatkan di tabel `settings` agar cukup
 * diatur sekali oleh Admin pada halaman Pengaturan.
 *
 * @return array{max:int, action:string}
 */
function violation_policy(bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $settings = settings_map();
    $max = (int) tostr($settings['max_violations'] ?? '3');
    if ($max < 1) {
        $max = 3;
    }
    $cache = [
        'max'    => min($max, 99),
        'action' => normalize_violation_action($settings['tindakan_pelanggaran'] ?? 'auto_submit'),
    ];
    return $cache;
}

/** Batas maksimal pelanggaran global (1..99). */
function get_max_violations(): int
{
    return violation_policy()['max'];
}

/** Tindakan saat batas pelanggaran tercapai (auto_submit | auto_blokir | record_only). */
function get_violation_action(): string
{
    return violation_policy()['action'];
}

// ---------------------------------------------------------------------------
// Zona waktu aplikasi (WIB / WITA / WIT)
// ---------------------------------------------------------------------------
//
// Seluruh tanggal & jam ujian disimpan sebagai DATETIME "wall clock" (naive) di
// zona waktu yang dipilih di halaman Pengaturan. Zona waktu server (OS/PHP) TIDAK
// berpengaruh karena default timezone selalu di-set eksplisit dari pengaturan ini
// (lihat apply_app_timezone() yang dipanggil di config/config.php).

/** Pilihan zona waktu Indonesia → nama zona IANA. */
function timezone_options(): array
{
    return [
        'WIB'  => 'Asia/Jakarta',   // UTC+7
        'WITA' => 'Asia/Makassar',  // UTC+8
        'WIT'  => 'Asia/Jayapura',  // UTC+9
    ];
}

/**
 * Normalisasi label zona waktu. Menerima label (WIB/WITA/WIT), nama IANA
 * (Asia/Jakarta, Asia/Makassar, Asia/Jayapura), atau nama kota.
 */
function normalize_timezone_label($value): string
{
    $raw = strtoupper(trim(tostr($value)));
    if ($raw === '') {
        return 'WIB';
    }

    // Label resmi lebih dulu (WITA harus dicek sebelum WIT).
    $options = timezone_options();
    if (isset($options[$raw])) {
        return $raw;
    }
    if (str_contains($raw, 'WITA') || str_contains($raw, 'MAKASSAR') || str_contains($raw, 'UTC+8') || str_contains($raw, 'UTC +8')) {
        return 'WITA';
    }
    if (str_contains($raw, 'WIT') || str_contains($raw, 'JAYAPURA') || str_contains($raw, 'UTC+9') || str_contains($raw, 'UTC +9')) {
        return 'WIT';
    }
    if (str_contains($raw, 'WIB') || str_contains($raw, 'JAKARTA') || str_contains($raw, 'UTC+7') || str_contains($raw, 'UTC +7')) {
        return 'WIB';
    }
    return 'WIB';
}

/** Label zona waktu yang sedang aktif (WIB/WITA/WIT). */
function app_timezone_label(): string
{
    try {
        $settings = settings_map();
        return normalize_timezone_label($settings['zona_waktu'] ?? 'WIB');
    } catch (Throwable $e) {
        return 'WIB';
    }
}

/** Nama zona IANA yang sedang aktif (mis. Asia/Makassar). */
function app_timezone(): string
{
    $options = timezone_options();
    return $options[app_timezone_label()] ?? 'Asia/Jakarta';
}

/** Offset zona waktu aktif, mis. "+08:00". */
function app_timezone_offset(): string
{
    try {
        $tz = new DateTimeZone(app_timezone());
        $offset = (new DateTimeImmutable('now', $tz))->getOffset();
        $sign = $offset >= 0 ? '+' : '-';
        $abs = abs($offset);
        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    } catch (Throwable $e) {
        return '+07:00';
    }
}

/**
 * Terapkan zona waktu aplikasi ke PHP (date_default_timezone_set).
 * Aman dipanggil berkali-kali; satu-satunya sumber kebenaran adalah pengaturan.
 */
function apply_app_timezone(): string
{
    $iana = app_timezone();
    try {
        date_default_timezone_set($iana);
    } catch (Throwable $e) {
        error_log('Zona waktu tidak valid: ' . $iana . ' — memakai Asia/Jakarta');
        $iana = 'Asia/Jakarta';
        date_default_timezone_set($iana);
    }
    return $iana;
}

/** Info zona waktu untuk ditampilkan di halaman Pengaturan. */
function timezone_info(): array
{
    $label = app_timezone_label();
    return [
        'label'      => $label,
        'iana'       => app_timezone(),
        'offset'     => app_timezone_offset(),
        'offset_min' => (int) round(((new DateTimeImmutable('now', new DateTimeZone(app_timezone())))->getOffset()) / 60),
        'sekarang'   => date('Y-m-d H:i:s'),
        'options'    => array_keys(timezone_options()),
    ];
}

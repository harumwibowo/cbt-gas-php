<?php
/**
 * Helper umum: konversi nilai, ID, tanggal, log aktivitas, token sesi,
 * deteksi browser, dan normalisasi opsi ujian.
 * Padanan fungsi utilitas pada Code.gs.
 */
declare(strict_types=1);

/**
 * Konversi nilai apa pun menjadi string aman (padanan toSafeString()).
 */
function tostr($val): string
{
    if ($val === null || $val === false) {
        return '';
    }
    if (is_bool($val)) {
        return $val ? 'true' : 'false';
    }
    if ($val instanceof DateTimeInterface) {
        if ((int) $val->format('H:i:s') === 0) {
            return (int) $val->format('j') . ' ' . bulan_id((int) $val->format('n')) . ' ' . $val->format('Y');
        }
        return $val->format('d-m-Y H:i:s');
    }
    if (is_array($val) || is_object($val)) {
        return trim((string) json_encode($val, JSON_UNESCAPED_UNICODE));
    }
    return trim((string) $val);
}

/** Nama bulan dalam Bahasa Indonesia (1-12). */
function bulan_id(int $month): string
{
    $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $bulan[$month - 1] ?? '';
}

/** ID baru bergaya GAS: PREFIX-XXXXXXXX */
function new_id(string $prefix): string
{
    $raw = bin2hex(random_bytes(6));
    return strtoupper($prefix) . '-' . strtoupper(substr($raw, 0, 8));
}

/** Token sesi baru bergaya GAS: TKN-<uuid> */
function new_session_token(): string
{
    $hex = bin2hex(random_bytes(16));
    return 'TKN-' . sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/** Waktu sekarang (format MySQL). */
function now_dt(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Standarisasi tanggal/waktu ke format ISO "Y-m-dTH:i" untuk input datetime-local.
 * Padanan formatDateTimeIso().
 */
function format_datetime_iso($val): string
{
    if ($val === null || $val === '') {
        return '';
    }
    if ($val instanceof DateTimeInterface) {
        return $val->format('Y-m-d\TH:i');
    }
    $str = trim((string) $val);
    if ($str === '' || $str === '-' || strtolower($str) === 'kapan saja') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $str)) {
        return substr($str, 0, 16);
    }
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $str, $m)) {
        return sprintf(
            '%s-%02d-%02dT%02d:%02d',
            $m[3],
            (int) $m[2],
            (int) $m[1],
            (int) ($m[4] ?? 0),
            (int) ($m[5] ?? 0)
        );
    }
    $ts = strtotime($str);
    if ($ts !== false) {
        return date('Y-m-d\TH:i', $ts);
    }
    return $str;
}

/**
 * Normalisasi input datetime-local (atau string lain) menjadi nilai MySQL DATETIME.
 */
function to_db_datetime($val): ?string
{
    $iso = format_datetime_iso($val);
    if ($iso === '') {
        return null;
    }
    $ts = strtotime($iso);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Tanggal keluar untuk tampilan (d-m-Y H:i:s) atau '' bila kosong. */
function dt_display(?string $val): string
{
    if ($val === null || $val === '' || str_starts_with((string) $val, '0000')) {
        return '';
    }
    $ts = strtotime((string) $val);
    return $ts === false ? (string) $val : date('d-m-Y H:i:s', $ts);
}

/**
 * Catat aktivitas ke tabel logs (padanan logActivity()).
 */
function log_activity(string $username, string $jenisKejadian, string $keterangan = ''): void
{
    try {
        db_exec(
            'INSERT INTO logs (`timestamp`, username, jenis_kejadian, keterangan) VALUES (?,?,?,?)',
            [now_dt(), $username !== '' ? $username : 'Anonymous', $jenisKejadian, $keterangan]
        );
    } catch (Throwable $e) {
        error_log('Gagal mencatat log: ' . $e->getMessage());
    }
}

/** Pecah isi kolom token_session menjadi daftar token (padanan parseSessionTokens()). */
function parse_session_tokens($rawValue): array
{
    $raw = tostr($rawValue);
    if ($raw === '') {
        return [];
    }
    $tokens = [];
    foreach (explode(',', $raw) as $part) {
        $t = trim($part);
        if ($t !== '' && !in_array($t, $tokens, true)) {
            $tokens[] = $t;
        }
    }
    return $tokens;
}

/**
 * Bentuk daftar token terbaru tanpa melampaui batas maksimum
 * (padanan buildSessionTokenList()).
 */
function build_session_token_list(array $existingTokens, string $newToken, $maxSessions): array
{
    $list = [];
    foreach ($existingTokens as $t) {
        if (is_string($t) && $t !== '' && $t !== $newToken && !in_array($t, $list, true)) {
            $list[] = $t;
        }
    }
    $list[] = $newToken;
    $max = (int) $maxSessions;
    if ($max < 1) {
        $max = 1;
    }
    if (count($list) > $max) {
        $list = array_slice($list, -$max);
    }
    return $list;
}

/** Deteksi nama browser dari User-Agent (padanan detectUserAgentBrowser()). */
function detect_user_agent_browser(string $userAgent): string
{
    $ua = strtolower($userAgent);
    if ($ua === '') {
        return '';
    }
    if (str_contains($ua, 'seb/') || str_contains($ua, 'safexambrowser') || str_contains($ua, 'safe exam browser')) {
        return 'seb';
    }
    if (str_contains($ua, 'edg/') || str_contains($ua, 'edga/') || str_contains($ua, 'edgios/')) {
        return 'edge';
    }
    if (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
        return 'opera';
    }
    if (str_contains($ua, 'samsungbrowser')) {
        return 'samsung';
    }
    if (str_contains($ua, 'firefox/') || str_contains($ua, 'fxios/')) {
        return 'firefox';
    }
    if (str_contains($ua, 'chrome/') || str_contains($ua, 'crios/')) {
        return 'chrome';
    }
    if (str_contains($ua, 'safari/')) {
        return 'safari';
    }
    if (str_contains($ua, 'msie') || str_contains($ua, 'trident/')) {
        return 'ie';
    }
    return '';
}

/** Cek apakah browser siswa termasuk daftar yang diizinkan (padanan isStudentBrowserAllowed()). */
function is_student_browser_allowed(string $userAgent, string $allowedRaw): bool
{
    $list = [];
    foreach (explode(',', $allowedRaw) as $item) {
        $item = strtolower(trim($item));
        if ($item !== '') {
            $list[] = $item;
        }
    }
    if (count($list) === 0) {
        return true;
    }

    $ua = strtolower($userAgent);
    if ($ua === '') {
        return false;
    }

    $detected = detect_user_agent_browser($ua);
    $aliases = [
        'chrome' => 'chrome', 'google chrome' => 'chrome', 'chromium' => 'chrome',
        'edge' => 'edge', 'microsoft edge' => 'edge', 'ms edge' => 'edge',
        'firefox' => 'firefox', 'mozilla firefox' => 'firefox',
        'safari' => 'safari', 'apple safari' => 'safari',
        'opera' => 'opera', 'opera gx' => 'opera',
        'seb' => 'seb', 'safe exam browser' => 'seb', 'safeexambrowser' => 'seb',
        'samsung' => 'samsung', 'samsung internet' => 'samsung',
        'ie' => 'ie', 'internet explorer' => 'ie',
    ];

    foreach ($list as $token) {
        if (isset($aliases[$token])) {
            if ($detected !== '' && $aliases[$token] === $detected) {
                return true;
            }
        } elseif (str_contains($ua, $token)) {
            return true;
        }
    }
    return false;
}

/** Normalisasi jumlah percobaan ujian (1..99). */
function normalize_exam_attempts($value): int
{
    $n = (int) $value;
    if ($n < 1) {
        return 1;
    }
    return min($n, 99);
}

/** Normalisasi batas maksimal pelanggaran ujian (1..99, default 3). */
function normalize_exam_max_violations($value): int
{
    $n = (int) $value;
    if ($n < 1) {
        return 3;
    }
    return min($n, 99);
}

/** Normalisasi tindakan saat batas pelanggaran tercapai. */
function normalize_violation_action($value): string
{
    $v = strtolower(tostr($value));
    if ($v === 'auto_blokir') {
        return 'auto_blokir';
    }
    if ($v === 'record_only') {
        return 'record_only';
    }
    return 'auto_submit';
}

/** Nomor percobaan dari satu baris hasil (default 1). */
function get_result_attempt_number($row): int
{
    if (!is_array($row) || !isset($row['percobaan_ke'])) {
        return 1;
    }
    return normalize_exam_attempts($row['percobaan_ke']);
}

/** Nilai "Ya"/"Tidak" dari berbagai bentuk masukan. */
function to_yes_no($val, string $default = 'Ya'): string
{
    $v = strtolower(tostr($val));
    if ($v === '' || $v === '-') {
        return $default;
    }
    if (in_array($v, ['ya', 'yes', 'true', '1', 'on'], true)) {
        return 'Ya';
    }
    if (in_array($v, ['tidak', 'no', 'false', '0', 'off'], true)) {
        return 'Tidak';
    }
    return $default;
}

/** Pecah daftar dipisah koma menjadi array bersih. */
function split_list($val): array
{
    $out = [];
    foreach (explode(',', tostr($val)) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Bandingkan dua string dengan aturan "natural-ish" seperti localeCompare JS
 * (dipakai untuk pengurutan kelas/nama).
 */
function cmp_text(string $a, string $b): int
{
    return strnatcasecmp($a, $b);
}

/**
 * Kumpulan daftar turunan dari tabel users + sesi untuk form pembuatan ujian
 * dan target peserta (padanan blok distinctClasses/distinctGrades/... pada
 * getAdminData() & getTeacherExams()).
 *
 * @param bool $onlyStudentRole true = hanya role 'siswa' (perilaku getAdminData)
 */
function catalog_lists(bool $onlyStudentRole = false): array
{
    $classes = [];
    $classGradeMap = [];
    $sessions = [];
    $students = [];
    $teachers = [];
    $grades = [];

    foreach (db_all('SELECT nama_sesi FROM sesi') as $row) {
        $val = trim(tostr($row['nama_sesi']));
        if ($val !== '' && !in_array($val, $sessions, true)) {
            $sessions[] = $val;
        }
    }

    foreach (db_all('SELECT username, nama_lengkap, role, kelas, sesi, tingkat, status_aktif FROM users') as $u) {
        $kVal = trim(tostr($u['kelas']));
        $tVal = trim(tostr($u['tingkat']));
        $lowerKelas = strtolower($kVal);

        if ($kVal !== '' && $kVal !== '-' && $lowerKelas !== 'semua' && !in_array($kVal, $classes, true)) {
            $classes[] = $kVal;
        }
        if ($tVal !== '' && $tVal !== '-' && strtolower($tVal) !== 'semua' && !in_array($tVal, $grades, true)) {
            $grades[] = $tVal;
        }
        if ($kVal !== '' && $kVal !== '-' && $tVal !== '' && $tVal !== '-') {
            $classGradeMap[$kVal] = $tVal;
        }

        $userSesi = trim(tostr($u['sesi']));
        if ($userSesi !== '' && $userSesi !== '-' && strtolower($userSesi) !== 'semua' && !in_array($userSesi, $sessions, true)) {
            $sessions[] = $userSesi;
        }

        $roleLower = strtolower(tostr($u['role']));
        $statusLower = strtolower(tostr($u['status_aktif']));
        $uname = trim(tostr($u['username']));
        $nama = trim(tostr($u['nama_lengkap']));

        $isStudent = $onlyStudentRole ? ($roleLower === 'siswa') : ($roleLower === 'siswa' || $roleLower === '');
        if ($isStudent && $uname !== '' && $statusLower !== 'nonaktif') {
            $students[] = [
                'username'     => $uname,
                'nama_lengkap' => $nama !== '' ? $nama : $uname,
                'kelas'        => $kVal !== '' ? $kVal : '-',
                'sesi'         => $userSesi !== '' ? $userSesi : '-',
                'tingkat'      => $tVal !== '' ? $tVal : '-',
            ];
        }
        if ($uname !== '' && ($roleLower === 'guru' || $roleLower === 'admin') && $statusLower !== 'nonaktif') {
            $teachers[] = [
                'username'     => $uname,
                'nama_lengkap' => $nama !== '' ? $nama : $uname,
                'role'         => tostr($u['role']) !== '' ? tostr($u['role']) : ($roleLower === 'admin' ? 'Admin' : 'Guru'),
            ];
        }
    }

    // Lengkapi pemetaan kelas → tingkat dari pola nama kelas (mis. "XI IPA 1" → "XI").
    foreach ($classes as $cls) {
        if (empty($classGradeMap[$cls]) && preg_match('/^(1[0-2]|[7-9]|X[IVX]*)/i', $cls, $m)) {
            $inf = strtoupper($m[1]);
            $classGradeMap[$cls] = $inf;
            if (!in_array($inf, $grades, true)) {
                $grades[] = $inf;
            }
        }
    }

    usort($classes, 'cmp_text');
    usort($grades, static function ($a, $b) {
        $numA = (int) $a;
        $numB = (int) $b;
        if ($numA > 0 && $numB > 0 && is_numeric($a) && is_numeric($b)) {
            return $numA <=> $numB;
        }
        return strnatcasecmp($a, $b);
    });
    usort($sessions, 'cmp_text');
    usort($students, static function ($a, $b) {
        if ($a['kelas'] !== $b['kelas']) {
            return strnatcasecmp($a['kelas'], $b['kelas']);
        }
        return strnatcasecmp($a['nama_lengkap'], $b['nama_lengkap']);
    });
    usort($teachers, static function ($a, $b) {
        $rA = strtolower($a['role']);
        $rB = strtolower($b['role']);
        if ($rA !== $rB) {
            if ($rA === 'admin') {
                return -1;
            }
            if ($rB === 'admin') {
                return 1;
            }
        }
        return strnatcasecmp($a['nama_lengkap'], $b['nama_lengkap']);
    });

    return [
        'available_classes'  => $classes,
        'available_grades'   => $grades,
        'class_grade_map'    => $classGradeMap,
        'available_sessions' => $sessions,
        'available_students' => $students,
        'available_teachers' => $teachers,
    ];
}

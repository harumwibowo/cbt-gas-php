<?php
/**
 * Unit test mandiri (tanpa PHPUnit) untuk fungsi murni (Bagian 3.6).
 * Jalankan: php tests/unit_test.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/services/Services.php';
require_once APP_ROOT . '/modules/student_exam.php';
require_once APP_ROOT . '/lib/update.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    } else {
        $failed++;
        echo "FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

// --- 1. TOTP RFC 6238 test vector -----------------------------------------
// Secret Base32 dari "12345678901234567890", T=59, SHA1, 8 digit -> 94287082.
check('TOTP RFC6238 T=59',
    totp_at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59, 30, 8) === '94287082',
    totp_at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59, 30, 8));

// --- 2. Base32 roundtrip ---------------------------------------------------
$raw = random_bytes(20);
check('Base32 encode/decode', totp_base32_decode(totp_base32_encode($raw)) === $raw);

// --- 3. TOTP verify --------------------------------------------------------
$secret = totp_new_secret();
$code = totp_code($secret);
check('TOTP verify kode benar', totp_verify($secret, $code));
check('TOTP tolak kode salah', !totp_verify($secret, $code === '000000' ? '111111' : '000000'));

// --- 4. Kebijakan password -------------------------------------------------
check('Passwordpolicy tolak umum', password_policy_error('123456') !== null);
check('Passwordpolicy tolak pendek', password_policy_error('abc') !== null);
check('Passwordpolicy terima kuat', password_policy_error('Kuat12345!') === null);
check('Passwordpolicy sama username', password_policy_error('admin', 'admin') !== null);

// --- 5. Username & sanitasi ------------------------------------------------
check('Username valid', is_valid_username('siswa.1'));
check('Username invalid', !is_valid_username('ab'));
$clean = sanitize_html_fragment('<script>alert(1)</script><b>Aman</b>');
check('Sanitasi hapus script', !str_contains(strtolower($clean), 'script') && str_contains($clean, '<b>'));

// --- 6. Attempt & deadline -------------------------------------------------
check('Attempt normalisasi 0 -> 1', normalize_exam_attempts(0) === 1);
check('Attempt normalisasi 200 -> 99', normalize_exam_attempts(200) === 99);
check('Attempt dari baris', get_result_attempt_number(['percobaan_ke' => 3]) === 3);
check('Attempt default', get_result_attempt_number([]) === 1);
check('Deadline lampau', exam_deadline_passed(date('Y-m-d H:i:s', time() - 3600)) === true);
check('Deadline masa depan', exam_deadline_passed(date('Y-m-d H:i:s', time() + 3600)) === false);

// --- 7. Service map --------------------------------------------------------
$map = service_action_map();
foreach (['login', 'getadmindata', 'startstudentexam', 'autosaveexam', 'submitexam',
          'getexamresults', 'getexamresultdetail', 'verify2fa'] as $action) {
    check('Service map: ' . $action, isset($map[$action]));
}

// --- 8. Request id & cache -------------------------------------------------
check('Request ID unik', preg_match('/^REQ-[A-F0-9]{12}$/', request_id()) === 1);
$key = 'cbt.unit.' . bin2hex(random_bytes(4));
cbt_cache_set($key, ['nilai' => 42], 10);
check('Cache set/get', cbt_cache_get($key) === ['nilai' => 42]);
cbt_cache_delete($key);
check('Cache delete', cbt_cache_get($key) === null);

// --- 9. Updater: folder baru belum ada saat pengecekan (screenshot update) ---
$tmpBase = sys_get_temp_dir() . '/cbt-upd-' . bin2hex(random_bytes(4));
if (@mkdir($tmpBase, 0700, true)) {
    check('Updater izin folder baru', update_parent_writable($tmpBase . '/database/migrations/0007_file.sql') === true);
    @rmdir($tmpBase);
} else {
    check('Updater izin folder baru', false);
}

echo "\n=== UNIT: PASS {$passed}  FAIL {$failed} ===\n";
exit($failed === 0 ? 0 : 1);

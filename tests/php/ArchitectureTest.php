<?php
/**
 * PHPUnit tests untuk helper murni & arsitektur (Bagian 3.6).
 * Jalankan: vendor/bin/phpunit -c phpunit.xml.dist
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once APP_ROOT . '/services/Services.php';
require_once APP_ROOT . '/modules/student_exam.php';

final class ArchitectureTest extends TestCase
{
    public function testTotpRfc6238Vector(): void
    {
        $this->assertSame('94287082', totp_at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59, 30, 8));
    }

    public function testBase32Roundtrip(): void
    {
        $raw = random_bytes(20);
        $this->assertSame($raw, totp_base32_decode(totp_base32_encode($raw)));
    }

    public function testTotpVerify(): void
    {
        $secret = totp_new_secret();
        $code = totp_code($secret);
        $this->assertTrue(totp_verify($secret, $code));
        $this->assertFalse(totp_verify($secret, $code === '000000' ? '111111' : '000000'));
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotNull(password_policy_error('123456'));
        $this->assertNotNull(password_policy_error('abc'));
        $this->assertNotNull(password_policy_error('admin', 'admin'));
        $this->assertNull(password_policy_error('Kuat12345!'));
    }

    public function testAttemptNormalization(): void
    {
        $this->assertSame(1, normalize_exam_attempts(0));
        $this->assertSame(99, normalize_exam_attempts(200));
        $this->assertSame(3, get_result_attempt_number(['percobaan_ke' => 3]));
        $this->assertSame(1, get_result_attempt_number([]));
    }

    public function testDeadlineHelpers(): void
    {
        $this->assertTrue(exam_deadline_passed(date('Y-m-d H:i:s', time() - 3600)));
        $this->assertFalse(exam_deadline_passed(date('Y-m-d H:i:s', time() + 3600)));
    }

    public function testSanitizerRemovesScript(): void
    {
        $clean = sanitize_html_fragment('<script>alert(1)</script><b>Aman</b>');
        $this->assertStringNotContainsStringIgnoringCase('script', $clean);
        $this->assertStringContainsString('<b>', $clean);
    }

    public function testServiceMapCoversKeyActions(): void
    {
        $map = service_action_map();
        foreach (['login', 'getadmindata', 'startstudentexam', 'autosaveexam',
                  'submitexam', 'getexamresults', 'verify2fa'] as $action) {
            $this->assertArrayHasKey($action, $map);
        }
    }
}

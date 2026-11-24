<?php
/**
 * Endpoint RPC tunggal — padanan google.script.run pada aplikasi Apps Script.
 *
 * Pemakaian:  POST api.php?action=namaFungsi  body: {"args":[...]}
 *             Hanya POST; permintaan GET ditolak 405.
 *
 * Selalu mengembalikan JSON. Bila fungsi melempar exception, status HTTP 500
 * dengan {"error": "..."} sehingga withFailureHandler di klien terpanggil.
 */
declare(strict_types=1);

// Kompresi JSON (rekap nilai bisa besar). Fallback aman bila zlib tidak ada.
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

require_once __DIR__ . '/config/config.php';

if (maintenance_active()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    http_response_code(503);
    echo json_encode(['error' => 'Aplikasi sedang dalam pembaruan. Silakan coba lagi beberapa saat lagi.', 'maintenance' => true]);
    exit;
}

obs_start();
register_shutdown_function(static function (): void {
    obs_request_finish(
        (string) ($GLOBALS['CBT_OBS_ACTION'] ?? ''),
        ['user' => (string) ($GLOBALS['CBT_OBS_USER'] ?? '')]
    );
});

require_once APP_ROOT . '/modules/admin.php';
require_once APP_ROOT . '/modules/media.php';
require_once APP_ROOT . '/modules/sessions.php';
require_once APP_ROOT . '/modules/exams.php';
require_once APP_ROOT . '/modules/questions.php';
require_once APP_ROOT . '/modules/results.php';
require_once APP_ROOT . '/modules/student.php';
require_once APP_ROOT . '/modules/student_exam.php';
require_once APP_ROOT . '/modules/monitor.php';
require_once APP_ROOT . '/modules/gemini.php';
require_once APP_ROOT . '/lib/update.php';
require_once APP_ROOT . '/lib/rbac.php';
require_once APP_ROOT . '/services/Services.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Request-ID: ' . request_id());

// Endpoint ini hanya melayani POST. Mencegah aksi state-changing dipicu lewat
// gambar/tautan GET dan mempermudah pemeriksaan origin.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Metode tidak diizinkan. Gunakan POST.']);
    exit;
}

if (!api_request_is_same_origin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Origin request tidak diizinkan.']);
    exit;
}

/** Daftar aksi yang boleh dipanggil dari klien (whitelist). */
const ALLOWED_ACTIONS = [
    // Autentikasi & sesi
    'login', 'logout', 'getSessionStatus', 'GetSessionStatus',
    // Autentikasi dua faktor & manajemen perangkat
    'setup2fa', 'confirm2fa', 'verify2fa', 'disable2fa', 'logoutAllDevices', 'getActiveSessions',
    // Pengaturan
    'getSettings', 'saveSettings',
    // Admin: pengguna
    'getUsers', 'getStudentExamCards', 'getAdminData', 'saveUser', 'changePassword',
    'deleteUser', 'deleteUsersBulk', 'resetUserPassword', 'resetUsersPasswordBulk', 'getStudentLoginSessions', 'resetStudentLoginSession', 'resetStudentLoginSessionsBulk', 'importUsers',
    // Master sesi
    'getSessions', 'saveSession', 'deleteSession',
    // Ujian
    'getTeacherExams', 'saveExam', 'deleteExam', 'generateStudentTokens',
    // Soal & teks
    'getExamQuestions', 'saveQuestion', 'deleteQuestion', 'importQuestions', 'saveBulkQuestions',
    'getExamTexts', 'saveExamText', 'deleteExamText',
    // Hasil & pelanggaran
    'getExamResults', 'getExamResultDetail', 'deleteStudentExamResult', 'getStudentViolations', 'regradeExamResults',
    // Alur siswa
    'getStudentAvailableExams', 'getStudentExamReview', 'startStudentExam',
    'autosaveExam', 'recordViolation', 'submitExam', 'getExamTiming',
    'blockStudentExam', 'unblockStudentExam',
    // Monitor ujian (live) & paksa selesai
    'getExamMonitor', 'forceFinishExam',
    // AI (Gemini)
    'getGeminiModels', 'testGeminiConnection', 'generateQuestionsWithGemini',
    // Pemeliharaan aplikasi
    'updateApplication', 'getApplicationVersion',
];

/** Ambil action dari query string. */
$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';

/** Body request: JSON {"args":[...]} atau form-encoded args[] / args. */
$body = file_get_contents('php://input');
$payload = [];
if (is_string($body) && trim($body) !== '') {
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($action === '' && isset($payload['action'])) {
    $action = trim((string) $payload['action']);
}

$args = [];
if (isset($payload['args']) && is_array($payload['args'])) {
    $args = array_values($payload['args']);
} elseif (isset($_POST['args'])) {
    $decodedArgs = is_string($_POST['args']) ? json_decode($_POST['args'], true) : $_POST['args'];
    if (is_array($decodedArgs)) {
        $args = array_values($decodedArgs);
    }
}

$GLOBALS['CBT_OBS_ACTION'] = $action;
$GLOBALS['CBT_OBS_USER'] = isset($args[0]) ? strtolower(trim(tostr($args[0]))) : '';

// CSRF double-submit (aktif bila cookie cbt_csrf tersedia setelah login).
// Login/getSettings/getSessionStatus/verify2fa dikecualikan: saat login belum
// ada sesi, dan cookie lama tidak boleh memblokir percobaan login berikutnya.
$csrfExempt = in_array(strtolower($action), [
    'login', 'getsettings', 'getsessionstatus', 'verify2fa',
], true);
if (!$csrfExempt && !api_csrf_valid()) {
    error_log('[CBT CSRF] action=' . $action . ' cookie='
        . (csrf_cookie_value() !== '' ? 'ada' : 'tidak-ada') . ' header='
        . (trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) !== '' ? 'ada' : 'tidak-ada')
        . ' ref=' . request_id());
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF tidak valid. Silakan muat ulang halaman (Ctrl+F5).']);
    exit;
}

if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter "action" wajib diisi.']);
    exit;
}

if (!in_array($action, ALLOWED_ACTIONS, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Aksi tidak dikenal: ' . $action]);
    exit;
}

try {
    // Instalasi belum selesai: beri tahu klien agar membuka halaman setup.
    if (setup_needs_wizard()) {
        http_response_code(503);
        echo json_encode([
            'error'       => 'Instalasi belum selesai. Buka setup.php untuk mengatur database.',
            'needs_setup' => true,
        ]);
        exit;
    }

    // Pemeriksaan skema ringan: satu SELECT versi, tanpa DDL/seed. Migrasi
    // penuh dijalankan lewat setup.php / tools/migrate.php, bukan di sini.
    ensure_schema();

    // Otorisasi terpusat: sesi + peran + kepemilikan ujian.
    rbac_authorize($action, $args);

    // Satu pintu masuk: parse -> auth/authorize (rbac_authorize) -> service.
    $result = service_dispatch($action, $args);
    if ($result === null) {
        // Fallback kompatibel untuk action lama yang belum dipetakan ke service.
        $callable = null;
        foreach (get_defined_functions()['user'] as $fn) {
            if (strcasecmp($fn, $action) === 0) {
                $callable = $fn;
                break;
            }
        }
        if ($callable === null) {
            throw new NotFoundException('Aksi belum diimplementasikan: ' . $action);
        }
        $result = call_user_func_array($callable, $args);
    }

    // Action penulisan membuat cache dashboard admin basi. getSettings/saveSettings
    // sudah mengurus cache settingsnya sendiri; di sini cukup dashboard.
    if (!str_starts_with(strtolower($action), 'get')) {
        admin_dashboard_invalidate();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (ApiException $e) {
    // Validation 422 / Forbidden 403 / NotFound 404 / Conflict 409 / Server 500.
    // RbacDenied mewaris ForbiddenException, jadi tetap 403.
    http_response_code($e->httpStatus());
    echo json_encode(
        ['error' => client_error_message($e->getMessage()), 'action' => $action, 'ref' => request_id()],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('[' . request_id() . '] [CBT API] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(
        ['error' => client_error_message($e->getMessage()), 'action' => $action, 'ref' => request_id()],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

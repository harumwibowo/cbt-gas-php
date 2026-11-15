<?php
/**
 * Endpoint RPC tunggal — padanan google.script.run pada aplikasi Apps Script.
 *
 * Pemakaian:  POST api.php?action=namaFungsi  body: {"args":[...]}
 *             GET  api.php?action=namaFungsi&args=[...]
 *
 * Selalu mengembalikan JSON. Bila fungsi melempar exception, status HTTP 500
 * dengan {"error": "..."} sehingga withFailureHandler di klien terpanggil.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

require_once APP_ROOT . '/modules/admin.php';
require_once APP_ROOT . '/modules/media.php';
require_once APP_ROOT . '/modules/sessions.php';
require_once APP_ROOT . '/modules/exams.php';
require_once APP_ROOT . '/modules/questions.php';
require_once APP_ROOT . '/modules/results.php';
require_once APP_ROOT . '/modules/student.php';
require_once APP_ROOT . '/modules/student_exam.php';
require_once APP_ROOT . '/modules/gemini.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Daftar aksi yang boleh dipanggil dari klien (whitelist). */
const ALLOWED_ACTIONS = [
    // Autentikasi & sesi
    'login', 'logout', 'getSessionStatus', 'GetSessionStatus',
    // Pengaturan
    'getSettings', 'saveSettings',
    // Admin: pengguna
    'getUsers', 'getStudentExamCards', 'getAdminData', 'saveUser', 'changePassword',
    'deleteUser', 'getStudentLoginSessions', 'resetStudentLoginSession', 'importUsers',
    // Master sesi
    'getSessions', 'saveSession', 'deleteSession',
    // Ujian
    'getTeacherExams', 'saveExam', 'deleteExam',
    // Soal & teks
    'getExamQuestions', 'saveQuestion', 'deleteQuestion', 'importQuestions', 'saveBulkQuestions',
    'getExamTexts', 'saveExamText', 'deleteExamText',
    // Hasil & pelanggaran
    'getExamResults', 'deleteStudentExamResult', 'getStudentViolations', 'regradeExamResults',
    // Alur siswa
    'getStudentAvailableExams', 'getStudentExamReview', 'startStudentExam',
    'autosaveExam', 'recordViolation', 'submitExam',
    'blockStudentExam', 'unblockStudentExam',
    // AI (Gemini)
    'getGeminiModels', 'testGeminiConnection', 'generateQuestionsWithGemini',
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
} elseif (isset($_GET['args'])) {
    $decodedArgs = json_decode((string) $_GET['args'], true);
    if (is_array($decodedArgs)) {
        $args = array_values($decodedArgs);
    }
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
    // Bootstrap skema otomatis (idempotent) agar aplikasi langsung siap pakai.
    ensure_schema();

    $callable = null;
    foreach (get_defined_functions()['user'] as $fn) {
        if (strcasecmp($fn, $action) === 0) {
            $callable = $fn;
            break;
        }
    }

    if ($callable === null) {
        throw new RuntimeException('Aksi belum diimplementasikan: ' . $action);
    }

    $result = call_user_func_array($callable, $args);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[CBT API] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(
        ['error' => $e->getMessage(), 'action' => $action],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

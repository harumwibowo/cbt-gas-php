<?php
/**
 * Lapisan layanan (Bagian 3.2).
 *
 * api.php sekarang hanya: parse request -> auth/authorize -> service_dispatch().
 * Service adalah satu-satunya tempat yang boleh memulai transaction untuk
 * operasi lintas tabel; modul tetap berisi implementasi fungsi yang sudah ada
 * (parity dengan Apps Script), sehingga perilaku tidak berubah.
 */
declare(strict_types=1);

abstract class BaseService
{
    /** Teruskan argumen RPC ke fungsi modul yang bersangkutan. */
    protected static function call(string $function, array $args)
    {
        if (!function_exists($function)) {
            throw new ServerErrorException('Fungsi layanan belum tersedia.');
        }
        return call_user_func_array($function, $args);
    }
}

final class AuthService extends BaseService
{
    public static function login(...$a) { return self::call('login', $a); }
    public static function logout(...$a) { return self::call('logout', $a); }
    public static function getSessionStatus(...$a) { return self::call('get_session_status', $a); }
    public static function changePassword(...$a) { return self::call('changePassword', $a); }
    public static function setup2fa(...$a) { return self::call('setup_two_factor', $a); }
    public static function verify2fa(...$a) { return self::call('verify_two_factor', $a); }
    public static function confirm2fa(...$a) { return self::call('confirm_two_factor', $a); }
    public static function disable2fa(...$a) { return self::call('disable_two_factor', $a); }
    public static function logoutAllDevices(...$a) { return self::call('logout_all_devices', $a); }
    public static function getActiveSessions(...$a) { return self::call('get_active_sessions', $a); }
}

final class SettingsService extends BaseService
{
    public static function getSettings(...$a) { return self::call('get_settings', $a); }
    public static function saveSettings(...$a) { return self::call('save_settings', $a); }
    public static function getApplicationVersion(...$a) { return self::call('getApplicationVersion', $a); }
    public static function updateApplication(...$a) { return self::call('updateApplication', $a); }
}

final class UserService extends BaseService
{
    public static function getUsers(...$a) { return self::call('getUsers', $a); }
    public static function getStudentExamCards(...$a) { return self::call('getStudentExamCards', $a); }
    public static function getAdminData(...$a) { return self::call('getAdminData', $a); }
    public static function saveUser(...$a) { return self::call('saveUser', $a); }
    public static function deleteUser(...$a) { return self::call('deleteUser', $a); }
    public static function resetUserPassword(...$a) { return self::call('resetUserPassword', $a); }
    public static function resetUsersPasswordBulk(...$a) { return self::call('resetUsersPasswordBulk', $a); }
    public static function deleteUsersBulk(...$a) { return self::call('deleteUsersBulk', $a); }
    public static function importUsers(...$a) { return self::call('importUsers', $a); }
    public static function getStudentLoginSessions(...$a) { return self::call('getStudentLoginSessions', $a); }
    public static function resetStudentLoginSession(...$a) { return self::call('resetStudentLoginSession', $a); }
    public static function resetStudentLoginSessionsBulk(...$a) { return self::call('resetStudentLoginSessionsBulk', $a); }
}

final class SessionService extends BaseService
{
    public static function getSessions(...$a) { return self::call('getSessions', $a); }
    public static function saveSession(...$a) { return self::call('saveSession', $a); }
    public static function deleteSession(...$a) { return self::call('deleteSession', $a); }
}

final class ExamService extends BaseService
{
    public static function getTeacherExams(...$a) { return self::call('getTeacherExams', $a); }
    public static function saveExam(...$a)
    {
        return db_transaction(static fn () => self::call('saveExam', $a));
    }
    // deleteExam sudah mengelola transaksinya sendiri di modules/exams.php.
    public static function deleteExam(...$a) { return self::call('deleteExam', $a); }
    public static function generateStudentTokens(...$a) { return self::call('generateStudentTokens', $a); }
}

final class QuestionService extends BaseService
{
    public static function getExamQuestions(...$a) { return self::call('getExamQuestions', $a); }
    public static function saveQuestion(...$a) { return self::call('saveQuestion', $a); }
    public static function deleteQuestion(...$a) { return self::call('deleteQuestion', $a); }
    // Keduanya sudah membuka transaksi sendiri di modules/questions.php.
    public static function importQuestions(...$a) { return self::call('importQuestions', $a); }
    public static function saveBulkQuestions(...$a) { return self::call('saveBulkQuestions', $a); }
    public static function getExamTexts(...$a) { return self::call('getExamTexts', $a); }
    public static function saveExamText(...$a) { return self::call('saveExamText', $a); }
    public static function deleteExamText(...$a) { return self::call('deleteExamText', $a); }
}

final class ResultService extends BaseService
{
    public static function getExamResults(...$a) { return self::call('getExamResults', $a); }
    public static function getExamResultDetail(...$a) { return self::call('getExamResultDetail', $a); }
    public static function deleteStudentExamResult(...$a) { return self::call('deleteStudentExamResult', $a); }
    public static function getStudentViolations(...$a) { return self::call('getStudentViolations', $a); }
    public static function regradeExamResults(...$a)
    {
        return db_transaction(static fn () => self::call('regradeExamResults', $a));
    }
}

final class StudentService extends BaseService
{
    public static function getStudentAvailableExams(...$a) { return self::call('getStudentAvailableExams', $a); }
    public static function getStudentExamReview(...$a) { return self::call('getStudentExamReview', $a); }
    public static function startStudentExam(...$a) { return self::call('startStudentExam', $a); }
    public static function autosaveExam(...$a) { return self::call('autosaveExam', $a); }
    public static function recordViolation(...$a) { return self::call('recordViolation', $a); }
    public static function submitExam(...$a) { return self::call('submitExam', $a); }
    public static function getExamTiming(...$a) { return self::call('getExamTiming', $a); }
    public static function blockStudentExam(...$a) { return self::call('blockStudentExam', $a); }
    public static function unblockStudentExam(...$a) { return self::call('unblockStudentExam', $a); }
}

final class MonitorService extends BaseService
{
    public static function getExamMonitor(...$a) { return self::call('getExamMonitor', $a); }
    public static function forceFinishExam(...$a) { return self::call('forceFinishExam', $a); }
}

final class AIService extends BaseService
{
    public static function getGeminiModels(...$a) { return self::call('getGeminiModels', $a); }
    public static function testGeminiConnection(...$a) { return self::call('testGeminiConnection', $a); }
    public static function generateQuestionsWithGemini(...$a) { return self::call('generateQuestionsWithGemini', $a); }
}

/**
 * Jalankan callback di dalam transaksi (commit saat sukses, rollback saat error).
 */
function db_transaction(callable $callback)
{
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        $result = $callback();
        if ($owns && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Petakan action RPC (case-insensitive) ke method service. */
function service_action_map(): array
{
    return [
        // auth & sesi
        'login' => [AuthService::class, 'login'],
        'logout' => [AuthService::class, 'logout'],
        'getsessionstatus' => [AuthService::class, 'getSessionStatus'],
        'changepassword' => [AuthService::class, 'changePassword'],
        'setup2fa' => [AuthService::class, 'setup2fa'],
        'verify2fa' => [AuthService::class, 'verify2fa'],
        'confirm2fa' => [AuthService::class, 'confirm2fa'],
        'disable2fa' => [AuthService::class, 'disable2fa'],
        'logoutalldevices' => [AuthService::class, 'logoutAllDevices'],
        'getactivesessions' => [AuthService::class, 'getActiveSessions'],
        // pengaturan & pembaruan
        'getsettings' => [SettingsService::class, 'getSettings'],
        'savesettings' => [SettingsService::class, 'saveSettings'],
        'getapplicationversion' => [SettingsService::class, 'getApplicationVersion'],
        'updateapplication' => [SettingsService::class, 'updateApplication'],
        // pengguna
        'getusers' => [UserService::class, 'getUsers'],
        'getstudentexamcards' => [UserService::class, 'getStudentExamCards'],
        'getadmindata' => [UserService::class, 'getAdminData'],
        'saveuser' => [UserService::class, 'saveUser'],
        'deleteuser' => [UserService::class, 'deleteUser'],
        'resetuserpassword' => [UserService::class, 'resetUserPassword'],
        'resetuserspasswordbulk' => [UserService::class, 'resetUsersPasswordBulk'],
        'deleteusersbulk' => [UserService::class, 'deleteUsersBulk'],
        'importusers' => [UserService::class, 'importUsers'],
        'getstudentloginsessions' => [UserService::class, 'getStudentLoginSessions'],
        'resetstudentloginsession' => [UserService::class, 'resetStudentLoginSession'],
        'resetstudentloginsessionsbulk' => [UserService::class, 'resetStudentLoginSessionsBulk'],
        // master sesi ujian
        'getsessions' => [SessionService::class, 'getSessions'],
        'savesession' => [SessionService::class, 'saveSession'],
        'deletesession' => [SessionService::class, 'deleteSession'],
        // ujian
        'getteacherexams' => [ExamService::class, 'getTeacherExams'],
        'saveexam' => [ExamService::class, 'saveExam'],
        'deleteexam' => [ExamService::class, 'deleteExam'],
        'generatestudenttokens' => [ExamService::class, 'generateStudentTokens'],
        // soal & teks
        'getexamquestions' => [QuestionService::class, 'getExamQuestions'],
        'savequestion' => [QuestionService::class, 'saveQuestion'],
        'deletequestion' => [QuestionService::class, 'deleteQuestion'],
        'importquestions' => [QuestionService::class, 'importQuestions'],
        'savebulkquestions' => [QuestionService::class, 'saveBulkQuestions'],
        'getexamtexts' => [QuestionService::class, 'getExamTexts'],
        'saveexamtext' => [QuestionService::class, 'saveExamText'],
        'deleteexamtext' => [QuestionService::class, 'deleteExamText'],
        // hasil & pelanggaran
        'getexamresults' => [ResultService::class, 'getExamResults'],
        'getexamresultdetail' => [ResultService::class, 'getExamResultDetail'],
        'deletestudentexamresult' => [ResultService::class, 'deleteStudentExamResult'],
        'getstudentviolations' => [ResultService::class, 'getStudentViolations'],
        'regradeexamresults' => [ResultService::class, 'regradeExamResults'],
        // alur siswa
        'getstudentavailableexams' => [StudentService::class, 'getStudentAvailableExams'],
        'getstudentexamreview' => [StudentService::class, 'getStudentExamReview'],
        'startstudentexam' => [StudentService::class, 'startStudentExam'],
        'autosaveexam' => [StudentService::class, 'autosaveExam'],
        'recordviolation' => [StudentService::class, 'recordViolation'],
        'submitexam' => [StudentService::class, 'submitExam'],
        'getexamtiming' => [StudentService::class, 'getExamTiming'],
        'blockstudentexam' => [StudentService::class, 'blockStudentExam'],
        'unblockstudentexam' => [StudentService::class, 'unblockStudentExam'],
        // monitor ujian
        'getexammonitor' => [MonitorService::class, 'getExamMonitor'],
        'forcefinishexam' => [MonitorService::class, 'forceFinishExam'],
        // AI
        'getgeminimodels' => [AIService::class, 'getGeminiModels'],
        'testgeminiconnection' => [AIService::class, 'testGeminiConnection'],
        'generatequestionswithgemini' => [AIService::class, 'generateQuestionsWithGemini'],
    ];
}

/**
 * Dispatch satu action ke service. Mengembalikan null bila action tidak ada
 * di map, sehingga api.php bisa memakai fallback fungsi lama.
 */
function service_dispatch(string $action, array $args)
{
    $map = service_action_map();
    $key = strtolower($action);
    if (!isset($map[$key])) {
        return null;
    }
    [$class, $method] = $map[$key];
    if (!class_exists($class) || !method_exists($class, $method)) {
        throw new ServerErrorException('Layanan untuk action ini belum tersedia.');
    }
    return call_user_func_array([$class, $method], $args);
}

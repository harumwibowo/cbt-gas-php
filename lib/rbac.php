<?php
/**
 * Otorisasi terpusat untuk endpoint RPC.
 *
 * Sebelumnya sebagian besar endpoint hanya memeriksa token, sehingga siswa
 * dapat memanggil fungsi admin/guru bila mengetahui namanya. Lapisan ini
 * memeriksa (1) sesi valid, (2) peran yang diizinkan, dan (3) kepemilikan
 * ujian untuk aksi yang menyentuh data ujian tertentu.
 */
declare(strict_types=1);

/** Exception khusus agar api.php dapat membalas HTTP 403. */
class RbacDenied extends RuntimeException
{
}

/** Aksi yang memang boleh diakses tanpa login. */
function rbac_public_actions(): array
{
    return [
        'login',
        'getsettings',
        'getsessionstatus',
    ];
}

/** Aksi khusus Admin. */
function rbac_admin_actions(): array
{
    return [
        'getusers',
        'getstudentexamcards',
        'getadmindata',
        'saveuser',
        'deleteuser',
        'importusers',
        'getstudentloginsessions',
        'resetstudentloginsession',
        'savesettings',
        'getsessions',
        'savesession',
        'deletesession',
        'updateapplication',
        'getapplicationversion',
    ];
}

/** Aksi yang boleh dilakukan Admin atau Guru (dengan cek kepemilikan ujian). */
function rbac_staff_actions(): array
{
    return [
        'getteacherexams',
        'saveexam',
        'deleteexam',
        'getexamquestions',
        'savequestion',
        'deletequestion',
        'importquestions',
        'savebulkquestions',
        'getexamtexts',
        'saveexamtext',
        'deleteexamtext',
        'getexamresults',
        'deletestudentexamresult',
        'getstudentviolations',
        'regradeexamresults',
        'unblockstudentexam',
        'getgeminimodels',
        'testgeminiconnection',
        'generatequestionswithgemini',
    ];
}

/** Aksi khusus siswa. */
function rbac_student_actions(): array
{
    return [
        'getstudentavailableexams',
        'getstudentexamreview',
        'startstudentexam',
        'autosaveexam',
        'recordviolation',
        'submitexam',
        'blockstudentexam',
    ];
}

/** Ambil peran (lowercase) seorang pengguna dari database. */
function rbac_role(string $username): string
{
    try {
        $row = db_one('SELECT role, status_aktif FROM users WHERE LOWER(TRIM(username)) = ? LIMIT 1', [strtolower(trim($username))]);
        if (!$row) {
            return '';
        }
        if (strtolower(trim(tostr($row['status_aktif']))) === 'nonaktif') {
            return '';
        }
        return strtolower(trim(tostr($row['role'])));
    } catch (Throwable $e) {
        return '';
    }
}

/** Apakah pengguna boleh mengelola ujian tertentu. */
function rbac_can_manage_exam(string $username, string $examId): bool
{
    $role = rbac_role($username);
    if ($role === 'admin') {
        return true;
    }
    if ($role !== 'guru' || $examId === '') {
        return false;
    }

    $exam = db_one('SELECT * FROM exams WHERE id_ujian = ?', [$examId]);
    if (!$exam) {
        return false;
    }
    return can_manage_exam($exam, $username);
}

/** Cari id_ujian dari id_soal. */
function rbac_exam_id_by_question(string $questionId): string
{
    if ($questionId === '') {
        return '';
    }
    $row = db_one('SELECT id_ujian FROM questions WHERE id_soal = ?', [$questionId]);
    return $row ? tostr($row['id_ujian']) : '';
}

/** Cari id_ujian dari id_teks. */
function rbac_exam_id_by_text(string $textId): string
{
    if ($textId === '') {
        return '';
    }
    $row = db_one('SELECT id_ujian FROM teks_ujian WHERE id_teks = ?', [$textId]);
    return $row ? tostr($row['id_ujian']) : '';
}

/**
 * Tentukan id_ujian yang terlibat pada sebuah aksi ('' bila tidak ada).
 */
function rbac_exam_id_from_args(string $action, array $args): string
{
    $payload = isset($args[2]) && is_array($args[2]) ? $args[2] : [];
    $arg2 = isset($args[2]) && !is_array($args[2]) ? tostr($args[2]) : '';

    switch ($action) {
        case 'saveexam':
            return tostr($payload['id_ujian'] ?? '');
        case 'savequestion':
            if (tostr($payload['id_soal'] ?? '') !== '') {
                return rbac_exam_id_by_question(tostr($payload['id_soal']));
            }
            return tostr($payload['id_ujian'] ?? '');
        case 'deletequestion':
            return rbac_exam_id_by_question($arg2);
        case 'saveexamtext':
            if (tostr($payload['id_teks'] ?? '') !== '') {
                return rbac_exam_id_by_text(tostr($payload['id_teks']));
            }
            return tostr($payload['id_ujian'] ?? '');
        case 'deleteexamtext':
            return rbac_exam_id_by_text($arg2);
        case 'importquestions':
        case 'savebulkquestions':
            return $arg2;
        case 'deleteexam':
        case 'getexamquestions':
        case 'getexamtexts':
        case 'getexamresults':
        case 'deletestudentexamresult':
        case 'getstudentviolations':
        case 'regradeexamresults':
        case 'unblockstudentexam':
            return $arg2;
        default:
            return '';
    }
}

/**
 * Validasi otorisasi. Melempar RbacDenied bila tidak diizinkan.
 */
function rbac_authorize(string $action, array $args): void
{
    $action = strtolower(trim($action));

    if (in_array($action, rbac_public_actions(), true)) {
        return;
    }

    $username = isset($args[0]) ? tostr($args[0]) : '';
    $token = isset($args[1]) ? tostr($args[1]) : '';
    if ($username === '' || $token === '' || !validate_session($username, $token)) {
        throw new RbacDenied('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $role = rbac_role($username);
    if ($role === '') {
        throw new RbacDenied('Akun tidak aktif atau tidak ditemukan.');
    }

    if (in_array($action, rbac_admin_actions(), true)) {
        if ($role !== 'admin') {
            throw new RbacDenied('Akses ditolak. Tindakan ini hanya untuk Admin.');
        }
        return;
    }

    if (in_array($action, rbac_student_actions(), true)) {
        if ($role !== 'siswa') {
            throw new RbacDenied('Akses ditolak. Tindakan ini hanya untuk siswa.');
        }
        return;
    }

    if (in_array($action, rbac_staff_actions(), true)) {
        if ($role !== 'admin' && $role !== 'guru') {
            throw new RbacDenied('Akses ditolak. Tindakan ini hanya untuk Admin atau Guru.');
        }
        if ($role === 'admin') {
            return;
        }

        $examId = rbac_exam_id_from_args($action, $args);
        if ($examId === '') {
            // Guru tanpa id_ujian tidak boleh mengambil rekap global.
            if (in_array($action, ['getexamresults', 'getexamquestions', 'getexamtexts'], true)) {
                throw new RbacDenied('Parameter id_ujian wajib diisi.');
            }
            return;
        }
        if (!rbac_can_manage_exam($username, $examId)) {
            throw new RbacDenied('Akses ditolak. Anda bukan pemilik atau kolaborator ujian ini.');
        }
        return;
    }

    // Aksi terautentikasi lain (logout, ganti password, daftar sesi, dll.)
    // sudah lolos validasi sesi di atas.
}

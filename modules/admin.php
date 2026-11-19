<?php
/**
 * Modul admin: pengguna, kartu ujian, dashboard admin, sesi login siswa,
 * dan impor pengguna (padanan modul 4 & 4b pada Code.gs).
 */
declare(strict_types=1);

/** Jumlah akun Admin yang masih aktif. */
function count_active_admins(): int
{
    return (int) db_val(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status_aktif <> 'nonaktif'",
        [],
        0
    );
}

/**
 * Daftar pengguna ringkas (padanan getUsers()).
 *
 * Opsional: getUsers(username, token, page, perPage) mengambil satu halaman
 * dari server. Tanpa argumen halaman, tetap mengembalikan seluruh pengguna
 * (kompatibel dengan klien lama) tetapi dibatasi 5000 baris agar tidak
 * terjadi pemindaian tak terbatas.
 */
function getUsers($username, $token, $page = 0, $perPage = 0): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $page = max(1, (int) $page);
        $perPage = max(0, min(100, (int) $perPage));
        $limitSql = $perPage > 0
            ? ' LIMIT ' . (($page - 1) * $perPage) . ', ' . $perPage
            : ' LIMIT 5000';

        $users = [];
        foreach (db_all('SELECT * FROM users ORDER BY created_at ASC, username ASC' . $limitSql) as $row) {
            $uName = trim(tostr($row['username']));
            if ($uName === '') {
                continue;
            }
            $users[] = [
                'id_user'      => tostr($row['id_user']),
                'username'     => $uName,
                'nama_lengkap' => tostr($row['nama_lengkap']),
                'role'         => tostr($row['role']) !== '' ? tostr($row['role']) : 'Siswa',
                'kelas'        => tostr($row['kelas']) !== '' ? tostr($row['kelas']) : '-',
                'sesi'         => tostr($row['sesi']) !== '' ? tostr($row['sesi']) : '-',
                'status_aktif' => tostr($row['status_aktif']) !== '' ? tostr($row['status_aktif']) : 'Aktif',
            ];
        }

        $response = ['success' => true, 'users' => $users];
        if ($perPage > 0) {
            $response['total'] = (int) db_val('SELECT COUNT(*) FROM users', [], 0);
            $response['page'] = $page;
            $response['per_page'] = $perPage;
        }
        return $response;
    } catch (Throwable $e) {
        return err('Gagal memuat pengguna: ' . client_error_message($e->getMessage()));
    }
}

/** Data siswa untuk cetak kartu ujian (termasuk password). */
function getStudentExamCards($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $callerRole = strtolower(trim(current_role(tostr($username))));
        if ($callerRole !== 'admin' && $callerRole !== 'guru') {
            return err('Hanya Admin atau Guru yang dapat mencetak kartu ujian.');
        }

        $students = [];
        $seen = [];
        foreach (db_all('SELECT * FROM users ORDER BY kelas ASC, nama_lengkap ASC') as $row) {
            if (strtolower(trim(tostr($row['role']))) !== 'siswa') {
                continue;
            }
            if (strtolower(trim(tostr($row['status_aktif']))) === 'nonaktif') {
                continue;
            }
            $uname = trim(tostr($row['username']));
            if ($uname === '') {
                continue;
            }
            $key = strtolower($uname);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $students[] = [
                'username'           => $uname,
                'password'           => '',
                'password_available' => false,
                'nama_lengkap' => tostr($row['nama_lengkap']) !== '' ? tostr($row['nama_lengkap']) : $uname,
                'kelas'        => tostr($row['kelas']) !== '' ? tostr($row['kelas']) : '-',
                'sesi'         => tostr($row['sesi']) !== '' ? tostr($row['sesi']) : '-',
            ];
        }

        usort($students, static function ($a, $b) {
            $cmp = cmp_text($a['kelas'], $b['kelas']);
            return $cmp !== 0 ? $cmp : cmp_text($a['nama_lengkap'], $b['nama_lengkap']);
        });

        return ['success' => true, 'students' => $students];
    } catch (Throwable $e) {
        return err('Gagal memuat data kartu ujian: ' . client_error_message($e->getMessage()));
    }
}

/** Batalkan cache dashboard admin (dipanggil api.php setelah action mutasi). */
function admin_dashboard_invalidate(): void
{
    cbt_cache_delete('cbt.admin.dashboard');
}

/** Data komprehensif dashboard admin (padanan getAdminData()). */
function getAdminData($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        // Dashboard menggabungkan users + logs + exams + katalog. Cache 30 detik
        // di APCu/file; dibatalkan segera setelah ada action penulisan.
        $cached = cbt_cache_get('cbt.admin.dashboard');
        if (is_array($cached) && !empty($cached['success'])) {
            return $cached;
        }

        $stats = [
            'totalStudents'    => 0,
            'totalTeachers'    => 0,
            'totalAdmins'      => 0,
            'totalExams'       => 0,
            'totalSubmissions' => 0,
        ];

        $users = [];
        // Muat users sekali lalu pakai ulang untuk katalog (sebelumnya
        // catalog_lists() memuat seluruh tabel users untuk kedua kalinya).
        $userRows = db_all('SELECT * FROM users ORDER BY created_at ASC, username ASC');
        foreach ($userRows as $row) {
            $uName = trim(tostr($row['username']));
            if ($uName === '') {
                continue;
            }
            $uRole = tostr($row['role']) !== '' ? tostr($row['role']) : 'Siswa';
            $users[] = [
                'id_user'      => tostr($row['id_user']),
                'username'     => $uName,
                'nama_lengkap' => tostr($row['nama_lengkap']),
                'role'         => $uRole,
                'kelas'        => tostr($row['kelas']) !== '' ? tostr($row['kelas']) : '-',
                'sesi'         => tostr($row['sesi']) !== '' ? tostr($row['sesi']) : '-',
                'tingkat'      => tostr($row['tingkat']) !== '' ? tostr($row['tingkat']) : '-',
                'status_aktif' => tostr($row['status_aktif']) !== '' ? tostr($row['status_aktif']) : 'Aktif',
            ];

            $lowerRole = strtolower($uRole);
            if ($lowerRole === 'siswa') {
                $stats['totalStudents']++;
            } elseif ($lowerRole === 'guru') {
                $stats['totalTeachers']++;
            } elseif ($lowerRole === 'admin') {
                $stats['totalAdmins']++;
            }
        }

        $stats['totalExams'] = (int) db_val('SELECT COUNT(*) FROM exams');
        $stats['totalSubmissions'] = (int) db_val("SELECT COUNT(*) FROM results WHERE status = 'SUBMITTED'");

        $sessions = [];
        foreach (db_all('SELECT * FROM sesi ORDER BY nama_sesi ASC') as $row) {
            $sId = tostr($row['id_sesi']);
            $sName = tostr($row['nama_sesi']);
            if ($sId === '' && $sName === '') {
                continue;
            }
            $sessions[] = [
                'id_sesi'    => $sId,
                'nama_sesi'  => $sName,
                'keterangan' => tostr($row['keterangan']),
            ];
        }

        $logs = [];
        foreach (db_all('SELECT * FROM logs ORDER BY id DESC LIMIT 50') as $row) {
            $logs[] = [
                'timestamp'      => dt_display($row['timestamp']),
                'username'       => tostr($row['username']),
                'jenis_kejadian' => tostr($row['jenis_kejadian']),
                'keterangan'     => tostr($row['keterangan']),
            ];
        }

        $qCounts = question_counts();
        $submitCounts = submitted_counts();
        $exams = [];
        foreach (db_all('SELECT * FROM exams ORDER BY created_at ASC, id_ujian ASC') as $row) {
            $examId = trim(tostr($row['id_ujian']));
            if ($examId === '') {
                continue;
            }
            $exams[] = exam_to_client($row, $qCounts[$examId] ?? 0, $submitCounts[$examId] ?? 0);
        }

        $lists = catalog_lists(false, $userRows);

        $result = [
            'success'            => true,
            'stats'              => $stats,
            'users'              => $users,
            'sessions'           => $sessions,
            'logs'               => $logs,
            'exams'              => $exams,
            'available_classes'  => $lists['available_classes'],
            'available_grades'   => $lists['available_grades'],
            'class_grade_map'    => $lists['class_grade_map'],
            'available_sessions' => $lists['available_sessions'],
            'available_students' => $lists['available_students'],
            'available_teachers' => $lists['available_teachers'],
        ];
        cbt_cache_set('cbt.admin.dashboard', $result, 30);
        return $result;
    } catch (Throwable $e) {
        return err('Gagal memuat data admin: ' . client_error_message($e->getMessage()));
    }
}

/** Tambah / ubah pengguna (padanan saveUser()). */
function saveUser($username, $token, $userData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $userData = is_array($userData) ? $userData : [];

    try {
        $targetUsername = normalize_username($userData['username'] ?? '');
        if ($targetUsername === '') {
            return err('Username tidak boleh kosong.');
        }
        if (!is_valid_username($targetUsername)) {
            return err('Username hanya boleh berisi huruf, angka, titik, garis bawah, atau strip (3-50 karakter).');
        }

        $isEdit = tostr($userData['id_user'] ?? '') !== '';
        $existing = null;

        foreach (db_all('SELECT * FROM users') as $row) {
            $rowId = tostr($row['id_user']);
            $rowUsername = strtolower(trim(tostr($row['username'])));
            if ($isEdit && $rowId === tostr($userData['id_user'])) {
                $existing = $row;
            } elseif ($rowUsername === $targetUsername) {
                return err('Username sudah digunakan oleh akun lain.');
            }
        }

        $roleInput = strtolower(trim(tostr($userData['role'] ?? 'siswa')));
        $roleMap = ['admin' => 'Admin', 'guru' => 'Guru', 'siswa' => 'Siswa'];
        $role = $roleMap[$roleInput] ?? 'Siswa';
        $sesiVal = sanitize_plain_text($userData['sesi'] ?? '') !== '' ? limit_text(sanitize_plain_text($userData['sesi']), 50) : '-';
        $tktVal = $role === 'Siswa'
            ? (sanitize_plain_text($userData['tingkat'] ?? '') !== '' ? limit_text(sanitize_plain_text($userData['tingkat']), 50) : '-')
            : '-';
        $nama = limit_text(sanitize_plain_text($userData['nama_lengkap'] ?? ''), 100);
        if ($nama === '') {
            return err('Nama lengkap wajib diisi.');
        }
        $kelas = sanitize_plain_text($userData['kelas'] ?? '') !== '' ? limit_text(sanitize_plain_text($userData['kelas']), 50) : '-';
        $statusInput = strtolower(trim(tostr($userData['status_aktif'] ?? 'aktif')));
        $status = $statusInput === 'nonaktif' ? 'Nonaktif' : 'Aktif';

        db()->beginTransaction();

        if ($isEdit) {
            if ($existing === null) {
                db()->rollBack();
                return err('Data pengguna tidak ditemukan untuk diedit.');
            }
            $roleChanged = strtolower($role) !== strtolower(tostr($existing['role']));
            $wasActiveAdmin = strtolower(trim(tostr($existing['role']))) === 'admin'
                && strtolower(trim(tostr($existing['status_aktif']))) !== 'nonaktif';
            $willBeActiveAdmin = strtolower($role) === 'admin' && strtolower($status) !== 'nonaktif';
            if ($wasActiveAdmin && !$willBeActiveAdmin && count_active_admins() <= 1) {
                db()->rollBack();
                return err('Tidak dapat menonaktifkan atau mengubah peran Admin aktif terakhir.');
            }

            $passwordBaru = trim(tostr($userData['password'] ?? ''));

            if ($passwordBaru !== '') {
                [$newHash, $newEnc] = password_fields($passwordBaru);
                db_exec(
                    'UPDATE users SET username = ?, password = ?, password_enc = NULL, nama_lengkap = ?, role = ?, kelas = ?,
                        status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                    [$targetUsername, $newHash, $nama, $role, $kelas, $status, $sesiVal, $tktVal, tostr($existing['id_user'])]
                );
                // Password berubah: semua sesi lama tidak berlaku lagi.
                session_revoke_all($targetUsername);
                db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($existing['id_user'])]);
            } else {
                // Password tidak diubah: nilai lama dipertahankan.
                db_exec(
                    'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, kelas = ?,
                        status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                    [$targetUsername, $nama, $role, $kelas, $status, $sesiVal, $tktVal, tostr($existing['id_user'])]
                );
            }
            // Akun nonaktif / perubahan peran mengakhiri sesi aktif
            if (strtolower($status) === 'nonaktif' || $roleChanged) {
                session_revoke_all($targetUsername);
                db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($existing['id_user'])]);
            }

            // Ganti username: ikutkan referensi agar riwayat tidak yatim.
            $oldUsername = strtolower(trim(tostr($existing['username'])));
            if ($oldUsername !== $targetUsername) {
                db_exec(
                    'UPDATE results SET username_siswa = ? WHERE username_siswa = ?',
                    [$targetUsername, $oldUsername]
                );
                db_exec(
                    'UPDATE exams SET id_guru = ? WHERE id_guru = ?',
                    [$targetUsername, $oldUsername]
                );
                db_exec('UPDATE sessions SET username = ? WHERE username = ?', [$targetUsername, $oldUsername]);
                // Kolaborator disimpan sebagai daftar dipisah koma.
                foreach (db_all("SELECT id_ujian, kolaborator FROM exams WHERE kolaborator <> ''") as $exRow) {
                    $members = split_list(tostr($exRow['kolaborator']));
                    $changed = false;
                    foreach ($members as $mi => $member) {
                        if (strtolower(trim($member)) === $oldUsername) {
                            $members[$mi] = $targetUsername;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        db_exec('UPDATE exams SET kolaborator = ? WHERE id_ujian = ?', [implode(', ', $members), tostr($exRow['id_ujian'])]);
                    }
                }
            }

            db()->commit(); current_user_invalidate();
            log_activity(tostr($username), 'Update User', 'Mengupdate user: ' . $targetUsername . ' (Tingkat: ' . $tktVal . ', Sesi: ' . $sesiVal . ')');
            return ['success' => true, 'message' => 'Pengguna berhasil diperbarui.'];
        }

        $pass = trim(tostr($userData['password'] ?? '')) !== '' ? trim(tostr($userData['password'])) : '123456';
        [$passHash, $passEnc] = password_fields($pass);
        db_exec(
            'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [new_id('USR'), $targetUsername, $passHash, null, $nama, $role, $kelas, null, $status, $sesiVal, $tktVal]
        );
        db()->commit(); current_user_invalidate();

        log_activity(tostr($username), 'Create User', 'Menambahkan user baru: ' . $targetUsername . ' (' . $role . ', Tingkat: ' . $tktVal . ', Sesi: ' . $sesiVal . ')');
        return ['success' => true, 'message' => 'Pengguna baru berhasil ditambahkan.'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return err('Gagal menyimpan pengguna: ' . client_error_message($e->getMessage()));
    }
}

/** Ubah password akun sendiri (padanan changePassword()). */
function changePassword($username, $token, $currentPassword, $newPassword, $confirmPassword): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $currentPassword = trim(tostr($currentPassword));
    $newPassword = trim(tostr($newPassword));
    $confirmPassword = trim(tostr($confirmPassword));

    if ($currentPassword === '') {
        return err('Password saat ini wajib diisi.');
    }
    if ($newPassword === '') {
        return err('Password baru wajib diisi.');
    }
    $policyError = password_policy_error((string) $newPassword, tostr($username));
    if ($policyError !== null) {
        return err($policyError);
    }
    if ($newPassword !== $confirmPassword) {
        return err('Konfirmasi password baru tidak cocok.');
    }
    if ($currentPassword === $newPassword) {
        return err('Password baru tidak boleh sama dengan password saat ini.');
    }

    try {
        $row = db_one('SELECT * FROM users WHERE username = ?', [strtolower(trim(tostr($username)))]);
        if (!$row) {
            return err('Akun pengguna tidak ditemukan.');
        }
        if (!verify_password($currentPassword, $row['password'] ?? '')) {
            return err('Password saat ini salah. Silakan periksa kembali.');
        }

        [$newHash, $newEnc] = password_fields($newPassword);
        db_exec('UPDATE users SET password = ?, password_enc = NULL, must_change_password = 0 WHERE id_user = ?',
            [$newHash, tostr($row['id_user'])]);
        current_user_invalidate(tostr($username));
        // Perangkat lain harus login ulang dengan password baru.
        db_exec(
            'UPDATE sessions SET revoked_at = ? WHERE username = ? AND token_hash <> ? AND revoked_at IS NULL',
            [now_dt(), strtolower(trim(tostr($username))), session_token_hash(tostr($token))]
        );
        log_activity(tostr($username), 'Change Password', 'Pengguna berhasil memperbarui password akun.');

        return ['success' => true, 'message' => 'Password berhasil diperbarui!'];
    } catch (Throwable $e) {
        return err('Gagal memperbarui password: ' . client_error_message($e->getMessage()));
    }
}

/** Hapus pengguna (padanan deleteUser()). */
function deleteUser($username, $token, $id_user): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }

    try {
        $row = db_one('SELECT * FROM users WHERE id_user = ?', [tostr($id_user)]);
        if (!$row) {
            return err('Pengguna tidak ditemukan.');
        }
        $deletedUsername = tostr($row['username']);
        if (strtolower($deletedUsername) === 'admin') {
            return err('Akun Super Admin utama tidak boleh dihapus.');
        }
        if (strtolower(trim(tostr($row['role']))) === 'admin'
            && strtolower(trim(tostr($row['status_aktif']))) !== 'nonaktif'
            && count_active_admins() <= 1) {
            return err('Tidak dapat menghapus Admin aktif terakhir.');
        }

        // Anonimkan riwayat, jangan hapus bukti ujian. Username anonim harus
        // unik agar tidak melanggar unique result per percobaan.
        $anonName = 'deleted:' . limit_text(tostr($id_user), 80);
        db()->beginTransaction();
        session_revoke_all($deletedUsername);
        db_exec(
            'UPDATE results SET username_siswa = ? WHERE username_siswa = ?',
            [$anonName, strtolower(trim($deletedUsername))]
        );
        db_exec(
            'UPDATE logs SET username = ? WHERE username = ?',
            [$anonName, strtolower(trim($deletedUsername))]
        );
        db_exec('DELETE FROM users WHERE id_user = ?', [tostr($id_user)]);
        db()->commit(); current_user_invalidate();

        log_activity(tostr($username), 'Delete User', 'Menghapus user: ' . $deletedUsername);

        return ['success' => true, 'message' => 'Pengguna berhasil dihapus.'];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return err(client_error_message($e->getMessage()));
    }
}

/** Daftar siswa + status sesi login aktif (padanan getStudentLoginSessions()). */
function getStudentLoginSessions($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $callerRole = strtolower(trim(current_role(tostr($username))));
        if ($callerRole !== 'admin' && $callerRole !== 'guru') {
            return err('Hanya Admin atau Guru yang dapat mengakses menu Login Siswa.');
        }

        $maxSessions = get_max_student_sessions();

        // Login terakhir per siswa: satu query agregat, bukan memindai seluruh
        // tabel log di PHP.
        $lastLogins = [];
        foreach (db_all(
            "SELECT username AS lname, MAX(`timestamp`) AS last_ts
             FROM logs WHERE jenis_kejadian = 'Login' GROUP BY username"
        ) as $logRow) {
            $logUser = strtolower(trim(tostr($logRow['lname'])));
            if ($logUser !== '') {
                $lastLogins[$logUser] = dt_display($logRow['last_ts']);
            }
        }

        // Jumlah sesi aktif per siswa juga diambil sekaligus.
        $activeSessionMap = [];
        foreach (db_all(
            'SELECT username, COUNT(*) AS total FROM sessions
             WHERE revoked_at IS NULL AND expires_at > ? GROUP BY username',
            [now_dt()]
        ) as $sesRow) {
            $activeSessionMap[strtolower(trim(tostr($sesRow['username'])))] = (int) $sesRow['total'];
        }

        $students = [];
        $classMap = [];
        $totalOnline = 0;

        foreach (db_all('SELECT * FROM users ORDER BY nama_lengkap ASC, username ASC') as $row) {
            if (strtolower(trim(tostr($row['role']))) !== 'siswa') {
                continue;
            }
            $uname = trim(tostr($row['username']));
            if ($uname === '') {
                continue;
            }
            $activeCount = (int) ($activeSessionMap[strtolower($uname)] ?? 0);
            $kelas = tostr($row['kelas']) !== '' ? tostr($row['kelas']) : '-';
            if ($kelas !== '' && $kelas !== '-') {
                $classMap[$kelas] = true;
            }
            if ($activeCount > 0) {
                $totalOnline++;
            }

            $students[] = [
                'id_user'         => tostr($row['id_user']),
                'username'        => $uname,
                'nama_lengkap'    => tostr($row['nama_lengkap']),
                'kelas'           => $kelas,
                'sesi'            => tostr($row['sesi']) !== '' ? tostr($row['sesi']) : '-',
                'tingkat'         => tostr($row['tingkat']) !== '' ? tostr($row['tingkat']) : '-',
                'status_aktif'    => tostr($row['status_aktif']) !== '' ? tostr($row['status_aktif']) : 'Aktif',
                'active_sessions' => $activeCount,
                'max_sessions'    => $maxSessions,
                'is_online'       => $activeCount > 0,
                'last_login'      => $lastLogins[strtolower($uname)] ?? '',
            ];
        }

        usort($students, static function ($a, $b) {
            $cmp = cmp_text($a['nama_lengkap'], $b['nama_lengkap']);
            return $cmp !== 0 ? $cmp : cmp_text($a['username'], $b['username']);
        });

        $classes = array_keys($classMap);
        sort($classes, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'success'        => true,
            'students'       => $students,
            'classes'        => $classes,
            'max_sessions'   => $maxSessions,
            'total_online'   => $totalOnline,
            'total_students' => count($students),
        ];
    } catch (Throwable $e) {
        return err('Gagal memuat sesi login siswa: ' . client_error_message($e->getMessage()));
    }
}

/** Reset / keluarkan semua sesi login seorang siswa. */
function resetStudentLoginSession($username, $token, $targetUsername): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $target = strtolower(trim(tostr($targetUsername)));
        if ($target === '') {
            return err('Username siswa tidak boleh kosong.');
        }

        $callerRole = strtolower(trim(current_role(tostr($username))));
        if ($callerRole !== 'admin' && $callerRole !== 'guru') {
            return err('Hanya Admin atau Guru yang dapat mereset sesi login siswa.');
        }

        $row = db_one('SELECT * FROM users WHERE username = ?', [$target]);
        if (!$row) {
            return err('Siswa tidak ditemukan.');
        }
        if (strtolower(trim(tostr($row['role']))) !== 'siswa') {
            return err('Reset sesi login hanya dapat dilakukan untuk akun siswa.');
        }

        $targetName = tostr($row['nama_lengkap']) !== '' ? tostr($row['nama_lengkap']) : $target;
        $activeCount = session_count_active($target);

        if ($activeCount === 0) {
            log_activity(tostr($username), 'Reset Login Siswa', 'Tidak ada sesi login aktif pada siswa: ' . $target);
            return ['success' => true, 'message' => 'Tidak ada sesi login aktif pada akun ' . $targetName . '.'];
        }

        session_revoke_all($target);
        db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($row['id_user'])]);
        log_activity(tostr($username), 'Reset Login Siswa', 'Mengeluarkan ' . $activeCount . ' sesi login siswa: ' . $target . ' (' . $targetName . ')');

        return [
            'success' => true,
            'message' => 'Sesi login ' . $targetName . ' berhasil direset. ' . $activeCount . ' perangkat telah dikeluarkan.',
        ];
    } catch (Throwable $e) {
        return err('Gagal mereset sesi login: ' . client_error_message($e->getMessage()));
    }
}

/** Impor pengguna massal (padanan importUsers()). */
function importUsers($username, $token, $usersList): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    if (!is_array($usersList) || count($usersList) === 0) {
        return err('Data pengguna yang akan diimpor kosong.');
    }

    try {
        $existingUsers = [];
        foreach (db_all('SELECT id_user, username AS lname FROM users') as $row) {
            $lu = strtolower(trim(tostr($row['lname'])));
            if ($lu !== '') {
                $existingUsers[$lu] = tostr($row['id_user']);
            }
        }

        $addedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        db()->beginTransaction();

        foreach ($usersList as $item) {
            if (!is_array($item)) {
                $skippedCount++;
                continue;
            }
            $rawUsername = trim(tostr($item['username'] ?? ''));
            if ($rawUsername === '') {
                $skippedCount++;
                continue;
            }

            $targetUsername = normalize_username($rawUsername);
            if (!is_valid_username($targetUsername)) {
                $skippedCount++;
                continue;
            }
            $namaLengkap = limit_text(sanitize_plain_text($item['nama_lengkap'] ?? ($item['nama'] ?? '')), 100);
            if ($namaLengkap === '') {
                $namaLengkap = $rawUsername;
            }

            $rawRole = trim(tostr($item['role'] ?? ($item['peran'] ?? '')));
            $roleMap = ['admin' => 'Admin', 'guru' => 'Guru', 'siswa' => 'Siswa'];
            $role = $roleMap[strtolower($rawRole)] ?? 'Siswa';

            $tingkat = limit_text(sanitize_plain_text($item['tingkat'] ?? ($item['tingkat_kelas'] ?? ($item['level'] ?? ($item['grade'] ?? '')))), 50);
            $tingkat = $tingkat !== '' ? $tingkat : '-';
            $kelas = limit_text(sanitize_plain_text($item['kelas'] ?? ($item['rombel'] ?? '')), 50);
            $kelas = $kelas !== '' ? $kelas : '-';
            $sesi = limit_text(sanitize_plain_text($item['sesi'] ?? ($item['session'] ?? ($item['sesi_ujian'] ?? ''))), 50);
            $sesi = $sesi !== '' ? $sesi : '-';
            $password = trim(tostr($item['password'] ?? ''));
            $statusInput = strtolower(trim(tostr($item['status_aktif'] ?? ($item['status'] ?? 'aktif'))));
            $status = $statusInput === 'nonaktif' ? 'Nonaktif' : 'Aktif';
            $finalTingkat = $role === 'Siswa' ? $tingkat : '-';

            if (isset($existingUsers[$targetUsername])) {
                $existingId = $existingUsers[$targetUsername];
                $prevRow = db_one('SELECT role, status_aktif FROM users WHERE id_user = ?', [$existingId]);
                $prevWasActiveAdmin = $prevRow
                    && strtolower(trim(tostr($prevRow['role']))) === 'admin'
                    && strtolower(trim(tostr($prevRow['status_aktif']))) !== 'nonaktif';
                $nextIsActiveAdmin = $role === 'Admin' && $status !== 'Nonaktif';
                if ($prevWasActiveAdmin && !$nextIsActiveAdmin && count_active_admins() <= 1) {
                    $skippedCount++;
                    continue;
                }
                if ($password !== '') {
                    [$impHash, $impEnc] = password_fields($password);
                    db_exec(
                        'UPDATE users SET username = ?, password = ?, password_enc = NULL, nama_lengkap = ?, role = ?, kelas = ?,
                            status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                        [$targetUsername, $impHash, $namaLengkap, $role, $kelas, $status, $sesi, $finalTingkat, $existingId]
                    );
                } else {
                    db_exec(
                        'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, kelas = ?,
                            status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                        [$targetUsername, $namaLengkap, $role, $kelas, $status, $sesi, $finalTingkat, $existingId]
                    );
                }
                // Impor dapat mengubah password/role/status: paksa login ulang.
                session_revoke_all($targetUsername);
                db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [$existingId]);
                $updatedCount++;
            } else {
                $password = $password !== '' ? $password : '123456';
                [$impHash2, $impEnc2] = password_fields($password);
                db_exec(
                    'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [new_id('USR'), $targetUsername, $impHash2, null, $namaLengkap, $role, $kelas, null, $status, $sesi, $finalTingkat]
                );
                $existingUsers[$targetUsername] = true;
                $addedCount++;
            }
        }

        db()->commit(); current_user_invalidate();

        log_activity(tostr($username), 'Import Users', 'Mengimpor pengguna: ' . $addedCount . ' baru, ' . $updatedCount . ' diperbarui.');

        $message = 'Import berhasil! ' . $addedCount . ' pengguna baru ditambahkan, ' . $updatedCount . ' diperbarui'
            . ($skippedCount > 0 ? ', ' . $skippedCount . ' baris dilewati (username kosong).' : '.');

        return [
            'success' => true,
            'message' => $message,
            'added'   => $addedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
        ];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return err('Gagal mengimpor data pengguna: ' . client_error_message($e->getMessage()));
    }
}

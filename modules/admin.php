<?php
/**
 * Modul admin: pengguna, kartu ujian, dashboard admin, sesi login siswa,
 * dan impor pengguna (padanan modul 4 & 4b pada Code.gs).
 */
declare(strict_types=1);

/** Daftar pengguna ringkas (padanan getUsers()). */
function getUsers($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $users = [];
        foreach (db_all('SELECT * FROM users ORDER BY created_at ASC, username ASC') as $row) {
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

        return ['success' => true, 'users' => $users];
    } catch (Throwable $e) {
        return err('Gagal memuat pengguna: ' . $e->getMessage());
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
                'username'     => $uname,
                'password'     => password_for_display($row),
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
        return err('Gagal memuat data kartu ujian: ' . $e->getMessage());
    }
}

/** Data komprehensif dashboard admin (padanan getAdminData()). */
function getAdminData($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    try {
        $stats = [
            'totalStudents'    => 0,
            'totalTeachers'    => 0,
            'totalAdmins'      => 0,
            'totalExams'       => 0,
            'totalSubmissions' => 0,
        ];

        $users = [];
        foreach (db_all('SELECT * FROM users ORDER BY created_at ASC, username ASC') as $row) {
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

        $lists = catalog_lists();

        return [
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
    } catch (Throwable $e) {
        return err('Gagal memuat data admin: ' . $e->getMessage());
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
        $targetUsername = strtolower(trim(tostr($userData['username'] ?? '')));
        if ($targetUsername === '') {
            return err('Username tidak boleh kosong.');
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

        $role = tostr($userData['role'] ?? '') !== '' ? tostr($userData['role']) : 'Siswa';
        $sesiVal = trim(tostr($userData['sesi'] ?? '')) !== '' ? trim(tostr($userData['sesi'])) : '-';
        $tktVal = strtolower($role) === 'siswa'
            ? (trim(tostr($userData['tingkat'] ?? '')) !== '' ? trim(tostr($userData['tingkat'])) : '-')
            : '-';
        $nama = tostr($userData['nama_lengkap'] ?? '');
        $kelas = tostr($userData['kelas'] ?? '') !== '' ? tostr($userData['kelas']) : '-';
        $status = tostr($userData['status_aktif'] ?? '') !== '' ? tostr($userData['status_aktif']) : 'Aktif';

        if ($isEdit) {
            if ($existing === null) {
                return err('Data pengguna tidak ditemukan untuk diedit.');
            }
            $roleChanged = strtolower($role) !== strtolower(tostr($existing['role']));
            $passwordBaru = trim(tostr($userData['password'] ?? ''));

            if ($passwordBaru !== '') {
                [$newHash, $newEnc] = password_fields($passwordBaru);
                db_exec(
                    'UPDATE users SET username = ?, password = ?, password_enc = ?, nama_lengkap = ?, role = ?, kelas = ?,
                        status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                    [$targetUsername, $newHash, $newEnc, $nama, $role, $kelas, $status, $sesiVal, $tktVal, tostr($existing['id_user'])]
                );
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
                db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($existing['id_user'])]);
            }

            log_activity(tostr($username), 'Update User', 'Mengupdate user: ' . $targetUsername . ' (Tingkat: ' . $tktVal . ', Sesi: ' . $sesiVal . ')');
            return ['success' => true, 'message' => 'Pengguna berhasil diperbarui.'];
        }

        $pass = trim(tostr($userData['password'] ?? '')) !== '' ? trim(tostr($userData['password'])) : '123456';
        [$passHash, $passEnc] = password_fields($pass);
        db_exec(
            'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [new_id('USR'), $targetUsername, $passHash, $passEnc, $nama, $role, $kelas, null, $status, $sesiVal, $tktVal]
        );

        log_activity(tostr($username), 'Create User', 'Menambahkan user baru: ' . $targetUsername . ' (' . $role . ', Tingkat: ' . $tktVal . ', Sesi: ' . $sesiVal . ')');
        return ['success' => true, 'message' => 'Pengguna baru berhasil ditambahkan.'];
    } catch (Throwable $e) {
        return err('Gagal menyimpan pengguna: ' . $e->getMessage());
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
    if (strlen($newPassword) < 5) {
        return err('Password baru minimal harus 5 karakter.');
    }
    if ($newPassword !== $confirmPassword) {
        return err('Konfirmasi password baru tidak cocok.');
    }
    if ($currentPassword === $newPassword) {
        return err('Password baru tidak boleh sama dengan password saat ini.');
    }

    try {
        $row = db_one('SELECT * FROM users WHERE LOWER(TRIM(username)) = ?', [strtolower(trim(tostr($username)))]);
        if (!$row) {
            return err('Akun pengguna tidak ditemukan.');
        }
        if (!verify_password($currentPassword, $row['password'] ?? '')) {
            return err('Password saat ini salah. Silakan periksa kembali.');
        }

        [$newHash, $newEnc] = password_fields($newPassword);
        db_exec('UPDATE users SET password = ?, password_enc = ? WHERE id_user = ?',
            [$newHash, $newEnc, tostr($row['id_user'])]);
        log_activity(tostr($username), 'Change Password', 'Pengguna berhasil memperbarui password akun.');

        return ['success' => true, 'message' => 'Password berhasil diperbarui!'];
    } catch (Throwable $e) {
        return err('Gagal memperbarui password: ' . $e->getMessage());
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

        db_exec('DELETE FROM users WHERE id_user = ?', [tostr($id_user)]);
        log_activity(tostr($username), 'Delete User', 'Menghapus user: ' . $deletedUsername);

        return ['success' => true, 'message' => 'Pengguna berhasil dihapus.'];
    } catch (Throwable $e) {
        return err($e->getMessage());
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

        // Login terakhir per siswa (pindai log terbaru ke bawah)
        $lastLogins = [];
        foreach (db_all("SELECT username, timestamp FROM logs WHERE LOWER(TRIM(jenis_kejadian)) = 'login' ORDER BY id DESC") as $logRow) {
            $logUser = strtolower(trim(tostr($logRow['username'])));
            if ($logUser !== '' && !isset($lastLogins[$logUser])) {
                $lastLogins[$logUser] = dt_display($logRow['timestamp']);
            }
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
            $activeTokens = parse_session_tokens($row['token_session']);
            $kelas = tostr($row['kelas']) !== '' ? tostr($row['kelas']) : '-';
            if ($kelas !== '' && $kelas !== '-') {
                $classMap[$kelas] = true;
            }
            if (count($activeTokens) > 0) {
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
                'active_sessions' => count($activeTokens),
                'max_sessions'    => $maxSessions,
                'is_online'       => count($activeTokens) > 0,
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
        return err('Gagal memuat sesi login siswa: ' . $e->getMessage());
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

        $row = db_one('SELECT * FROM users WHERE LOWER(TRIM(username)) = ?', [$target]);
        if (!$row) {
            return err('Siswa tidak ditemukan.');
        }
        if (strtolower(trim(tostr($row['role']))) !== 'siswa') {
            return err('Reset sesi login hanya dapat dilakukan untuk akun siswa.');
        }

        $targetName = tostr($row['nama_lengkap']) !== '' ? tostr($row['nama_lengkap']) : $target;
        $activeCount = count(parse_session_tokens($row['token_session']));

        if ($activeCount === 0) {
            log_activity(tostr($username), 'Reset Login Siswa', 'Tidak ada sesi login aktif pada siswa: ' . $target);
            return ['success' => true, 'message' => 'Tidak ada sesi login aktif pada akun ' . $targetName . '.'];
        }

        db_exec('UPDATE users SET token_session = NULL WHERE id_user = ?', [tostr($row['id_user'])]);
        log_activity(tostr($username), 'Reset Login Siswa', 'Mengeluarkan ' . $activeCount . ' sesi login siswa: ' . $target . ' (' . $targetName . ')');

        return [
            'success' => true,
            'message' => 'Sesi login ' . $targetName . ' berhasil direset. ' . $activeCount . ' perangkat telah dikeluarkan.',
        ];
    } catch (Throwable $e) {
        return err('Gagal mereset sesi login: ' . $e->getMessage());
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
        foreach (db_all('SELECT id_user, LOWER(TRIM(username)) AS lname FROM users') as $row) {
            $lu = strtolower(trim(tostr($row['lname'])));
            if ($lu !== '') {
                $existingUsers[$lu] = tostr($row['id_user']);
            }
        }

        $addedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

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

            $targetUsername = strtolower($rawUsername);
            $namaLengkap = trim(tostr($item['nama_lengkap'] ?? ($item['nama'] ?? '')));
            if ($namaLengkap === '') {
                $namaLengkap = $rawUsername;
            }

            $rawRole = trim(tostr($item['role'] ?? ($item['peran'] ?? '')));
            $role = 'Siswa';
            if (strtolower($rawRole) === 'guru') {
                $role = 'Guru';
            } elseif (strtolower($rawRole) === 'admin') {
                $role = 'Admin';
            }

            $tingkat = trim(tostr($item['tingkat'] ?? ($item['tingkat_kelas'] ?? ($item['level'] ?? ($item['grade'] ?? '')))));
            $tingkat = $tingkat !== '' ? $tingkat : '-';
            $kelas = trim(tostr($item['kelas'] ?? ($item['rombel'] ?? '')));
            $kelas = $kelas !== '' ? $kelas : '-';
            $sesi = trim(tostr($item['sesi'] ?? ($item['session'] ?? ($item['sesi_ujian'] ?? ''))));
            $sesi = $sesi !== '' ? $sesi : '-';
            $password = trim(tostr($item['password'] ?? ''));
            $password = $password !== '' ? $password : '123456';
            $status = trim(tostr($item['status_aktif'] ?? ($item['status'] ?? '')));
            $status = $status !== '' ? $status : 'Aktif';
            $finalTingkat = $role === 'Siswa' ? $tingkat : '-';

            if (isset($existingUsers[$targetUsername])) {
                $existingId = $existingUsers[$targetUsername];
                [$impHash, $impEnc] = password_fields($password);
                db_exec(
                    'UPDATE users SET username = ?, password = ?, password_enc = ?, nama_lengkap = ?, role = ?, kelas = ?,
                        status_aktif = ?, sesi = ?, tingkat = ? WHERE id_user = ?',
                    [$rawUsername, $impHash, $impEnc, $namaLengkap, $role, $kelas, $status, $sesi, $finalTingkat, $existingId]
                );
                $updatedCount++;
            } else {
                [$impHash2, $impEnc2] = password_fields($password);
                db_exec(
                    'INSERT INTO users (id_user, username, password, password_enc, nama_lengkap, role, kelas, token_session, status_aktif, sesi, tingkat)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [new_id('USR'), $rawUsername, $impHash2, $impEnc2, $namaLengkap, $role, $kelas, null, $status, $sesi, $finalTingkat]
                );
                $existingUsers[$targetUsername] = true;
                $addedCount++;
            }
        }

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
        return err('Gagal mengimpor data pengguna: ' . $e->getMessage());
    }
}

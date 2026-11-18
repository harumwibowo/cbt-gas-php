<?php
/**
 * Master data sesi ujian: CRUD (padanan modul Sesi pada Code.gs).
 */
declare(strict_types=1);

/** Daftar sesi ujian. */
function getSessions($username, $token): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }
    try {
        $rows = db_all('SELECT id_sesi, nama_sesi, keterangan FROM sesi ORDER BY id_sesi ASC');
        $sessions = [];
        foreach ($rows as $row) {
            $id = tostr($row['id_sesi']);
            $name = tostr($row['nama_sesi']);
            if ($id === '' && $name === '') {
                continue;
            }
            $sessions[] = [
                'id_sesi'    => $id,
                'nama_sesi'  => $name,
                'keterangan' => tostr($row['keterangan']),
            ];
        }
        return ['success' => true, 'sessions' => $sessions];
    } catch (Throwable $e) {
        return err('Gagal memuat data sesi: ' . client_error_message($e->getMessage()));
    }
}

/** Tambah / perbarui sesi ujian. */
function saveSession($username, $token, $sessionData): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa. Silakan login kembali.');
    }

    $sessionData = is_array($sessionData) ? $sessionData : [];
    try {
        $namaVal = trim(tostr($sessionData['nama_sesi'] ?? ''));
        if ($namaVal === '') {
            return err('Nama sesi tidak boleh kosong.');
        }
        $ketVal = limit_text(sanitize_plain_text($sessionData['keterangan'] ?? ''), 255);
        $namaVal = limit_text(sanitize_plain_text($namaVal), 150);
        $idSesi = trim(tostr($sessionData['id_sesi'] ?? ''));

        $duplicate = db_one(
            'SELECT id_sesi FROM sesi WHERE LOWER(TRIM(nama_sesi)) = ? AND id_sesi <> ? LIMIT 1',
            [strtolower($namaVal), $idSesi]
        );
        if ($duplicate) {
            return err('Nama sesi sudah digunakan.');
        }

        if ($idSesi !== '') {
            $exists = db_one('SELECT id_sesi FROM sesi WHERE id_sesi = ?', [$idSesi]);
            if (!$exists) {
                return err('Data sesi tidak ditemukan untuk diedit.');
            }
            db_exec('UPDATE sesi SET nama_sesi = ?, keterangan = ? WHERE id_sesi = ?', [$namaVal, $ketVal, $idSesi]);
            log_activity(tostr($username), 'Update Session', 'Mengupdate sesi: ' . $namaVal);
            return ['success' => true, 'message' => 'Sesi berhasil diperbarui.'];
        }

        $newSessionId = new_id('SES');
        db_exec('INSERT INTO sesi (id_sesi, nama_sesi, keterangan) VALUES (?,?,?)', [$newSessionId, $namaVal, $ketVal]);
        log_activity(tostr($username), 'Create Session', 'Menambahkan sesi baru: ' . $namaVal);
        return ['success' => true, 'message' => 'Sesi baru berhasil ditambahkan.', 'id_sesi' => $newSessionId];
    } catch (Throwable $e) {
        return err('Gagal menyimpan sesi: ' . client_error_message($e->getMessage()));
    }
}

/** Hapus sesi ujian. */
function deleteSession($username, $token, $id_sesi): array
{
    if (!validate_session($username, $token)) {
        return err('Sesi kedaluwarsa.');
    }

    try {
        $idSesi = trim(tostr($id_sesi));
        $row = db_one('SELECT id_sesi, nama_sesi FROM sesi WHERE id_sesi = ?', [$idSesi]);
        if (!$row) {
            return err('Sesi tidak ditemukan untuk dihapus.');
        }
        db_exec('DELETE FROM sesi WHERE id_sesi = ?', [$idSesi]);
        log_activity(tostr($username), 'Delete Session', 'Menghapus sesi: ' . (tostr($row['nama_sesi']) !== '' ? tostr($row['nama_sesi']) : $idSesi));
        return ['success' => true, 'message' => 'Sesi berhasil dihapus.'];
    } catch (Throwable $e) {
        return err('Gagal menghapus sesi: ' . client_error_message($e->getMessage()));
    }
}

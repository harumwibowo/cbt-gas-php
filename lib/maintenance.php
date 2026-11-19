<?php
/**
 * Maintenance mode (Bagian 3.7): menolak request web saat update/migrasi.
 * File penanda config/maintenance.json (bukan kredensial) berisi:
 *   {"enabled":true,"until":<unix|0>,"reason":"..."}
 */
declare(strict_types=1);

function maintenance_file(): string
{
    return APP_ROOT . '/config/maintenance.json';
}

function maintenance_active(): bool
{
    $file = maintenance_file();
    if (!is_file($file)) {
        return false;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || empty($data['enabled'])) {
        return false;
    }
    $until = (int) ($data['until'] ?? 0);
    if ($until > 0 && time() > $until) {
        @unlink($file);
        return false;
    }
    return true;
}

function maintenance_info(): array
{
    $file = maintenance_file();
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    return is_array($data) ? $data : ['enabled' => false];
}

function maintenance_set(bool $enabled, int $minutes = 0, string $reason = ''): void
{
    $file = maintenance_file();
    if (!$enabled) {
        @unlink($file);
        return;
    }
    $payload = [
        'enabled' => true,
        'until'   => $minutes > 0 ? time() + ($minutes * 60) : 0,
        'reason'  => $reason !== '' ? $reason : 'Pembaruan sistem sedang berjalan.',
    ];
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

<?php
/**
 * Kendalikan maintenance mode: php tools/maintenance.php <on|off|status> [menit] [alasan]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya CLI.\n");
}

require_once dirname(__DIR__) . '/config/config.php';

$cmd = strtolower(trim((string) ($argv[1] ?? 'status')));
switch ($cmd) {
    case 'on':
        maintenance_set(true, (int) ($argv[2] ?? 0), (string) ($argv[3] ?? 'Pembaruan sistem.'));
        echo "Maintenance AKTIF.\n";
        break;
    case 'off':
        maintenance_set(false);
        echo "Maintenance NONAKTIF.\n";
        break;
    case 'status':
    default:
        echo maintenance_active() ? "Maintenance AKTIF\n" : "Maintenance NONAKTIF\n";
        break;
}

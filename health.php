<?php
/**
 * Health check ringan untuk monitoring/uptime (Bagian 3.5).
 *
 * GET /health.php -> JSON. Tidak membocorkan kredensial, path, atau data ujian.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/config.php';

try {
    $dbOk = true;
    $version = null;
    try {
        $version = db_val('SELECT MAX(version) FROM schema_migrations', [], null);
    } catch (Throwable $e) {
        $dbOk = false;
    }

    $metrics = obs_metrics(200);
    $free = @disk_free_space(APP_ROOT);
    $total = @disk_total_space(APP_ROOT);

    $payload = [
        'status'              => maintenance_active() ? 'maintenance' : ($dbOk ? 'ok' : 'degraded'),
        'maintenance'         => maintenance_info(),
        'app'                 => APP_NAME,
        'version'             => function_exists('app_version_name') ? app_version_name() : '',
        'time'                => date('c'),
        'schema'              => [
            'current' => $version === null ? null : (int) $version,
            'target'  => schema_target_version(),
            'ok'      => $version !== null && (int) $version >= schema_target_version(),
        ],
        'database'            => [
            'reachable' => $dbOk,
            'size_mb'   => $dbOk ? obs_db_size_mb() : null,
        ],
        'disk'                => [
            'free_mb'  => is_float($free) ? round($free / 1048576, 1) : null,
            'total_mb' => is_float($total) ? round($total / 1048576, 1) : null,
        ],
        'active_participants' => $dbOk ? obs_active_participants() : null,
        'metrics'             => $metrics,
        'alert'               => obs_alert_message($metrics),
    ];
    http_response_code($dbOk ? 200 : 503);
} catch (Throwable $e) {
    http_response_code(503);
    $payload = ['status' => 'error', 'message' => 'Health check gagal.'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

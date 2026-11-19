<?php
/**
 * Ringkasan metrik request dari storage/logs/requests.jsonl (Bagian 3.5).
 *
 *   php tools/metrics.php [jumlah_sampel]   (default 1000)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya CLI.\n");
}

require_once dirname(__DIR__) . '/config/config.php';

$limit = max(10, min(10000, (int) ($argv[1] ?? 1000)));
$m = obs_metrics($limit);

echo "Sampel     : {$m['samples']}\n";
echo "p50        : {$m['p50_ms']} ms\n";
echo "p95        : {$m['p95_ms']} ms\n";
echo "max        : {$m['max_ms']} ms\n";
echo "Error rate : {$m['error_rate']}% ({$m['errors']} gagal)\n";
echo "Query avg  : {$m['queries_avg']} per request\n";

$alert = obs_alert_message($m);
echo 'Alert      : ' . ($alert ?? 'tidak ada') . "\n";

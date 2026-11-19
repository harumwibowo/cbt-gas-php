<?php
/**
 * Observability ringan tanpa dependency (Bagian 3.5).
 *
 * - request_id sudah ada di security.php; api.php mengirimnya sebagai header.
 * - setiap request ditulis sebagai satu baris JSON di storage/logs/requests.jsonl
 *   (folder diblokir .htaccess).
 * - tools/metrics.php merangkum p50/p95, error rate, jumlah query.
 */
declare(strict_types=1);

/** Tandai awal request untuk pengukuran durasi. */
function obs_start(): void
{
    $GLOBALS['CBT_OBS_START'] = microtime(true);
    $GLOBALS['CBT_QUERY_COUNT'] = 0;
}

/** Tulis satu event terstruktur ke log JSONL. */
function obs_event(string $event, array $context = []): void
{
    $entry = array_merge([
        'ts'          => date('c'),
        'request_id'  => request_id(),
        'event'       => $event,
    ], $context);
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($line)) {
        return;
    }
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/requests.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Simpan ringkasan request saat shutdown (dipanggil dari api.php). */
function obs_request_finish(string $action = '', array $extra = []): void
{
    $started = (float) ($GLOBALS['CBT_OBS_START'] ?? microtime(true));
    $entry = array_merge([
        'event'        => 'request',
        'action'       => $action,
        'method'       => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'status'       => http_response_code(),
        'duration_ms'  => round((microtime(true) - $started) * 1000, 1),
        'queries'      => (int) ($GLOBALS['CBT_QUERY_COUNT'] ?? 0),
        'path'         => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    ], $extra);
    obs_event('request', $entry);

    // Alert ringan: error 5xx atau request lambat -> error_log untuk monitoring.
    if ((int) $entry['status'] >= 500 || (float) $entry['duration_ms'] > 2000) {
        error_log('[CBT OBS ALERT] ' . json_encode($entry, JSON_UNESCAPED_UNICODE));
    }
}

/** Hitung statistik dari N baris log terakhir. */
function obs_metrics(int $limit = 1000): array
{
    $file = APP_ROOT . '/storage/logs/requests.jsonl';
    $rows = [];
    if (is_file($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $lines = array_slice($lines, -$limit);
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if (is_array($data) && ($data['event'] ?? '') === 'request') {
                    $rows[] = $data;
                }
            }
        }
    }
    $durations = [];
    $errors = 0;
    $queries = 0;
    foreach ($rows as $row) {
        $durations[] = (float) ($row['duration_ms'] ?? 0);
        $queries += (int) ($row['queries'] ?? 0);
        if ((int) ($row['status'] ?? 200) >= 400) {
            $errors++;
        }
    }
    sort($durations, SORT_NUMERIC);
    $count = count($durations);
    $pct = static function (float $p) use ($durations, $count): float {
        if ($count === 0) {
            return 0.0;
        }
        $idx = (int) ceil(($p / 100) * $count) - 1;
        return (float) $durations[max(0, min($count - 1, $idx))];
    };
    return [
        'samples'    => $count,
        'p50_ms'     => $pct(50),
        'p95_ms'     => $pct(95),
        'max_ms'     => $count > 0 ? (float) end($durations) : 0.0,
        'errors'     => $errors,
        'error_rate' => $count > 0 ? round($errors / $count * 100, 2) : 0.0,
        'queries'    => $queries,
        'queries_avg' => $count > 0 ? round($queries / $count, 1) : 0.0,
    ];
}

/** Jumlah peserta aktif (DRAFT dengan deadline belum terlewat). */
function obs_active_participants(): int
{
    try {
        return (int) db_val(
            'SELECT COUNT(*) FROM results WHERE status = ? AND (deadline_at IS NULL OR deadline_at > ?)',
            ['MENGERJAKAN', now_dt()],
            0
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/** Perkiraan ukuran database dalam MB (informasi schema, bukan kredensial). */
function obs_db_size_mb(): float
{
    try {
        $row = db_one(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS total
             FROM information_schema.TABLES WHERE table_schema = ?',
            [DB_NAME]
        );
        return round(((float) ($row['total'] ?? 0)) / 1048576, 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Rekomendasi alert bila metrik memburuk. */
function obs_alert_message(array $metrics): ?string
{
    if ($metrics['error_rate'] > 5.0) {
        return 'Error rate ' . $metrics['error_rate'] . '% (>5%) saat ujian.';
    }
    if ($metrics['p95_ms'] > 2000) {
        return 'p95 ' . $metrics['p95_ms'] . ' ms (>2000 ms). Periksa slow query.';
    }
    return null;
}

<?php
declare(strict_types=1);

// Background worker: processes uploads/analysis even when no browser tab is open.
//   php bin/worker.php          → runs continuously
//   php bin/worker.php --once   → processes what is pending, then exits (for cron)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/src/bootstrap.php';

// The worker runs forever; php.ini's max_execution_time (meant for web requests) must not stop it.
set_time_limit(0);

$once = in_array('--once', $argv, true);
echo '[' . date('H:i:s') . "] Studiepilot worker started" . ($once ? ' (once)' : '') . PHP_EOL;
$idleSince = time();
while (true) {
    // Tells the web app a worker is running, so browser tabs only poll for status instead of
    // doing the slow AI work inside web requests (PHP's built-in server handles one request at a time).
    try {
        App\Support::setSetting('worker_heartbeat', time());
    } catch (Throwable) {
    }
    try {
        $worked = App\Pipeline::workerTick();
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('H:i:s') . '] ' . $e->getMessage() . PHP_EOL);
        $worked = false;
        sleep(5);
    }
    if ($worked) {
        $idleSince = time();
        continue;
    }
    if ($once) {
        break;
    }
    try {
        App\AI::maintainSpeeds(); // runs at most once an hour (once a day on the free tier)
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('H:i:s') . '] speed test: ' . $e->getMessage() . PHP_EOL);
    }
    sleep(2);
}
echo '[' . date('H:i:s') . "] Done" . PHP_EOL;

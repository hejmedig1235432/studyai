<?php
declare(strict_types=1);

// Background processing for shared hosting (e.g. Simply.com) where you can't run `php bin/worker.php`.
// Let the host's scheduler open this URL every minute:
//   https://your-domain.dk/cron.php?key=YOUR_CRON_KEY
// It processes pending uploads/analysis/exams for up to ~50 seconds, then stops.
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Env;
use App\Pipeline;
use App\Support;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$expected = (string) Env::get('CRON_KEY', '');
$given = (string) ($_GET['key'] ?? '');
if ($expected === '' || strlen($expected) < 16) {
    http_response_code(503);
    exit("Cron is disabled: set CRON_KEY (at least 16 random characters) in .env\n");
}
if (!hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Forbidden\n");
}

ignore_user_abort(true);
@set_time_limit(120);
$budget = max(10, min(110, Env::int('CRON_SECONDS', 50)));
$start = time();
$steps = 0;
// Only one cron run at a time.
$lock = fopen(ROOT . '/storage/cache/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another cron run is still working.\n");
}
try {
    while (time() - $start < $budget) {
        Support::setSetting('worker_heartbeat', time());
        if (!Pipeline::workerTick()) {
            break; // nothing to do
        }
        $steps++;
    }
    if (time() - $start < $budget - 20) {
        App\AI::maintainSpeeds();
    }
} catch (Throwable $e) {
    Support::log('Cron error: ' . $e->getMessage());
    echo 'Error: ', $e->getMessage(), "\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
echo "OK: $steps step(s) in " . (time() - $start) . "s\n";

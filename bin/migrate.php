<?php
declare(strict_types=1);

// Applies database migrations (also done automatically on the first web request).
//   php bin/migrate.php
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/src/bootstrap.php';

$applied = App\DB::migrate();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Database is up to date (' . App\DB::driver() . ').' . PHP_EOL;

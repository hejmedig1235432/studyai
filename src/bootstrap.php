<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Studiepilot requires PHP 8.1 or newer. You are running ' . PHP_VERSION);
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Env::load(ROOT . '/.env');

date_default_timezone_set(App\Env::get('APP_TIMEZONE', 'UTC'));
mb_internal_encoding('UTF-8');

foreach (['storage', 'storage/uploads', 'storage/logs', 'storage/cache', 'storage/tmp'] as $dir) {
    if (!is_dir(ROOT . '/' . $dir)) {
        @mkdir(ROOT . '/' . $dir, 0775, true);
    }
}

// Missing extensions are reported clearly instead of failing later in odd ways.
$missing = [];
foreach (['pdo', 'mbstring', 'curl', 'json', 'zip', 'dom', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
$driver = strtolower(App\Env::get('DB_DRIVER', 'sqlite'));
if (!extension_loaded($driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite')) {
    $missing[] = $driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite';
}
if ($missing) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Studiepilot needs these PHP extensions enabled in php.ini: " . implode(', ', $missing) . "\nSee README.md → Requirements.");
}

App\DB::autoMigrate();

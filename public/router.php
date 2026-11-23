<?php
// Router for PHP's built-in dev server:  php -S localhost:8000 -t public public/router.php
// Serves static files with revalidation so code changes show up without a hard refresh.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'json' => 'application/json', 'woff2' => 'font/woff2'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
return false; // api.php, print.php etc.

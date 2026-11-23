<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

App\Auth::startSession();
$user = App\Auth::publicUser(App\Auth::user());
$boot = [
    'user' => $user,
    'csrf' => App\Auth::csrf(),
    'appName' => App\Env::get('APP_NAME', 'Studiepilot'),
    'limits' => App\Sources::limits(),
    'local' => App\Env::isLocal(),
    'languages' => App\Lang::LANGUAGES,
    'version' => App\Changelog::version(),
    'device' => App\Security::deviceId(),
    'ban' => App\Security::deviceBan(),
];
$theme = is_array($user['prefs'] ?? null) ? ($user['prefs']['theme'] ?? 'dark') : 'dark';
$v = substr(md5((string) @filemtime(__DIR__ . '/assets/js/app.js') . @filemtime(__DIR__ . '/assets/css/app.css')), 0, 8);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
?><!doctype html>
<html lang="da" data-theme="<?= $theme === 'light' ? 'light' : 'dark' ?>" data-pref="<?= htmlspecialchars((string) $theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($boot['appName']) ?></title>
<meta name="theme-color" content="#07090e">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='%237c8cff'/><stop offset='1' stop-color='%23a78bfa'/></linearGradient></defs><rect width='32' height='32' rx='9' fill='url(%23g)'/><path d='M9 12l7-4 7 4-7 4z M11 15v5c3 2 7 2 10 0v-5' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
<script>
  // Apply "system" theme before first paint.
  (function () {
    var p = document.documentElement.getAttribute('data-pref');
    if (p === 'system') document.documentElement.setAttribute('data-theme', matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  })();
  window.__BOOT__ = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
</head>
<body>
<div id="app"></div>
<div class="toasts" id="toasts" aria-live="polite"></div>
<noscript>Siden kræver JavaScript.</noscript>
<script type="module" src="assets/js/app.js?v=<?= $v ?>"></script>
</body>
</html>

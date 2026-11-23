<?php
declare(strict_types=1);

// Gives a user the admin role (admins manage AI keys and models).
//   php bin/make-admin.php you@example.com
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/src/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/make-admin.php <email>\n");
    exit(1);
}
$n = App\DB::update('users', ['role' => 'admin'], 'email = ?', [$email]);
echo $n ? "$email is now an admin.\n" : "No user with email $email.\n";
exit($n ? 0 : 1);

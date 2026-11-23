<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private static ?array $user = null;
    private static bool $wasLocked = false;
    private static string $lockReason = '';
    private static bool $wasPasswordChanged = false;
    public const PASSWORD_CHANGED_MESSAGE = 'Din adgangskode er blevet ændret. Log ind igen med den nye adgangskode.';
    public const LOCKED_MESSAGE = 'Din konto er låst af administratoren. Kontakt administratoren, hvis du mener, det er en fejl.';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string) Env::get('APP_URL', ''), 'https://');
        session_name('studyai_sid');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
        $dir = ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    /** Releases the session lock so long AI calls don't block parallel requests. */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function checkCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Http::input()['_csrf'] ?? '');
        if (!is_string($token) || !hash_equals(self::csrf(), $token)) {
            throw new HttpError(419, 'Din session er udløbet. Genindlæs siden og prøv igen.');
        }
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $u = DB::one('SELECT id, email, name, role, onboarded, prefs, created_at, locked_at, lock_reason, session_epoch FROM users WHERE id = ?', [$id]);
        if (!$u || $u['locked_at']) {
            // Deleted or locked by the administrator: sign out immediately.
            self::$wasLocked = (bool) ($u['locked_at'] ?? false);
            self::$lockReason = (string) ($u['lock_reason'] ?? '');
            unset($_SESSION['uid']);
            return null;
        }
        if ((int) ($_SESSION['epoch'] ?? 0) !== (int) $u['session_epoch']) {
            // The password was changed (by the user elsewhere or by an administrator): sign this session out.
            self::$wasPasswordChanged = true;
            unset($_SESSION['uid']);
            return null;
        }
        unset($u['session_epoch'], $u['lock_reason']);
        $u['prefs'] = Support::decode($u['prefs'], new \stdClass());
        Usage::$userId = (int) $u['id'];
        return self::$user = $u;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            throw new HttpError(self::$wasLocked && self::$lockReason !== '' ? 403 : 401, self::$wasLocked ? self::lockedMessage(self::$lockReason) : (self::$wasPasswordChanged ? self::PASSWORD_CHANGED_MESSAGE : 'Log ind for at fortsætte.'));
        }
        return $u;
    }

    public static function lockedMessage(?string $reason): string
    {
        return $reason ? Security::bannedMessage($reason) : self::LOCKED_MESSAGE;
    }

    public static function id(): int
    {
        return (int) self::require()['id'];
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function publicUser(?array $u): ?array
    {
        if (!$u) {
            return null;
        }
        return [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'onboarded' => (bool) $u['onboarded'],
            'prefs' => $u['prefs'],
        ];
    }

    // ------------------------------------------------------------------ throttle

    private static function throttle(string $bucket, int $max, int $window): void
    {
        $key = hash('sha256', $bucket);
        DB::exec('DELETE FROM auth_throttle WHERE created_at < ?', [time() - 86400]);
        $n = (int) DB::val('SELECT COUNT(*) FROM auth_throttle WHERE key_hash = ? AND created_at > ?', [$key, time() - $window]);
        if ($n >= $max) {
            $mins = (int) ceil($window / 60);
            throw new HttpError(429, "For mange forsøg. Vent ca. $mins minutter og prøv igen.");
        }
    }

    private static function hit(string $bucket): void
    {
        DB::insert('auth_throttle', ['key_hash' => hash('sha256', $bucket), 'created_at' => time()]);
    }

    private static function hashPassword(string $pw): string
    {
        return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function validatePassword(string $pw): void
    {
        if (mb_strlen($pw) < 8) {
            throw HttpError::bad('Adgangskoden skal være mindst 8 tegn.');
        }
        if (mb_strlen($pw) > 200) {
            throw HttpError::bad('Adgangskoden er for lang.');
        }
    }

    private static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['epoch'] = (int) DB::val('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
        Security::rememberDevice($userId);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        DB::update('users', ['last_login_at' => time()], 'id = ?', [$userId]);
        self::$user = null;
    }

    /**
     * Sets a new password and signs out every session of that user (they all carry the old epoch).
     * With $keepThisSession the current session stays signed in (the user changed their own password).
     */
    public static function setPassword(int $userId, string $pw, bool $keepThisSession = false): void
    {
        self::validatePassword($pw);
        DB::exec('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE id = ?', [self::hashPassword($pw), $userId]);
        DB::exec('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$userId]);
        if ($keepThisSession && (int) ($_SESSION['uid'] ?? 0) === $userId) {
            $_SESSION['epoch'] = (int) DB::val('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
            self::$user = null;
        }
    }

    // ------------------------------------------------------------------ actions

    public static function register(): array
    {
        $name = Http::str('name', '', 80);
        $email = mb_strtolower(Http::str('email', '', 190));
        $pw = (string) (Http::input()['password'] ?? '');
        self::throttle('register|' . Http::ip(), 10, 3600);
        Security::guardDevice(true); // a banned device can't just make a new account
        if ($name === '') {
            throw HttpError::bad('Skriv dit navn.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpError::bad('Skriv en gyldig e-mailadresse.');
        }
        self::validatePassword($pw);
        if (DB::val('SELECT id FROM users WHERE email = ?', [$email])) {
            throw HttpError::bad('Der findes allerede en konto med denne e-mail. Prøv at logge ind i stedet.');
        }
        self::hit('register|' . Http::ip());
        $isFirst = (int) DB::val('SELECT COUNT(*) FROM users') === 0;
        $id = DB::insert('users', [
            'email' => $email,
            'name' => $name,
            'password_hash' => self::hashPassword($pw),
            'role' => $isFirst ? 'admin' : 'user',
            'onboarded' => 0,
            'prefs' => json_encode(['theme' => 'dark']),
            'created_at' => time(),
        ]);
        self::login($id);
        return ['user' => self::publicUser(self::user()), 'csrf' => self::csrf()];
    }

    public static function signIn(): array
    {
        $email = mb_strtolower(Http::str('email', '', 190));
        $pw = (string) (Http::input()['password'] ?? '');
        $bucket = 'login|' . Http::ip() . '|' . $email;
        self::throttle($bucket, 8, 900);              // per account + IP
        self::throttle('loginip|' . Http::ip(), 30, 900); // per IP, across all accounts
        Security::guardDevice(true);
        $u = DB::one('SELECT id, password_hash, locked_at, lock_reason FROM users WHERE email = ?', [$email]);
        if (!$u || !password_verify($pw, $u['password_hash'])) {
            self::hit($bucket);
            self::hit('loginip|' . Http::ip());
            throw new HttpError(401, 'Forkert e-mail eller adgangskode.');
        }
        if ($u['locked_at']) {
            throw new HttpError(403, self::lockedMessage($u['lock_reason']), $u['lock_reason'] ? 'banned' : 'locked');
        }
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => self::hashPassword($pw)], 'id = ?', [$u['id']]);
        }
        self::login((int) $u['id']);
        return ['user' => self::publicUser(self::user()), 'csrf' => self::csrf()];
    }

    public static function logout(): array
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::startSession();
        return ['ok' => true, 'csrf' => self::csrf()];
    }

    public static function forgot(): array
    {
        $email = mb_strtolower(Http::str('email', '', 190));
        self::throttle('forgot|' . Http::ip(), 6, 900);
        self::hit('forgot|' . Http::ip());
        $resp = ['ok' => true, 'message' => 'Hvis der findes en konto med den e-mail, er der sendt et link til at nulstille adgangskoden.'];
        $u = DB::one('SELECT id, name, email FROM users WHERE email = ?', [$email]);
        if (!$u) {
            return $resp;
        }
        $token = bin2hex(random_bytes(32));
        DB::insert('password_resets', [
            'user_id' => $u['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + 3600,
            'created_at' => time(),
        ]);
        $url = rtrim((string) Env::get('APP_URL', 'http://localhost:8088'), '/') . '/#/reset?token=' . $token;
        $app = (string) Env::get('APP_NAME', 'Studiepilot');
        $body = "Hej {$u['name']},\n\nNogen (forhåbentlig dig) har bedt om at nulstille din adgangskode til $app.\n\nNulstil den her (linket virker i 1 time):\n$url\n\nHvis det ikke var dig, kan du se bort fra denne e-mail.";
        Mailer::send($u['email'], "Nulstil din adgangskode til $app", $body);
        if (Env::isLocal() && Mailer::driver() === 'log') {
            // Convenience for localhost: there is no mail server, so show the link directly.
            $resp['dev_reset_url'] = $url;
        }
        return $resp;
    }

    public static function reset(): array
    {
        $token = Http::str('token', '', 128);
        $pw = (string) (Http::input()['password'] ?? '');
        self::throttle('reset|' . Http::ip(), 10, 900);
        self::validatePassword($pw);
        $row = DB::one('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || $row['used_at'] || $row['expires_at'] < time()) {
            self::hit('reset|' . Http::ip());
            throw HttpError::bad('Linket er ugyldigt eller udløbet. Bed om et nyt.');
        }
        DB::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [time(), $row['user_id']]);
        self::setPassword((int) $row['user_id'], $pw);
        self::login((int) $row['user_id']);
        return ['user' => self::publicUser(self::user()), 'csrf' => self::csrf()];
    }

    public static function updateProfile(): array
    {
        $u = self::require();
        $data = [];
        if (array_key_exists('name', Http::input())) {
            $name = Http::str('name', '', 80);
            if ($name === '') {
                throw HttpError::bad('Navnet må ikke være tomt.');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('prefs', Http::input())) {
            $prefs = (array) $u['prefs'];
            foreach (Http::arr('prefs') as $k => $v) {
                if (in_array($k, ['theme', 'daily_goal_min', 'session_length', 'ai_language'], true) && is_scalar($v)) {
                    $prefs[$k] = $v;
                }
            }
            $data['prefs'] = json_encode($prefs);
        }
        if (array_key_exists('onboarded', Http::input())) {
            $data['onboarded'] = Http::input()['onboarded'] ? 1 : 0;
        }
        if ($data) {
            DB::update('users', $data, 'id = ?', [$u['id']]);
        }
        self::$user = null;
        return ['user' => self::publicUser(self::user())];
    }

    public static function changePassword(): array
    {
        $u = self::require();
        $current = (string) (Http::input()['current_password'] ?? '');
        $new = (string) (Http::input()['new_password'] ?? '');
        $hash = (string) DB::val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify($current, $hash)) {
            throw HttpError::bad('Din nuværende adgangskode er forkert.');
        }
        self::setPassword((int) $u['id'], $new, true); // other devices are signed out
        return ['ok' => true];
    }

    public static function deleteAccount(): array
    {
        $u = self::require();
        $pw = (string) (Http::input()['password'] ?? '');
        $hash = (string) DB::val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify($pw, $hash)) {
            throw HttpError::bad('Adgangskoden er forkert.');
        }
        foreach (DB::col('SELECT id FROM subjects WHERE user_id = ?', [$u['id']]) as $sid) {
            Subjects::destroy((int) $sid, (int) $u['id']);
        }
        foreach (['study_log', 'card_reviews', 'password_resets', 'jobs', 'ai_usage', 'essay_reviews', 'user_devices'] as $t) {
            DB::exec("DELETE FROM $t WHERE user_id = ?", [$u['id']]);
        }
        DB::exec('DELETE FROM users WHERE id = ?', [$u['id']]);
        return self::logout();
    }
}

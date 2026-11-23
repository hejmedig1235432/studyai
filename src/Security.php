<?php
declare(strict_types=1);

namespace App;

/**
 * Protection against prompt injection (attempts to manipulate the AI) and device bans.
 *
 * - Text a user types that goes to the AI is scanned. A clear attempt bans the account at once; a
 *   weaker sign gives a warning, and a second warning within 30 days bans the account.
 * - A ban blocks the ACCOUNT and the DEVICE (never the IP address). Browsers can't read real hardware
 *   ids, so the device is recognised by: a long-lived cookie, a copy of that id in the browser's
 *   storage (each restores the other), and a browser fingerprint (used to stop new sign-ups).
 * - Administrators are never banned (they only get the warning), so they can't lock themselves out.
 */
final class Security
{
    public const COOKIE = 'sp_did';

    /** Clear attempts: [regex, label]. One of these = immediate ban. */
    private const STRONG = [
        // "ignore / forget / override (all) previous instructions" (English + Danish)
        ['/\b(ignore|disregard|forget|override|bypass|skip)\b[^.\n]{0,40}\b(all|your|previous|prior|above|earlier|preceding|system|initial|original)\b[^.\n]{0,30}\b(instructions?|prompts?|rules|guidelines|directions|directives|constraints)\b/i', 'forsøg på at få AI’en til at ignorere sine instruktioner'],
        ['/\b(ignor[eé]r|glem(?!\s+(ikke|aldrig))|tilsidesæt|se bort fra|overskriv|omgå)\b[^.\n]{0,40}\b(alle|dine|tidligere|ovenstående|foregående|forrige|system|oprindelige)\b[^.\n]{0,30}\b(instruks(er|erne)?|instruktion(er|erne)?|regler(ne)?|prompt(en|s)?|retningslinjer(ne)?|begrænsninger(ne)?)\b/iu', 'forsøg på at få AI’en til at ignorere sine instruktioner'],
        // "reveal / print your system prompt"
        ['/\b(reveal|show|print|repeat|output|leak|display|dump|tell me|give me|what (is|are|was))\b[^.\n]{0,30}\b(your|the|its)\b[^.\n]{0,20}\b(system|hidden|initial|original|secret|developer|internal)\s*(prompt|instructions?|message|rules)\b/i', 'forsøg på at få AI’en til at afsløre sine skjulte instruktioner'],
        ['/\b(vis|skriv|gentag|fortæl|afslør|udskriv|hvad (er|var))\b[^.\n]{0,30}\b(din|dine|den|de|dens)\b[^.\n]{0,20}\b(system ?prompt(en)?|skjulte (instruks|instruktion|regler)\w*|interne (instruks|instruktion|regler)\w*|systembesked(en)?|oprindelige (instruks|instruktion)\w*)/iu', 'forsøg på at få AI’en til at afsløre sine skjulte instruktioner'],
        // Chat-format control tokens that only make sense when attacking a model
        ['/<\|\s*(im_start|im_end|system|endoftext|assistant|user)\s*\|>|\[\/?INST\]|<<\/?SYS>>|<\/?\s*system\s*>|^\s*#{2,}\s*(system|new instructions?)\s*:/im', 'indsættelse af skjulte styrekoder til AI’en'],
        // Known jailbreaks
        ['/\b(DAN mode|do anything now|developer mode (enabled|on)|god ?mode|unfiltered mode|uncensored mode|evil (mode|confidant)|AIM mode)\b/i', 'forsøg på at “jailbreake” AI’en'],
        // "pretend you have no rules" / "you are now an AI without restrictions"
        ['/\b(pretend|act as if|imagine|assume|behave as if)\b[^.\n]{0,30}\b(you|there)\b[^.\n]{0,20}\b(have|has|are|is)\b[^.\n]{0,10}\b(no|zero|without)\b[^.\n]{0,20}\b(rules|restrictions|limits|limitations|filters|guidelines|censorship)\b/i', 'forsøg på at fjerne AI’ens regler'],
        ['/\b(lad som om|forestil dig|antag)\b[^.\n]{0,30}\b(du|der)\b[^.\n]{0,20}\b(ingen|ikke har nogen|uden)\b[^.\n]{0,20}\b(regler|begrænsninger|filtre|grænser|retningslinjer)\b/iu', 'forsøg på at fjerne AI’ens regler'],
        ['/\byou are (now|no longer)\b[^.\n]{0,60}\b(unrestricted|uncensored|unfiltered|without (any )?(rules|restrictions|limits)|free from|not bound|DAN)\b/i', 'forsøg på at give AI’en en ny rolle uden regler'],
        ['/\b(fra nu af|du er nu|du er ikke længere)\b[^.\n]{0,60}\b(uden (regler|begrænsninger|filtre)|ubegrænset|ucensureret|ikke bundet)\b/iu', 'forsøg på at give AI’en en ny rolle uden regler'],
        ['/\b(new|updated|override|admin|developer|system)\s+(instructions?|override|command|prompt)\s*:/i', 'forsøg på at give AI’en falske systeminstruktioner'],
        ['/\b(nye|opdaterede)\s+(system)?(instruks(er)?|instruktioner)\s*:/iu', 'forsøg på at give AI’en falske systeminstruktioner'],
    ];

    /** Weaker signs (warning; a second warning within 30 days bans). Only checked in short typed messages. */
    private const WEAK = [
        ['/\b(system ?prompt|your (hidden |secret )?instructions|your (initial |original )?prompt)\b/i', 'spørgsmål om AI’ens skjulte instruktioner'],
        ['/\bjail ?break\w*\b/i', 'forsøg på at “jailbreake” AI’en'],
        ['/\b(systemprompt(en)?|dine (skjulte |hemmelige )?(instrukser|instruktioner)|din prompt|dine skjulte regler)\b/iu', 'spørgsmål om AI’ens skjulte instruktioner'],
    ];

    // ----------------------------------------------------------------- devices

    /** The browser's device id: the cookie, restored from the browser-storage copy (X-Device-Id) if needed. */
    public static function deviceId(): string
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        $header = (string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
        $id = preg_match('/^[a-f0-9]{32}$/', $cookie) ? $cookie : (preg_match('/^[a-f0-9]{32}$/', $header) ? $header : bin2hex(random_bytes(16)));
        if ($cookie !== $id && !headers_sent() && PHP_SAPI !== 'cli') {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string) Env::get('APP_URL', ''), 'https://');
            setcookie(self::COOKIE, $id, ['expires' => time() + 2 * 365 * 86400, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
        }
        return $id;
    }

    /** Hashes of everything that identifies this device (the stored id(s) and the fingerprint). */
    private static function deviceKeys(): array
    {
        $keys = ['id:' . hash('sha256', self::deviceId())];
        $header = (string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $header) && $header !== self::deviceId()) {
            $keys[] = 'id:' . hash('sha256', $header);
        }
        $fp = (string) ($_SERVER['HTTP_X_DEVICE_FP'] ?? '');
        if (preg_match('/^[a-f0-9]{16,64}$/', $fp)) {
            $keys[] = 'fp:' . hash('sha256', $fp);
        }
        return $keys;
    }

    /**
     * The ban on this device, if any. $includeFingerprint: also match the browser fingerprint
     * (used for sign-up/login, so a banned person can't just make a new account after clearing cookies).
     */
    public static function deviceBan(bool $includeFingerprint = false): ?array
    {
        $keys = array_values(array_filter(self::deviceKeys(), static fn ($k) => $includeFingerprint || str_starts_with($k, 'id:')));
        if (!$keys) {
            return null;
        }
        $row = DB::one('SELECT reason, created_at FROM banned_devices WHERE device_key IN (' . DB::in($keys) . ') ORDER BY id DESC LIMIT 1', $keys);
        return $row ? ['reason' => (string) $row['reason'], 'at' => (int) $row['created_at']] : null;
    }

    public static function bannedMessage(string $reason): string
    {
        return 'Du er blokeret fra ' . Env::get('APP_NAME', 'Studiepilot') . '. Årsag: ' . $reason . ' Kontakt administratoren, hvis du mener, det er en fejl.';
    }

    /** Refuses the request when this device is banned. */
    public static function guardDevice(bool $includeFingerprint = false): void
    {
        if ($ban = self::deviceBan($includeFingerprint)) {
            throw new HttpError(403, self::bannedMessage($ban['reason']), 'banned');
        }
    }

    // ----------------------------------------------------------- prompt guard

    /**
     * Scans text the user typed before it goes to the AI.
     * @param string[] $texts
     * @param bool $long Long texts (essays, pasted material): only clear attempts count, not weak signs.
     */
    public static function inspect(array $texts, string $where, bool $long = false): void
    {
        $text = trim(implode("\n", array_map(static fn ($t) => (string) $t, $texts)));
        if ($text === '') {
            return;
        }
        // Undo simple obfuscation: zero-width characters and l33t-style spacing inside words.
        $norm = (string) preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $text);
        $hit = self::match($norm, self::STRONG);
        $strong = $hit !== null;
        if (!$strong && !$long) {
            $hit = self::match($norm, self::WEAK);
        }
        if ($hit === null) {
            return;
        }
        [$label, $quote] = $hit;
        $u = Auth::require();
        $uid = (int) $u['id'];
        $excerpt = Support::excerpt($quote, 160);

        if ($u['role'] === 'admin') {
            self::event($uid, 'admin_warning', $where, $label, $excerpt);
            throw new HttpError(400, 'Denne besked ligner et forsøg på at manipulere AI’en (' . $label . '). Almindelige brugere bliver blokeret for dette – som administrator får du kun denne advarsel.', 'injection_warning');
        }
        $recentWarnings = (int) DB::val("SELECT COUNT(*) FROM security_events WHERE user_id = ? AND kind = 'warning' AND created_at > ?", [$uid, time() - 30 * 86400]);
        if (!$strong && $recentWarnings === 0) {
            self::event($uid, 'warning', $where, $label, $excerpt);
            throw new HttpError(400, 'Advarsel: Din besked ligner et forsøg på at manipulere AI’en (' . $label . '). '
                . Env::get('APP_NAME', 'Studiepilot') . ' hjælper kun med skole og læring. Gør du det igen, bliver din konto blokeret.', 'injection_warning');
        }
        $reason = 'Forsøg på at manipulere AI’en (prompt injection): ' . $label . ' – “' . $excerpt . '”.';
        self::ban($uid, $reason, $where, $excerpt);
        throw new HttpError(403, self::bannedMessage($reason), 'banned');
    }

    private static function match(string $text, array $rules): ?array
    {
        foreach ($rules as [$re, $label]) {
            if (preg_match($re, $text, $m)) {
                // Quote the sentence around the match so the reason is understandable.
                $pos = mb_strpos($text, $m[0]) ?: 0;
                $start = max(0, $pos - 40);
                return [$label, trim(mb_substr($text, $start, mb_strlen($m[0]) + 80))];
            }
        }
        return null;
    }

    /** Blocks the account and the device it was used from (cookie id, storage id and fingerprint). Never the IP. */
    public static function ban(int $userId, string $reason, string $where = '', string $excerpt = ''): void
    {
        DB::update('users', ['locked_at' => time(), 'lock_reason' => Support::excerpt($reason, 500)], 'id = ?', [$userId]);
        foreach (self::deviceKeys() as $key) {
            if (!DB::val('SELECT id FROM banned_devices WHERE device_key = ?', [$key])) {
                DB::insert('banned_devices', ['device_key' => $key, 'user_id' => $userId, 'reason' => Support::excerpt($reason, 500), 'created_at' => time()]);
            }
        }
        // Remember every device this account used recently, so those get blocked too.
        foreach (DB::col('SELECT DISTINCT device_key FROM user_devices WHERE user_id = ?', [$userId]) as $key) {
            if (!DB::val('SELECT id FROM banned_devices WHERE device_key = ?', [$key])) {
                DB::insert('banned_devices', ['device_key' => $key, 'user_id' => $userId, 'reason' => Support::excerpt($reason, 500), 'created_at' => time()]);
            }
        }
        self::event($userId, 'ban', $where, $reason, $excerpt);
        Support::log("SECURITY: user #$userId banned ($where): $reason");
    }

    /** Lifts the ban: unlocks the account and frees all devices banned because of it. */
    public static function unban(int $userId): void
    {
        DB::update('users', ['locked_at' => null, 'lock_reason' => null], 'id = ?', [$userId]);
        DB::exec('DELETE FROM banned_devices WHERE user_id = ?', [$userId]);
    }

    /** Records which devices an account uses (called on login and now and then on requests). */
    public static function rememberDevice(int $userId): void
    {
        foreach (self::deviceKeys() as $key) {
            if (str_starts_with($key, 'id:') && !DB::val('SELECT id FROM user_devices WHERE user_id = ? AND device_key = ?', [$userId, $key])) {
                DB::insert('user_devices', ['user_id' => $userId, 'device_key' => $key, 'created_at' => time()]);
            }
        }
    }

    private static function event(int $userId, string $kind, string $where, string $label, string $excerpt): void
    {
        DB::insert('security_events', ['user_id' => $userId, 'kind' => $kind, 'route' => Support::excerpt($where, 60), 'label' => Support::excerpt($label, 300),
            'excerpt' => Support::excerpt($excerpt, 300), 'device_key' => self::deviceKeys()[0], 'created_at' => time()]);
    }

    /** For the admin page. */
    public static function recentEvents(int $limit = 30): array
    {
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => $r['name'], 'email' => $r['email'], 'kind' => $r['kind'],
            'route' => $r['route'], 'label' => $r['label'], 'excerpt' => $r['excerpt'], 'created_at' => (int) $r['created_at'],
        ], DB::all('SELECT e.*, u.name, u.email FROM security_events e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT ' . (int) $limit));
    }
}

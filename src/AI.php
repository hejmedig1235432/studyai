<?php
declare(strict_types=1);

namespace App;

/**
 * OpenAI-compatible chat client (OpenRouter by default) with multiple API keys.
 * Keys come from OPENROUTER_API_KEYS in .env (comma separated) and from keys an admin
 * adds in Settings (stored encrypted). Requests rotate across keys and fail over when
 * a key is invalid, out of credits or rate limited.
 */
final class AI
{
    public static function config(): array
    {
        $s = (array) Support::setting('ai_config', []);
        return [
            'base_url' => rtrim((string) ($s['base_url'] ?? '') ?: (string) Env::get('AI_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
            'model' => (string) (($s['model'] ?? '') ?: Env::get('AI_MODEL', 'google/gemini-2.5-flash')),
            'vision_model' => (string) (($s['vision_model'] ?? '') ?: Env::get('AI_VISION_MODEL', '') ?: (($s['model'] ?? '') ?: Env::get('AI_MODEL', 'google/gemini-2.5-flash'))),
            'embedding_model' => (string) (($s['embedding_model'] ?? null) ?? Env::get('AI_EMBEDDING_MODEL', '')),
        ];
    }

    // ---------------------------------------------------------------------- keys

    /** @return array<int, array{id:string,key:string,label:string,source:string,enabled:bool}> */
    public static function keys(): array
    {
        $out = [];
        $envKeys = array_filter(array_map('trim', explode(',', (string) Env::get('OPENROUTER_API_KEYS', Env::get('OPENROUTER_API_KEY', '')))));
        foreach (array_values($envKeys) as $i => $k) {
            $out[] = ['id' => 'env' . $i, 'key' => $k, 'label' => '.env key #' . ($i + 1), 'source' => 'env', 'enabled' => true];
        }
        foreach ((array) Support::setting('ai_keys', []) as $row) {
            $k = Support::decrypt((string) ($row['enc'] ?? ''));
            if ($k) {
                $out[] = ['id' => (string) $row['id'], 'key' => $k, 'label' => (string) ($row['label'] ?? 'Nøgle'), 'source' => 'settings', 'enabled' => (bool) ($row['enabled'] ?? true)];
            }
        }
        return $out;
    }

    /**
     * "Backup keys for admins only": when on (default), regular users only use the FIRST enabled key.
     * Once it is used up / rate-limited they wait for it, while admins can continue on the other keys.
     */
    public static function reserveForAdmins(): bool
    {
        return (bool) Support::setting('ai_reserve_keys', true);
    }

    /** The user the current AI call is made for (set by Auth and by background jobs) is an admin. */
    private static function actingIsAdmin(): bool
    {
        static $cache = [];
        $uid = Usage::$userId;
        if ($uid <= 0) {
            return true; // system work without a user (key tests, model probing)
        }
        return $cache[$uid] ??= ((string) DB::val('SELECT role FROM users WHERE id = ?', [$uid]) === 'admin');
    }

    /** Enabled keys the current user may use, in the admin's order. */
    private static function usableKeys(): array
    {
        $keys = array_values(array_filter(self::keys(), static fn ($k) => $k['enabled']));
        if (count($keys) > 1 && self::reserveForAdmins() && !self::actingIsAdmin()) {
            $keys = array_slice($keys, 0, 1);
        }
        return $keys;
    }

    /** True when this user is held to the first key while backup keys exist. */
    private static function restrictedToFirstKey(): bool
    {
        return self::reserveForAdmins() && !self::actingIsAdmin()
            && count(array_filter(self::keys(), static fn ($k) => $k['enabled'])) > 1;
    }

    public static function hasKeys(): bool
    {
        foreach (self::keys() as $k) {
            if ($k['enabled']) {
                return true;
            }
        }
        return false;
    }

    public static function mask(string $key): string
    {
        return strlen($key) <= 12 ? str_repeat('•', 8) : substr($key, 0, 7) . '…' . substr($key, -4);
    }

    private static function keyStatus(): array
    {
        return (array) Support::setting('ai_key_status', []);
    }

    private static function markKey(string $id, ?string $error, int $cooldown = 0): void
    {
        $all = self::keyStatus();
        $cur = (array) ($all[$id] ?? []);
        if ($error === null) {
            $cur['ok_at'] = time();
            $cur['cooldown_until'] = 0;
            $cur['last_error'] = null;
        } else {
            $cur['last_error'] = $error;
            $cur['error_at'] = time();
            $cur['cooldown_until'] = $cooldown ? time() + $cooldown : 0;
        }
        $all[$id] = $cur;
        Support::setSetting('ai_key_status', $all);
    }

    public static function publicKeys(): array
    {
        $status = self::keyStatus();
        return array_map(static fn ($k) => [
            'id' => $k['id'],
            'label' => $k['label'],
            'masked' => self::mask($k['key']),
            'source' => $k['source'],
            'enabled' => $k['enabled'],
            'last_error' => $status[$k['id']]['last_error'] ?? null,
            'ok_at' => $status[$k['id']]['ok_at'] ?? null,
            'cooldown_until' => $status[$k['id']]['cooldown_until'] ?? 0,
        ], self::keys());
    }

    /**
     * Keys that may be used right now, in the admin's order: the FIRST key is always used first;
     * the next key is only used while the ones before it are rate-limited (cooling down).
     * Throws a 'rate_limited' error when every key is cooling down (chat() then waits and retries).
     */
    private static function orderedKeys(): array
    {
        $keys = self::usableKeys();
        if (!$keys) {
            throw new HttpError(503, 'Der er ikke sat en AI-nøgle op endnu. En administrator kan tilføje OpenRouter-nøgler under Indstillinger → AI-udbyder (eller i .env-filen).', 'no_keys');
        }
        $status = self::keyStatus();
        $free = array_values(array_filter($keys, static fn ($k) => ($status[$k['id']]['cooldown_until'] ?? 0) <= time()));
        if (!$free) {
            throw new HttpError(503, 'Alle API-nøgler er midlertidigt begrænset.', 'rate_limited');
        }
        return $free;
    }

    /**
     * Marks a key as rate-limited. OpenRouter's free tier has a DAILY cap (e.g. 50 free requests/day,
     * shared by all keys of one account) that resets at 00:00 UTC - no point retrying before that.
     */
    private static function markLimited(string $keyId, string $msg, ?int $retryAfter = null): void
    {
        if (preg_match('/per[- ]day|daily/i', $msg)) {
            $reset = $retryAfter && $retryAfter > 600 ? time() + $retryAfter : strtotime('tomorrow 00:00:00 UTC');
            self::markKey($keyId, 'Dagsgrænse nået: ' . Support::excerpt($msg, 160), max(60, $reset - time()));
            Support::setSetting('ai_free_tier', ['value' => true, 'at' => time()]);
            return;
        }
        self::markKey($keyId, 'Begrænset: ' . Support::excerpt($msg, 160), max(5, min(600, (int) ($retryAfter ?? 30))));
    }

    /** Unix time when the daily free limit resets, if EVERY key has hit it; otherwise null. */
    public static function dailyLimitResetAt(): ?int
    {
        $status = self::keyStatus();
        $resets = [];
        foreach (self::usableKeys() as $k) {
            $s = $status[$k['id']] ?? [];
            if (!str_starts_with((string) ($s['last_error'] ?? ''), 'Dagsgrænse') || (int) ($s['cooldown_until'] ?? 0) <= time()) {
                return null; // at least one key can still be used
            }
            $resets[] = (int) $s['cooldown_until'];
        }
        return $resets ? min($resets) : null;
    }

    public static function dailyLimitError(int $resetAt): HttpError
    {
        if (self::restrictedToFirstKey()) {
            return new HttpError(429, 'Dagens AI-forespørgsler er brugt op. De nulstilles kl. ' . date('H:i', $resetAt) . ' – prøv igen derefter.', 'daily_limit');
        }
        return new HttpError(429, 'Dagens gratis AI-grænse hos OpenRouter er brugt op (gratis konti får 50 forespørgsler pr. dag, delt mellem alle nøgler på samme konto). '
            . 'Den nulstilles kl. ' . date('H:i', $resetAt) . '. Med 10 dollars i kredit på openrouter.ai får du 1000 gratis forespørgsler om dagen.', 'daily_limit');
    }

    /** True when the keys are on OpenRouter's free tier (cached for 12 hours). */
    public static function isFreeTier(): bool
    {
        $cached = Support::setting('ai_free_tier', null);
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - 43200) {
            return (bool) $cached['value'];
        }
        $cfg = self::config();
        $keys = array_values(array_filter(self::keys(), static fn ($k) => $k['enabled']));
        if (!$keys || !str_contains($cfg['base_url'], 'openrouter.ai')) {
            return false;
        }
        $ch = curl_init($cfg['base_url'] . '/key');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $keys[0]['key']]]);
        self::caBundle($ch);
        $d = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $free = !empty($d['data']['is_free_tier']);
        Support::setSetting('ai_free_tier', ['value' => $free, 'at' => time()]);
        return $free;
    }

    /** Seconds until the first rate-limited key can be used again (0 if one is free now). */
    public static function secondsUntilKeyFree(): int
    {
        $status = self::keyStatus();
        $waits = [];
        foreach (self::usableKeys() as $k) {
            $until = (int) ($status[$k['id']]['cooldown_until'] ?? 0);
            // Long cooldowns (invalid key / no credit) aren't worth waiting for.
            $err = (string) ($status[$k['id']]['last_error'] ?? '');
            if ($until > time() && (str_starts_with($err, 'Ugyldig') || str_starts_with($err, 'Ingen kredit') || str_starts_with($err, 'Dagsgrænse'))) {
                continue;
            }
            $waits[] = max(0, $until - time());
        }
        return $waits ? min($waits) : 0;
    }

    // ---------------------------------------------------------------------- HTTP

    private static function post(string $path, array $body, string $key, int $timeout): array
    {
        $cfg = self::config();
        $ch = curl_init($cfg['base_url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'HTTP-Referer: ' . Env::get('APP_URL', 'http://localhost:8088'),
                'X-Title: ' . Env::get('APP_NAME', 'Studiepilot'),
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        // Read rate-limit headers so we know exactly how long a limited key has to rest.
        $retryAfter = null;
        $quotaLimit = null;
        $quotaRemaining = null;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$retryAfter, &$quotaLimit, &$quotaRemaining) {
            if (preg_match('/^x-ratelimit-limit:\s*(\d+)/i', $line, $m)) {
                $quotaLimit = (int) $m[1];
            } elseif (preg_match('/^x-ratelimit-remaining:\s*(\d+)/i', $line, $m)) {
                $quotaRemaining = (int) $m[1];
            }
            if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                $retryAfter = (int) $m[1];
            } elseif ($retryAfter === null && preg_match('/^x-ratelimit-reset:\s*(\d+)/i', $line, $m)) {
                $reset = (int) $m[1];
                $reset = $reset > 1e11 ? intdiv($reset, 1000) : $reset; // ms or s epoch
                $retryAfter = max(1, $reset - time());
            }
            return strlen($line);
        });
        self::caBundle($ch);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($path === '/chat/completions') {
            Usage::hit();
            Usage::providerQuota($quotaLimit, $quotaRemaining);
        }
        if ($raw === false) {
            return ['status' => 0, 'error' => 'Netværksfejl: ' . $err, 'data' => null, 'retry_after' => null];
        }
        $data = json_decode((string) $raw, true);
        $msg = null;
        if (is_array($data) && isset($data['error'])) {
            $msg = is_array($data['error']) ? (string) ($data['error']['message'] ?? 'Unknown error') : (string) $data['error'];
            // OpenRouter hides the real reason in metadata.raw ("Provider returned error" is not helpful).
            $rawMeta = is_array($data['error']) ? ($data['error']['metadata']['raw'] ?? null) : null;
            if (is_string($rawMeta) && $rawMeta !== '') {
                $inner = json_decode($rawMeta, true);
                $detail = is_array($inner) ? (string) ($inner['error']['message'] ?? $inner['message'] ?? $rawMeta) : $rawMeta;
                $msg .= ': ' . Support::excerpt(preg_replace('/\s*Please retry.*$/s', '', $detail) ?? $detail, 200);
            }
            $code = is_array($data['error']) ? (int) ($data['error']['code'] ?? 0) : 0;
            if ($status === 200 && $code >= 400) {
                $status = $code;
            } elseif ($status === 200) {
                $status = 502;
            }
        } elseif (!is_array($data)) {
            $msg = 'Invalid response from AI provider (HTTP ' . $status . ')';
            if ($status === 200) {
                $status = 502;
            }
        }
        return ['status' => $status, 'error' => $msg, 'data' => $data, 'retry_after' => $retryAfter];
    }

    /**
     * Fresh Windows PHP installs ship without a CA bundle, so HTTPS fails with
     * "unable to get local issuer certificate". Use (in order): CURL_CA_BUNDLE from .env,
     * the php.ini setting, storage/cacert.pem, or the operating system's certificate store.
     */
    public static function caBundle($ch): void
    {
        $custom = Env::get('CURL_CA_BUNDLE');
        if ($custom && is_file($custom)) {
            curl_setopt($ch, CURLOPT_CAINFO, $custom);
            return;
        }
        if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
            return;
        }
        if (is_file(ROOT . '/storage/cacert.pem')) {
            curl_setopt($ch, CURLOPT_CAINFO, ROOT . '/storage/cacert.pem');
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
    }

    /** One request with key fallback; waiting/retrying on rate limits is done by chat(). */
    private static function request(string $path, array $body, int $timeout = 180): array
    {
        return self::requestOnce($path, $body, $timeout);
    }

    private static function requestOnce(string $path, array $body, int $timeout): array
    {
        $errors = [];
        $transient = true;
        $onlyRateLimits = true;
        foreach (self::orderedKeys() as $k) {
            $r = self::post($path, $body, $k['key'], $timeout);
            $st = $r['status'];
            if ($st >= 200 && $st < 300 && $r['error'] === null) {
                self::markKey($k['id'], null);
                return $r['data'];
            }
            $msg = $r['error'] ?? ('HTTP ' . $st);
            // Some models/providers reject JSON mode (400, or a 5xx from the provider): retry without it.
            if (($st === 400 || $st >= 500) && isset($body['response_format'])) {
                unset($body['response_format']);
                $r = self::post($path, $body, $k['key'], $timeout);
                if ($r['status'] >= 200 && $r['status'] < 300 && $r['error'] === null) {
                    self::markKey($k['id'], null);
                    return $r['data'];
                }
                $st = $r['status'];
                $msg = $r['error'] ?? ('HTTP ' . $st);
            }
            // Low balance: OpenRouter says how many tokens the key can still afford → retry with that limit.
            if ($st === 402 && isset($body['max_tokens']) && preg_match('/can only afford (\d+)/', $msg, $am) && (int) $am[1] >= 1000) {
                $body2 = $body;
                $body2['max_tokens'] = (int) floor((int) $am[1] * 0.95);
                $r = self::post($path, $body2, $k['key'], $timeout);
                if ($r['status'] >= 200 && $r['status'] < 300 && $r['error'] === null) {
                    self::markKey($k['id'], 'Lav kredit: svarene bliver forkortet. Køb kredit på openrouter.ai eller brug gratis modeller.', 0);
                    return $r['data'];
                }
                $st = $r['status'];
                $msg = $r['error'] ?? ('HTTP ' . $st);
            }
            // Problems with the MODEL (region-blocked, unknown, no provider) — not the key. Every key would fail
            // the same way, so stop here and let chat() move on to the next model.
            if (in_array($st, [400, 403, 404], true) && preg_match('/region|not available|no endpoints|not a valid model|model.*not found|does not exist|not supported/i', $msg)) {
                throw new HttpError(502, Support::excerpt($msg, 200), 'model');
            }
            if ($st === 429 && str_contains($msg, 'upstream')) {
                // Shared free-model pool is busy: the model is the bottleneck, not this key.
                throw new HttpError(503, Support::excerpt($msg, 200), 'model_busy');
            }
            if ($st !== 429) {
                $onlyRateLimits = false;
            }
            if ($st === 401 || $st === 403) {
                $transient = false;
                self::markKey($k['id'], 'Ugyldig nøgle: ' . $msg, 3600);
            } elseif ($st === 402) {
                $transient = false;
                self::markKey($k['id'], 'Ingen kredit: ' . $msg, 600);
            } elseif ($st === 429) {
                // This key is rate-limited: rest it for as long as the provider says, then the next key takes over.
                self::markLimited($k['id'], $msg, $r['retry_after'] ?? null);
            } elseif ($st === 0 || $st >= 500) {
                // Timeouts / provider errors come from a slow or overloaded MODEL, not from the key:
                // don't mark the key as faulty, let chat() skip this model and use another one.
                throw new HttpError(503, Support::excerpt($msg, 200), 'model_busy');
            } else {
                // Request-level problem (e.g. unknown model): other keys will fail the same way.
                Support::log("AI request error ($st): $msg");
                throw new HttpError(502, 'AI-udbyderen afviste forespørgslen: ' . Support::excerpt($msg, 220));
            }
            $errors[] = $k['label'] . ': ' . $msg;
        }
        Support::log('All AI keys failed: ' . implode(' | ', $errors));
        $hint = str_contains(implode(' ', $errors), 'credits') || str_contains(implode(' ', $errors), 'afford')
            ? ' Køb kredit på openrouter.ai, eller vælg gratis modeller under Indstillinger → AI-udbyder.'
            : ' Prøv igen om lidt. Med gratis modeller hjælper det at tilføje flere modeller eller nøgler.';
        if ($onlyRateLimits && $errors) {
            throw new HttpError(503, 'Alle API-nøgler er midlertidigt begrænset.', 'rate_limited');
        }
        throw new HttpError(503, 'AI-tjenesten er ikke tilgængelig lige nu (' . Support::excerpt(implode('; ', $errors), 300) . ').' . $hint, $transient ? 'transient' : null);
    }

    // ---------------------------------------------------------------------- chat

    /**
     * @param array $messages OpenAI-style messages
     * @param array{model?:string,temperature?:float,max_tokens?:int,json?:bool,timeout?:int} $opt
     */
    public static function chat(array $messages, array $opt = []): string
    {
        // Rate limited? Wait until a key (normally the first) is free again and retry automatically.
        $budget = (int) ($opt['wait_budget'] ?? Env::int('AI_MAX_WAIT_SECONDS', PHP_SAPI === 'cli' ? 240 : 150));
        $cacheKey = self::cacheKey($messages, $opt);
        if ($cacheKey && ($hit = self::cacheGet($cacheKey)) !== null) {
            return $hit; // asked before: instant, and uses no AI quota
        }
        Usage::checkUserLimit();
        $start = time();
        while (true) {
            if ($reset = self::dailyLimitResetAt()) {
                throw self::dailyLimitError($reset);
            }
            try {
                $out = self::chatOnce($messages, $opt);
                if ($cacheKey && (empty($opt['json']) || self::parseJson($out) !== null)) {
                    self::cachePut($cacheKey, $out);
                }
                return $out;
            } catch (HttpError $e) {
                if (!in_array($e->code_, ['rate_limited', 'transient'], true)) {
                    throw $e;
                }
                if ($reset = self::dailyLimitResetAt()) {
                    throw self::dailyLimitError($reset);
                }
                $wait = self::secondsUntilKeyFree();
                if ($wait <= 0) {
                    $wait = $e->code_ === 'rate_limited' ? 5 : 15; // busy models: short pause, then try again
                }
                $wait = min($wait, 60);
                if (time() - $start + $wait > $budget) {
                    throw new HttpError(503, $e->code_ === 'rate_limited' && self::restrictedToFirstKey()
                        ? 'AI’en har travlt lige nu. Prøv igen om ' . max(1, self::secondsUntilKeyFree()) . ' sekunder.'
                        : ($e->code_ === 'rate_limited'
                        ? 'Alle API-nøgler er begrænset lige nu og blev ikke fri i tide. Prøv igen om ' . max(1, self::secondsUntilKeyFree()) . ' sekunder – eller tilføj en ekstra nøgle.'
                        : $e->getMessage()), $e->code_);
                }
                Support::log("AI: {$e->code_} – waiting {$wait}s before retrying automatically");
                sleep($wait);
            }
        }
    }

    private static function chatOnce(array $messages, array $opt = []): string
    {
        $cfg = self::config();
        // Output language (see Lang). OCR opts out: it must transcribe in the original language.
        if (empty($opt['no_lang'])) {
            if (($messages[0]['role'] ?? '') === 'system' && is_string($messages[0]['content'])) {
                $messages[0]['content'] .= "\n\n" . Lang::instruction();
            } else {
                array_unshift($messages, ['role' => 'system', 'content' => Lang::instruction()]);
            }
        }
        // The model setting may be a comma-separated list (e.g. all free models). They are tried in
        // order — starting with the one that worked last — and a busy, rate-limited or failing model
        // is skipped automatically.
        $modelList = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($opt['model'] ?? $cfg['model']))))));
        if (!$modelList) {
            $modelList = ['google/gemini-2.5-flash'];
        }
        // Fastest models first (measured), reasoning models pushed back when a student is waiting.
        $interactive = PHP_SAPI !== 'cli';
        $modelList = self::orderModels($modelList, $interactive);
        $lastGood = (string) Support::setting('ai_last_good_model', '');
        $base = [
            'messages' => $messages,
            'temperature' => $opt['temperature'] ?? 0.4,
            'max_tokens' => $opt['max_tokens'] ?? 4000,
        ];
        if (!empty($opt['json'])) {
            $base['response_format'] = ['type' => 'json_object'];
        }
        $content = null;
        $lastError = null;
        $single = count($modelList) === 1;
        // Skip models that recently failed for model-specific reasons (region block: 1 day, busy: 90 s),
        // unless that would leave nothing to try.
        $skip = (array) Support::setting('ai_model_skip', []);
        $usable = array_values(array_filter($modelList, static fn ($m) => ($skip[$m] ?? 0) < time()));
        if ($usable) {
            $modelList = $usable;
        }
        if ($interactive && !$single) {
            // Only the fastest few: a slow model at the end of the list is never worth the wait.
            $modelList = array_slice($modelList, 0, max(2, Env::int('AI_INTERACTIVE_MODELS', 4)));
        }
        if (str_contains($cfg['base_url'], 'openrouter.ai')) {
            // Reasoning ("thinking") models are much slower; keep their hidden reasoning short.
            $base['reasoning'] = ['effort' => 'low', 'exclude' => true];
        }
        // Several models: race the first few in parallel and take the first good answer.
        // Only for requests a student is waiting on; background work (CLI) uses one model at a time
        // so it doesn't eat the rate limit that interactive requests need.
        $background = PHP_SAPI === 'cli' && !Env::bool('AI_RACE_IN_BACKGROUND', false);
        if (!$single && empty($opt['no_race']) && !$background && !self::isFreeTier()) {
            $racers = array_slice($modelList, 0, max(1, Env::int('AI_RACE_MODELS', 3)));
            $won = self::race($racers, $base, min(120, $opt['timeout'] ?? 120), !empty($opt['json']));
            if ($won !== null) {
                return $won;
            }
            // Nobody in the race answered: try the remaining models one by one.
            $modelList = array_values(array_diff($modelList, $racers)) ?: $modelList;
        }
        foreach ($modelList as $i => $model) {
            // When a student is waiting, give each model about 2.5x its usual time, then move on.
            $limit = $single ? ($opt['timeout'] ?? 180)
                : ($interactive ? self::modelTimeout($model, min(90, $opt['timeout'] ?? 90), (int) $base['max_tokens']) : min(90, $opt['timeout'] ?? 90));
            $t0 = microtime(true);
            try {
                $data = $single
                    ? self::request('/chat/completions', ['model' => $model] + $base, $limit)
                    : self::requestOnce('/chat/completions', ['model' => $model] + $base, $limit);
            } catch (HttpError $e) {
                $lastError = $e;
                if ($e->code_ === 'model_busy') {
                    self::recordSpeed($model, max(microtime(true) - $t0, 30) * self::sizeFactor((int) $base['max_tokens']));
                }
                if ($e->code_ === 'model' || $e->code_ === 'model_busy') {
                    $skip = (array) Support::setting('ai_model_skip', []);
                    $skip[$model] = time() + ($e->code_ === 'model' ? 86400 : 90);
                    Support::setSetting('ai_model_skip', $skip);
                    if ($single) {
                        throw new HttpError($e->status, "AI-modellen “$model” kan ikke bruges lige nu: " . $e->getMessage() . ' Vælg en anden model under Indstillinger → AI-udbyder, eller brug “Brug alle gratis modeller”.');
                    }
                    continue;
                }
                // Keys/credits problems affect every model; other request errors may be model-specific.
                if ($e->code_ === 'no_keys' || (!$single && $e->code_ !== 'transient' && $e->status !== 502)) {
                    throw $e;
                }
                continue;
            }
            $c = $data['choices'][0]['message']['content'] ?? null;
            if (is_array($c)) {
                $c = implode('', array_map(static fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $c));
            }
            if (is_string($c) && trim($c) !== '') {
                $content = $c;
                self::recordSpeed($model, (microtime(true) - $t0) * self::sizeFactor((int) $base['max_tokens']));
                if (!$single && $model !== $lastGood) {
                    Support::setSetting('ai_last_good_model', $model);
                }
                break;
            }
            $lastError = new HttpError(502, 'AI’en gav et tomt svar (' . ($data['choices'][0]['finish_reason'] ?? 'ukendt') . ').', 'transient');
        }
        if ($content === null) {
            if (!$single && $lastError && in_array($lastError->code_, ['transient', 'model', 'model_busy'], true)) {
                throw new HttpError(503, 'Alle ' . count($modelList) . ' AI-modeller er optaget lige nu. Vent et minut og prøv igen, eller tilføj en ekstra API-nøgle.', 'transient');
            }
            throw $lastError ?? new HttpError(502, 'AI’en gav et tomt svar. Prøv igen.');
        }
        return $content;
    }

    /**
     * Sends the same request to several models at once (each with a different key when available)
     * and returns the first usable answer; the slower requests are cancelled.
     * For JSON requests an answer only counts if it parses. Returns null if none succeeded.
     */
    private static function race(array $models, array $base, int $timeout, bool $json): ?string
    {
        // Always the first key that isn't rate-limited (throws 'rate_limited' if none is free).
        $key = self::orderedKeys()[0];
        $keyLimited = false;
        $cfg = self::config();
        $mh = curl_multi_init();
        $handles = [];
        foreach ($models as $i => $model) {
            $ch = curl_init($cfg['base_url'] . '/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key['key'],
                    'HTTP-Referer: ' . Env::get('APP_URL', 'http://localhost:8088'),
                    'X-Title: ' . Env::get('APP_NAME', 'Studiepilot'),
                ],
                CURLOPT_POSTFIELDS => json_encode(['model' => $model] + $base, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            self::caBundle($ch);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['ch' => $ch, 'model' => $model];
        }
        Usage::hit(count($handles));
        $winner = null;
        $raceStart = microtime(true);
        $skip = (array) Support::setting('ai_model_skip', []);
        $skipChanged = false;
        do {
            curl_multi_exec($mh, $running);
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $h = $handles[(int) $ch];
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $raw = (string) curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                $d = json_decode($raw, true);
                $c = $d['choices'][0]['message']['content'] ?? null;
                if (is_array($c)) {
                    $c = implode('', array_map(static fn ($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $c));
                }
                if ($status === 200 && is_string($c) && trim($c) !== '' && (!$json || self::parseJson($c) !== null)) {
                    $winner = ['content' => $c, 'model' => $h['model'], 'seconds' => microtime(true) - $raceStart];
                    break 2;
                }
                // Remember model-specific failures so later requests skip them.
                $err = is_array($d['error'] ?? null) ? ($d['error']['message'] ?? '') . ' ' . ($d['error']['metadata']['raw'] ?? '') : '';
                if (in_array($status, [400, 403, 404], true) && preg_match('/region|not available|no endpoints|not a valid model|not found/i', $err)) {
                    $skip[$h['model']] = time() + 86400;
                    $skipChanged = true;
                } elseif ($status === 429 && str_contains($err, 'upstream')) {
                    $skip[$h['model']] = time() + 90;
                    $skipChanged = true;
                } elseif ($status === 429) {
                    $keyLimited = $err ?: 'for mange forespørgsler'; // the key itself is rate-limited
                }
            }
            if ($running > 0) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0);
        foreach ($handles as $h) {
            @curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
        }
        curl_multi_close($mh);
        if ($skipChanged) {
            Support::setSetting('ai_model_skip', $skip);
        }
        if ($winner === null) {
            if ($keyLimited) {
                // Rest this key; the next attempt automatically moves on to the next key.
                self::markLimited($key['id'], (string) $keyLimited);
            }
            return null;
        }
        self::markKey($key['id'], null);
        if ($winner['model'] !== Support::setting('ai_last_good_model', '')) {
            Support::setSetting('ai_last_good_model', $winner['model']);
        }
        self::recordSpeed($winner['model'], $winner['seconds']);
        return $winner['content'];
    }

    /**
     * Streams an answer token by token, calling $onDelta for each piece of text.
     * Uses the fastest available model on the first free key; moves to the next model if one fails
     * before producing text. Returns the full answer, or null if streaming wasn't possible
     * (the caller then uses chat(), which also handles waiting for rate limits).
     */
    public static function chatStream(array $messages, array $opt, callable $onDelta): ?string
    {
        $cfg = self::config();
        if (($messages[0]['role'] ?? '') === 'system' && is_string($messages[0]['content'])) {
            $messages[0]['content'] .= "\n\n" . Lang::instruction();
        } else {
            array_unshift($messages, ['role' => 'system', 'content' => Lang::instruction()]);
        }
        $models = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($opt['model'] ?? $cfg['model']))))));
        $skip = (array) Support::setting('ai_model_skip', []);
        $models = array_values(array_filter($models, static fn ($m) => ($skip[$m] ?? 0) < time())) ?: $models;
        $models = self::orderModels($models, true);
        $cacheKey = self::cacheKey($messages, $opt);
        if ($cacheKey && ($hit = self::cacheGet($cacheKey)) !== null) {
            $onDelta($hit); // answered before: instant
            return $hit;
        }
        $firstTokenLimit = Env::int('AI_FIRST_TOKEN_SECONDS', 12);
        Usage::checkUserLimit();
        try {
            $key = self::orderedKeys()[0];
        } catch (HttpError $e) {
            return null; // all keys limited: let chat() wait and retry
        }
        foreach (array_slice($models, 0, 4) as $model) {
            $body = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $opt['temperature'] ?? 0.4,
                'max_tokens' => $opt['max_tokens'] ?? 1500,
                'stream' => true,
            ];
            if (str_contains($cfg['base_url'], 'openrouter.ai')) {
                $body['reasoning'] = ['effort' => 'low', 'exclude' => true];
            }
            $full = '';
            $buffer = '';
            $status = 0;
            $errorBody = '';
            $start = microtime(true);
            $lastPiece = $start;
            $gaveUp = false;
            $ch = curl_init($cfg['base_url'] . '/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 150,
                CURLOPT_CONNECTTIMEOUT => 10,
                // Give up on a model that hasn't written anything after ~12 s (or stalls for 30 s), so the
                // next one can try. Keep-alive comments from the provider don't count as text.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function () use (&$full, &$lastPiece, &$gaveUp, $start, $firstTokenLimit): int {
                    $now = microtime(true);
                    if (($full === '' && $now - $start > $firstTokenLimit) || ($full !== '' && $now - $lastPiece > 30)) {
                        $gaveUp = true;
                        return 1; // abort
                    }
                    return 0;
                },
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key['key'],
                    'HTTP-Referer: ' . Env::get('APP_URL', 'http://localhost:8088'),
                    'X-Title: ' . Env::get('APP_NAME', 'Studiepilot'),
                ],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$full, &$buffer, &$status, &$errorBody, &$lastPiece, $onDelta) {
                    $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    if ($status !== 200) {
                        $errorBody .= $chunk;
                        return strlen($chunk);
                    }
                    $buffer .= $chunk;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                        if (!str_starts_with($line, 'data:')) {
                            continue; // comments like ": OPENROUTER PROCESSING"
                        }
                        $json = trim(substr($line, 5));
                        if ($json === '[DONE]') {
                            continue;
                        }
                        $d = json_decode($json, true);
                        $piece = $d['choices'][0]['delta']['content'] ?? '';
                        if (is_string($piece) && $piece !== '') {
                            $full .= $piece;
                            $lastPiece = microtime(true);
                            $onDelta($piece);
                        }
                    }
                    return strlen($chunk);
                },
            ]);
            self::caBundle($ch);
            Usage::hit();
            curl_exec($ch);
            $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (trim($full) !== '') {
                self::recordSpeed($model, (microtime(true) - $start) * self::sizeFactor((int) $body['max_tokens']));
                self::markKey($key['id'], null);
                if ($cacheKey && (empty($opt['json']) || self::parseJson($full) !== null)) {
                    self::cachePut($cacheKey, $full);
                }
                return $full;
            }
            if ($gaveUp) {
                // Too slow to start: count it as slow and rest it briefly, then try the next model.
                self::recordSpeed($model, 40);
                $skip[$model] = time() + 60;
                Support::setSetting('ai_model_skip', $skip);
                Support::log("AI: $model wrote nothing in {$firstTokenLimit}s - trying the next model");
                continue;
            }
            // Nothing came out: remember why, then try the next model.
            $err = (string) (json_decode($errorBody, true)['error']['message'] ?? '') . ' ' . $errorBody;
            if ($status === 429 && !str_contains($err, 'upstream')) {
                self::markLimited($key['id'], $err);
                return null;
            }
            if ($status === 429 || in_array($status, [400, 403, 404], true) && preg_match('/region|not available|no endpoints|not found/i', $err)) {
                $skip[$model] = time() + ($status === 429 ? 90 : 86400);
                Support::setSetting('ai_model_skip', $skip);
            }
        }
        return null;
    }

    /** Running average of how long a model takes to answer (used to try the fastest first). */
    private static function recordSpeed(string $model, float $seconds): void
    {
        $speed = (array) Support::setting('ai_model_speed', []);
        $old = $speed[$model] ?? null;
        $speed[$model] = round($old === null ? $seconds : 0.7 * (float) $old + 0.3 * $seconds, 2);
        Support::setSetting('ai_model_speed', $speed);
    }

    /** Long answers take longer: speeds are stored as "time for a normal-sized answer" (1500 tokens). */
    private static function sizeFactor(int $maxTokens): float
    {
        return $maxTokens > 1500 ? 1500 / $maxTokens : 1.0;
    }

    /** Reasoning ("thinking") models spend extra seconds thinking before they write anything. */
    private static function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/reason|think|\br1\b|-r1\b|qwq|deepseek-r/i', $model);
    }

    /**
     * Fastest first by measured answer time (unknown models count as 25 s). When a student is
     * waiting, reasoning models are pushed back because they think before answering.
     */
    private static function orderModels(array $models, bool $interactive): array
    {
        $speed = (array) Support::setting('ai_model_speed', []);
        $order = array_flip($models);
        $score = static fn (string $m): float => (float) ($speed[$m] ?? 25) * ($interactive && self::isReasoningModel($m) ? 1.6 : 1.0);
        usort($models, static fn ($a, $b) => [$score($a), $order[$a]] <=> [$score($b), $order[$b]]);
        return $models;
    }

    /** How long to wait for one model: ~2.5x its usual time (scaled for long answers), 15 s to $max. */
    private static function modelTimeout(string $model, int $max, int $maxTokens): int
    {
        $avg = (float) (((array) Support::setting('ai_model_speed', []))[$model] ?? 0);
        if ($avg <= 0) {
            return (int) min($max, ceil(45 / self::sizeFactor($maxTokens))); // not measured yet
        }
        return (int) max(15, min($max, ceil($avg / self::sizeFactor($maxTokens) * 2.5 + 8)));
    }

    // ----------------------------------------------------------------- answer cache

    /**
     * Identical requests (same messages, same language, same settings) get the saved answer instantly
     * and use no AI quota. Only for deterministic work (low temperature) or when asked ('cache' => true);
     * creative work like new quiz questions is never cached.
     */
    private static function cacheKey(array $messages, array $opt): ?string
    {
        if (!empty($opt['no_cache']) || !Env::bool('AI_CACHE', true)) {
            return null;
        }
        if (empty($opt['cache']) && (float) ($opt['temperature'] ?? 0.4) > 0.3) {
            return null;
        }
        unset($opt['timeout'], $opt['wait_budget'], $opt['cache']);
        return hash('sha256', json_encode([$messages, $opt, Lang::code()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function cacheGet(string $key): ?string
    {
        $row = DB::one('SELECT response, created_at FROM ai_cache WHERE cache_key = ?', [$key]);
        if (!$row || (int) $row['created_at'] < time() - Env::int('AI_CACHE_DAYS', 14) * 86400) {
            return null;
        }
        return (string) $row['response'];
    }

    private static function cachePut(string $key, string $response): void
    {
        try {
            DB::exec('DELETE FROM ai_cache WHERE cache_key = ?', [$key]);
            DB::insert('ai_cache', ['cache_key' => $key, 'response' => $response, 'created_at' => time()]);
            if (random_int(1, 100) === 1) {
                DB::exec('DELETE FROM ai_cache WHERE created_at < ?', [time() - Env::int('AI_CACHE_DAYS', 14) * 86400]);
            }
        } catch (\Throwable $e) {
            Support::log('AI cache: ' . $e->getMessage());
        }
    }

    /**
     * Background speed test (run by the worker/cron). With a paid account every model is tested each
     * hour. On the free tier (50 requests a day) only up to 4 models without a measurement are tested,
     * once a day, so it costs almost nothing.
     */
    public static function maintainSpeeds(): void
    {
        $free = self::isFreeTier();
        if ((int) Support::setting('ai_speed_probe_at', 0) > time() - ($free ? 86400 : 3600)) {
            return;
        }
        Support::setSetting('ai_speed_probe_at', time());
        if (!self::hasKeys() || self::dailyLimitResetAt()) {
            return;
        }
        $speed = (array) Support::setting('ai_model_speed', []);
        $models = array_values(array_unique(array_filter(array_map('trim', explode(',', self::config()['model'])))));
        if ($free) {
            $models = array_slice(array_values(array_filter($models, static fn ($m) => !isset($speed[$m]))), 0, 4);
        }
        if (!$models) {
            return;
        }
        $result = self::probeModels(array_map(static fn ($m) => ['id' => $m], $models));
        $answered = [];
        foreach ($result as $r) {
            if (isset($r['time'])) {
                // A test answer is tiny; a real answer takes roughly 2.5x as long.
                $estimate = round((float) $r['time'] * 2.5, 2);
                $speed[$r['id']] = isset($speed[$r['id']]) ? round(0.8 * (float) $speed[$r['id']] + 0.2 * $estimate, 2) : $estimate;
                $answered[$r['id']] = true;
            }
        }
        foreach ($models as $m) {
            if (!isset($answered[$m])) {
                $speed[$m] = max((float) ($speed[$m] ?? 0), 45.0); // didn't answer: try it last
            }
        }
        Support::setSetting('ai_model_speed', $speed);
        Support::log('AI speed test: ' . count($answered) . ' of ' . count($models) . ' models answered');
    }

    /** Chat that must return a JSON object. Retries once with a correction if parsing fails. */
    public static function json(string $system, string $user, array $opt = []): array
    {
        $messages = [
            ['role' => 'system', 'content' => $system . "\n\nRespond with a single valid JSON object only. No markdown fences, no commentary."],
            ['role' => 'user', 'content' => $user],
        ];
        $opt['json'] = true;
        $out = self::chat($messages, $opt);
        $parsed = self::parseJson($out);
        if ($parsed !== null) {
            return $parsed;
        }
        $messages[] = ['role' => 'assistant', 'content' => mb_substr($out, 0, 6000)];
        $messages[] = ['role' => 'user', 'content' => 'That was not valid JSON (it may have been cut off). Reply again with ONLY a complete, valid JSON object, keeping it shorter if needed.'];
        $out = self::chat($messages, $opt);
        $parsed = self::parseJson($out);
        if ($parsed === null) {
            Support::log('Unparseable AI JSON (' . mb_strlen($out) . ' chars): ' . mb_substr($out, 0, 1500) . ' … ' . mb_substr($out, -1500));
            throw new HttpError(502, 'AI’en svarede i et uventet format. Prøv igen.');
        }
        return $parsed;
    }

    public static function parseJson(string $s): ?array
    {
        $s = trim($s);
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $s) ?? $s;
        $d = json_decode($s, true);
        if (is_array($d)) {
            return $d;
        }
        $start = strpos($s, '{');
        $end = strrpos($s, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $d = json_decode(substr($s, $start, $end - $start + 1), true);
            if (is_array($d)) {
                return $d;
            }
        }
        return null;
    }

    /** Reads text from an image using a vision-capable model. */
    public static function ocr(string $imageBytes, string $mime, string $hint = ''): string
    {
        $cfg = self::config();
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);
        $prompt = "Transcribe ALL readable text in this image exactly as written, preserving headings, lists and table rows (use ' | ' between table cells). "
            . "After the transcription, if the image contains diagrams, charts or figures, add a section starting with 'Figure description:' that briefly and factually describes what they show. "
            . "Do not add information that is not visible. If there is no readable text and nothing meaningful, reply exactly NO_TEXT." . ($hint ? "\nContext: $hint" : '');
        $out = self::chat([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]],
        ], ['model' => $cfg['vision_model'], 'temperature' => 0, 'max_tokens' => 6000, 'no_lang' => true]);
        return trim($out) === 'NO_TEXT' ? '' : trim($out);
    }

    /** @return array<int, float[]>|null embeddings, or null when not configured/available */
    public static function embed(array $texts): ?array
    {
        $model = self::config()['embedding_model'];
        if ($model === '' || !$texts || Support::setting('embeddings_disabled_until', 0) > time()) {
            return null;
        }
        try {
            $data = self::request('/embeddings', ['model' => $model, 'input' => array_values($texts)], 90);
        } catch (\Throwable $e) {
            // Embeddings are optional: fall back to keyword retrieval and retry later.
            Support::log('Embeddings unavailable: ' . $e->getMessage());
            Support::setSetting('embeddings_disabled_until', time() + 1800);
            return null;
        }
        $out = [];
        foreach (($data['data'] ?? []) as $row) {
            $out[(int) ($row['index'] ?? count($out))] = array_map('floatval', (array) ($row['embedding'] ?? []));
        }
        ksort($out);
        return count($out) === count($texts) ? array_values($out) : null;
    }

    public static function testKey(string $key): array
    {
        $cfg = self::config();
        // OpenRouter: validate the key itself (no credits used, unaffected by model rate limits).
        if (str_contains($cfg['base_url'], 'openrouter.ai')) {
            $ch = curl_init($cfg['base_url'] . '/key');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
            self::caBundle($ch);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'message' => 'Netværksfejl: ' . $err];
            }
            $d = json_decode((string) $raw, true);
            if ($status !== 200 || !isset($d['data'])) {
                return ['ok' => false, 'message' => 'HTTP ' . $status . ': ' . ($d['error']['message'] ?? 'nøglen blev afvist')];
            }
            $info = $d['data'];
            $msg = 'Nøglen er gyldig.';
            if (!empty($info['is_free_tier'])) {
                $msg .= ' Gratis konto (ingen kredit): brug gratis modeller (“Brug alle gratis modeller” nedenfor).';
            } elseif (isset($info['limit_remaining'])) {
                $msg .= ' Resterende kredit: $' . number_format((float) $info['limit_remaining'], 2) . '.';
            }
            return ['ok' => true, 'message' => $msg, 'free_tier' => !empty($info['is_free_tier'])];
        }
        $model = trim(explode(',', $cfg['model'])[0]);
        $r = self::post('/chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Reply with the word OK.']],
            'max_tokens' => 5,
        ], $key, 40);
        if ($r['status'] >= 200 && $r['status'] < 300 && $r['error'] === null) {
            return ['ok' => true, 'message' => 'Nøglen virker med modellen ' . $model . '.'];
        }
        return ['ok' => false, 'message' => 'HTTP ' . $r['status'] . ': ' . ($r['error'] ?? 'ukendt fejl')];
    }

    /**
     * Sends a tiny JSON request to every candidate model in parallel and keeps the ones that answer
     * correctly from this location, fastest first (busy ones are kept at the end as a last resort).
     */
    private static function probeModels(array $models): array
    {
        $keys = array_values(array_filter(self::keys(), static fn ($k) => $k['enabled']));
        if (!$keys || !$models) {
            return $models;
        }
        $base = self::config()['base_url'];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($models as $i => $m) {
            $ch = curl_init($base . '/chat/completions');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $keys[$i % count($keys)]['key']],
                CURLOPT_POSTFIELDS => json_encode(['model' => $m['id'], 'max_tokens' => 400, 'messages' => [
                    ['role' => 'system', 'content' => 'Respond with a JSON object only.'],
                    ['role' => 'user', 'content' => 'Return {"answer": <the result of 17+25 as a number>}']]])]);
            self::caBundle($ch);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        Usage::hit(count($handles));
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
        } while ($running > 0);
        $good = [];
        $busy = [];
        foreach ($handles as $i => $ch) {
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $d = json_decode((string) curl_multi_getcontent($ch), true);
            $content = (string) ($d['choices'][0]['message']['content'] ?? '');
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($status === 200 && str_contains($content, '42')) {
                $good[] = $models[$i] + ['time' => $time];
            } elseif ($status === 429) {
                $busy[] = $models[$i];
            }
        }
        curl_multi_close($mh);
        usort($good, static fn ($a, $b) => $a['time'] <=> $b['time']);
        return array_merge($good, $busy);
    }

    /**
     * Current free OpenRouter models, best first. Tiny models and code-only models are left out
     * because they can't reliably produce study material.
     * @return array{text: string[], vision: string[]}
     */
    public static function freeModels(): array
    {
        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        self::caBundle($ch);
        $raw = curl_exec($ch);
        curl_close($ch);
        $models = json_decode((string) $raw, true)['data'] ?? [];
        if (!$models) {
            throw new HttpError(502, 'Kunne ikke hente modellisten fra OpenRouter. Tjek internetforbindelsen.');
        }
        $free = [];
        foreach ($models as $m) {
            $id = (string) ($m['id'] ?? '');
            if (!str_ends_with($id, ':free')) {
                continue;
            }
            // Skip tiny (< 7B) and code-only models.
            if (preg_match('/(^|[-_\/])(0?\.\d+|[1-6](\.\d+)?)b\b|code|coder/i', $id) && !preg_match('/a\d+b/i', $id)) {
                continue;
            }
            if ((int) ($m['context_length'] ?? 0) < 32000) {
                continue;
            }
            $params = preg_match('/(\d+)b/i', $id, $pm) ? (int) $pm[1] : 50; // rough size hint for ordering
            $free[] = [
                'id' => $id,
                'vision' => in_array('image', $m['architecture']['input_modalities'] ?? [], true),
                'score' => $params + ((int) ($m['context_length'] ?? 0) / 100000),
            ];
        }
        usort($free, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $free = self::probeModels($free);
        return [
            'text' => array_column($free, 'id'),
            'vision' => array_column(array_filter($free, static fn ($m) => $m['vision']), 'id'),
        ];
    }
}

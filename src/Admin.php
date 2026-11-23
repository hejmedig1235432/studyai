<?php
declare(strict_types=1);

namespace App;

/** AI provider settings (admin only) and global search. */
final class Admin
{
    private static function requireAdmin(): void
    {
        Auth::require();
        if (!Auth::isAdmin()) {
            throw new HttpError(403, 'Kun administratoren kan ændre AI-indstillingerne.');
        }
    }

    public static function aiStatus(): array
    {
        Auth::require();
        $cfg = AI::config();
        // Regular users only learn whether AI is available — never keys, models or provider details.
        $out = [
            'configured' => AI::hasKeys(),
            'is_admin' => Auth::isAdmin(),
        ];
        if (Auth::isAdmin()) {
            $out += [
                'model' => $cfg['model'],
                'config' => $cfg,
                'keys' => AI::publicKeys(),
                'reserve_for_admins' => AI::reserveForAdmins(),
                'env_note' => 'Keys from .env (OPENROUTER_API_KEYS) can only be changed in that file.',
                'system' => [
                    'php' => PHP_VERSION,
                    'db' => DB::driver(),
                    'upload_limit_mb' => round(Sources::limits()['max_bytes'] / 1048576, 1),
                    'gd' => function_exists('imagecreatefromstring'),
                    'embeddings' => $cfg['embedding_model'] !== '',
                ],
            ];
        }
        return $out;
    }

    public static function addKey(): array
    {
        self::requireAdmin();
        $key = trim((string) (Http::input()['key'] ?? ''));
        $label = Http::str('label', '', 60) ?: 'Nøgle ' . (count((array) Support::setting('ai_keys', [])) + 1);
        if (strlen($key) < 20 || preg_match('/\s/', $key)) {
            throw HttpError::bad('Det ligner ikke en gyldig API-nøgle.');
        }
        foreach (AI::keys() as $k) {
            if (hash_equals($k['key'], $key)) {
                throw HttpError::bad('Den nøgle er allerede tilføjet.');
            }
        }
        $test = (Http::input()['test'] ?? true) ? AI::testKey($key) : ['ok' => true, 'message' => 'Ikke testet'];
        if (!$test['ok'] && !(Http::input()['force'] ?? false)) {
            throw HttpError::bad('Nøglen virkede ikke: ' . $test['message']);
        }
        $keys = (array) Support::setting('ai_keys', []);
        $keys[] = ['id' => 'k' . bin2hex(random_bytes(4)), 'label' => $label, 'enc' => Support::encrypt($key), 'enabled' => true, 'added_at' => time()];
        Support::setSetting('ai_keys', $keys);
        return ['ok' => true, 'test' => $test, 'keys' => AI::publicKeys()];
    }

    public static function updateKey(): array
    {
        self::requireAdmin();
        $id = Http::str('id');
        $keys = (array) Support::setting('ai_keys', []);
        $found = false;
        foreach ($keys as $i => $k) {
            if ($k['id'] === $id) {
                $found = true;
                if (Http::input()['delete'] ?? false) {
                    unset($keys[$i]);
                } else {
                    if (array_key_exists('enabled', Http::input())) {
                        $keys[$i]['enabled'] = (bool) Http::input()['enabled'];
                    }
                    if ($label = Http::str('label', '', 60)) {
                        $keys[$i]['label'] = $label;
                    }
                }
            }
        }
        if (!$found) {
            throw HttpError::bad(str_starts_with($id, 'env') ? 'Nøgler fra .env-filen skal ændres i den fil.' : 'Nøglen blev ikke fundet.');
        }
        Support::setSetting('ai_keys', array_values($keys));
        return ['keys' => AI::publicKeys()];
    }

    public static function testKeys(): array
    {
        self::requireAdmin();
        Auth::release();
        $results = [];
        foreach (AI::keys() as $k) {
            $r = AI::testKey($k['key']);
            $results[] = ['id' => $k['id'], 'label' => $k['label']] + $r;
        }
        return ['results' => $results];
    }

    public static function updateConfig(): array
    {
        self::requireAdmin();
        $cfg = (array) Support::setting('ai_config', []);
        foreach (['base_url', 'model', 'vision_model', 'embedding_model'] as $f) {
            if (array_key_exists($f, Http::input())) {
                $v = Http::str($f, '', 300);
                if ($f === 'base_url' && $v !== '' && !preg_match('~^https?://~', $v)) {
                    throw HttpError::bad('API-adressen skal starte med http:// eller https://');
                }
                $cfg[$f] = $v;
            }
        }
        if (array_key_exists('reserve_for_admins', Http::input())) {
            Support::setSetting('ai_reserve_keys', (bool) Http::input()['reserve_for_admins']);
            return self::aiStatus();
        }
        Support::setSetting('ai_config', $cfg);
        Support::setSetting('embeddings_disabled_until', 0);
        return self::aiStatus();
    }

    /** Sets the main and vision model to every usable free OpenRouter model (tried in order, with fallback). */
    public static function useFreeModels(): array
    {
        self::requireAdmin();
        Auth::release();
        $free = AI::freeModels();
        if (!$free['text']) {
            throw HttpError::bad('OpenRouter har ingen egnede gratis modeller lige nu.');
        }
        $cfg = (array) Support::setting('ai_config', []);
        $cfg['model'] = implode(', ', $free['text']);
        $cfg['vision_model'] = implode(', ', $free['vision'] ?: $free['text']);
        Support::setSetting('ai_config', $cfg);
        Support::setSetting('ai_last_good_model', '');
        return self::aiStatus() + ['free' => $free];
    }

    /** Re-embeds existing chunks after an embedding model is configured. */
    public static function reindex(): array
    {
        self::requireAdmin();
        Auth::release();
        @set_time_limit(300);
        $rows = DB::all('SELECT id, content FROM chunks WHERE embedding IS NULL LIMIT 256');
        $done = 0;
        foreach (array_chunk($rows, 64) as $batch) {
            $vecs = AI::embed(array_map(static fn ($r) => mb_substr($r['content'], 0, 6000), $batch));
            if (!$vecs) {
                throw HttpError::bad('Embedding-modellen gav ingen resultater. Tjek modelnavnet i AI-indstillingerne.');
            }
            foreach ($batch as $i => $r) {
                DB::update('chunks', ['embedding' => base64_encode(pack('g*', ...$vecs[$i]))], 'id = ?', [$r['id']]);
                $done++;
            }
        }
        return ['done' => $done, 'remaining' => (int) DB::val('SELECT COUNT(*) FROM chunks WHERE embedding IS NULL')];
    }

    // -------------------------------------------------------- admin dashboard

    /** Statistics + all users. */
    public static function overview(): array
    {
        self::requireAdmin();
        $day = Usage::day();
        $weekAgo = time() - 7 * 86400;
        $users = DB::all('SELECT id, name, email, role, created_at, last_login_at, locked_at, lock_reason FROM users ORDER BY created_at DESC');
        foreach ($users as &$u) {
            $u['id'] = (int) $u['id'];
            $u['subjects'] = (int) DB::val('SELECT COUNT(*) FROM subjects WHERE user_id = ?', [$u['id']]);
            $u['ai_today'] = (int) DB::val('SELECT COALESCE(requests,0) FROM ai_usage WHERE user_id = ? AND day = ?', [$u['id'], $day]);
            $u['ai_week'] = (int) DB::val('SELECT COALESCE(SUM(requests),0) FROM ai_usage WHERE user_id = ? AND day >= ?', [$u['id'], gmdate('Y-m-d', $weekAgo)]);
            $u['storage_mb'] = round((int) DB::val('SELECT COALESCE(SUM(size_bytes),0) FROM sources WHERE user_id = ?', [$u['id']]) / 1048576, 1);
            $u['locked'] = (bool) $u['locked_at'];
            $u['warnings'] = (int) DB::val("SELECT COUNT(*) FROM security_events WHERE user_id = ? AND kind = 'warning'", [$u['id']]);
        }
        unset($u);
        $usageDays = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $usageDays[] = ['day' => $d, 'requests' => (int) DB::val('SELECT COALESCE(SUM(requests),0) FROM ai_usage WHERE day = ?', [$d])];
        }
        return [
            'stats' => [
                'users' => count($users),
                'active_week' => (int) DB::val('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [$weekAgo]),
                'locked' => count(array_filter($users, static fn ($u) => $u['locked'])),
                'subjects' => (int) DB::val('SELECT COUNT(*) FROM subjects'),
                'sources' => (int) DB::val('SELECT COUNT(*) FROM sources'),
                'flashcards' => (int) DB::val('SELECT COUNT(*) FROM flashcards'),
                'quizzes' => (int) DB::val("SELECT COUNT(*) FROM attempts WHERE status = 'finished'"),
                'storage_mb' => round((int) DB::val('SELECT COALESCE(SUM(size_bytes),0) FROM sources') / 1048576, 1),
                'ai_today' => Usage::todayTotal(),
            ],
            'usage_days' => $usageDays,
            'user_limit' => Usage::userLimit(),
            'users' => $users,
            'security_events' => Security::recentEvents(30),
            'banned_devices' => (int) DB::val('SELECT COUNT(*) FROM banned_devices'),
            'me' => Auth::id(),
        ];
    }

    public static function updateUser(): array
    {
        self::requireAdmin();
        $id = Http::int('id');
        $target = DB::one('SELECT id, role, locked_at FROM users WHERE id = ?', [$id]);
        if (!$target) {
            throw HttpError::bad('Brugeren blev ikke fundet.');
        }
        $in = Http::input();
        if ($id === Auth::id() && (array_key_exists('locked', $in) || array_key_exists('role', $in))) {
            throw HttpError::bad('Du kan ikke låse eller ændre rollen på din egen konto.');
        }
        $data = [];
        if (array_key_exists('locked', $in)) {
            if ($in['locked']) {
                $data['locked_at'] = time();
            } else {
                Security::unban($id); // also frees the devices that were banned with the account
            }
        }
        if (array_key_exists('role', $in)) {
            $role = Http::str('role') === 'admin' ? 'admin' : 'user';
            if ($role === 'user' && $target['role'] === 'admin' && (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin'") <= 1) {
                throw HttpError::bad('Der skal være mindst én administrator.');
            }
            $data['role'] = $role;
        }
        if ($data) {
            DB::update('users', $data, 'id = ?', [$id]);
        }
        return self::overview();
    }

    /** Sets a new password for another user. All their sessions are signed out. */
    public static function setPassword(): array
    {
        self::requireAdmin();
        $id = Http::int('id');
        $target = DB::one('SELECT id, name FROM users WHERE id = ?', [$id]);
        if (!$target) {
            throw HttpError::bad('Brugeren blev ikke fundet.');
        }
        if ($id === Auth::id()) {
            throw HttpError::bad('Skift din egen adgangskode under Indstillinger → Adgangskode.');
        }
        Auth::setPassword($id, (string) (Http::input()['password'] ?? ''));
        Support::log('Admin #' . Auth::id() . ' changed the password of user #' . $id);
        return ['ok' => true];
    }

    public static function deleteUser(): array
    {
        self::requireAdmin();
        $id = Http::int('id');
        if ($id === Auth::id()) {
            throw HttpError::bad('Du kan ikke slette din egen konto herfra. Brug Indstillinger → Konto.');
        }
        if (!DB::val('SELECT id FROM users WHERE id = ?', [$id])) {
            throw HttpError::bad('Brugeren blev ikke fundet.');
        }
        foreach (DB::col('SELECT id FROM subjects WHERE user_id = ?', [$id]) as $sid) {
            Subjects::destroy((int) $sid, $id);
        }
        foreach (['study_log', 'card_reviews', 'password_resets', 'jobs', 'ai_usage', 'essay_reviews', 'user_devices'] as $t) {
            DB::exec("DELETE FROM $t WHERE user_id = ?", [$id]);
        }
        DB::exec('DELETE FROM users WHERE id = ?', [$id]);
        return self::overview();
    }

    public static function setUserLimit(): array
    {
        self::requireAdmin();
        Support::setSetting('ai_user_daily_limit', max(0, min(10000, Http::int('limit'))));
        return self::overview();
    }

    // ---------------------------------------------------------------- search

    public static function search(): array
    {
        $uid = Auth::id();
        $q = Http::str('q', '', 300);
        if (mb_strlen($q) < 2) {
            return ['results' => [], 'subjects' => [], 'cards' => []];
        }
        $subjectId = Http::int('subject_id') ?: null;
        $hits = Retriever::search($uid, $subjectId, $q, 25, ['no_vectors' => false]);
        $terms = array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: [], static fn ($t) => mb_strlen($t) > 1);
        $subjects = DB::all('SELECT id, name, color FROM subjects WHERE user_id = ?', [$uid]);
        $subjectNames = array_column($subjects, null, 'id');
        $results = [];
        foreach ($hits as $h) {
            // Snippet around the first matching term.
            $content = preg_replace('/\s+/u', ' ', $h['content']) ?? $h['content'];
            $pos = 0;
            foreach ($terms as $t) {
                $p = mb_stripos($content, $t);
                if ($p !== false) {
                    $pos = $p;
                    break;
                }
            }
            $start = max(0, $pos - 120);
            $snippet = ($start > 0 ? '…' : '') . mb_substr($content, $start, 320) . (mb_strlen($content) > $start + 320 ? '…' : '');
            $results[] = [
                'chunk_id' => (int) $h['id'],
                'source_id' => (int) $h['source_id'],
                'source_title' => $h['source_title'],
                'label' => $h['label'],
                'subject_id' => (int) $h['subject_id'],
                'subject_name' => $subjectNames[$h['subject_id']]['name'] ?? '',
                'snippet' => $snippet,
                'score' => $h['score'],
            ];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $topicHits = DB::all("SELECT t.id, t.name, t.subject_id, s.name AS subject_name FROM topics t JOIN subjects s ON s.id = t.subject_id WHERE t.user_id = ? AND t.active = 1 AND (t.name LIKE ? OR t.summary LIKE ?) LIMIT 8", [$uid, $like, $like]);
        $cards = DB::all('SELECT f.id, f.front, f.back, f.subject_id, s.name AS subject_name FROM flashcards f JOIN subjects s ON s.id = f.subject_id WHERE f.user_id = ? AND (f.front LIKE ? OR f.back LIKE ?) LIMIT 8', [$uid, $like, $like]);
        return ['results' => $results, 'topics' => $topicHits, 'cards' => $cards];
    }
}

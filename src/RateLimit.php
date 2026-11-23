<?php
declare(strict_types=1);

namespace App;

/**
 * Rate limiting for the whole API (fixed one-minute / one-hour windows, stored in the database so it
 * works across all PHP processes). Counted per user when signed in, otherwise per IP address.
 * Login, registration and password reset have their own stricter limits in Auth.
 */
final class RateLimit
{
    /** Routes that call the AI or do heavy work (uploads, copying): much stricter. */
    public const HEAVY = [
        'tutor.send', 'tutor.stream', 'essay.review', 'essay.review.stream', 'essay.extract', 'learn.start', 'learn.answer', 'learn.action',
        'guide.transform', 'guide.summary', 'guide.regenerate', 'cards.generate', 'quiz.answer', 'exam.create', 'exam.submit',
        'subjects.process', 'sources.upload', 'sources.url', 'sources.text', 'sources.retry', 'share.copy', 'search',
        'ai.keys.test', 'ai.key.add', 'ai.free_models', 'ai.reindex',
    ];

    public static function check(string $route, ?int $userId): void
    {
        $who = $userId ? 'u' . $userId : 'ip' . Http::ip();
        $rules = $userId
            ? [['all', Env::int('RATE_LIMIT_PER_MINUTE', 300), 60], ['allh', Env::int('RATE_LIMIT_PER_HOUR', 6000), 3600]]
            : [['anon', Env::int('RATE_LIMIT_ANON_PER_MINUTE', 60), 60]];
        if (in_array($route, self::HEAVY, true)) {
            $rules[] = ['heavy', Env::int('RATE_LIMIT_AI_PER_MINUTE', 20), 60];
            $rules[] = ['heavyh', Env::int('RATE_LIMIT_AI_PER_HOUR', 200), 3600];
        }
        foreach ($rules as [$name, $max, $window]) {
            if ($max > 0) {
                self::hit("$who|$name", $max, $window);
            }
        }
        if (random_int(1, 200) === 1) {
            DB::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 7200]);
        }
    }

    private static function hit(string $bucket, int $max, int $window): void
    {
        $start = intdiv(time(), $window) * $window;
        $key = substr(hash('sha256', $bucket), 0, 40) . ':' . $start;
        $sql = DB::driver() === 'mysql'
            ? 'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
            : 'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON CONFLICT(bucket) DO UPDATE SET hits = hits + 1';
        DB::exec($sql, [$key, $start]);
        $hits = (int) DB::val('SELECT hits FROM rate_limits WHERE bucket = ?', [$key]);
        if ($hits > $max) {
            $wait = max(1, $start + $window - time());
            header('Retry-After: ' . $wait);
            throw new HttpError(429, 'Du sender for mange forespørgsler. Vent ' . ($wait >= 120 ? ceil($wait / 60) . ' minutter' : $wait . ' sekunder') . ', og prøv igen.', 'too_many_requests');
        }
    }
}

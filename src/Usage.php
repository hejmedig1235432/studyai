<?php
declare(strict_types=1);

namespace App;

/**
 * Counts AI requests per user per day (UTC day — the same day OpenRouter's free limit uses)
 * and estimates how much of the daily quota is left.
 */
final class Usage
{
    /** User the current AI work is done for (0 = system / unknown). */
    public static int $userId = 0;

    public static function day(): string
    {
        return gmdate('Y-m-d');
    }

    /** Records $n requests sent to the AI provider. */
    public static function hit(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        try {
            $day = self::day();
            $uid = self::$userId;
            $updated = DB::exec('UPDATE ai_usage SET requests = requests + ?, updated_at = ? WHERE user_id = ? AND day = ?', [$n, time(), $uid, $day]);
            if (!$updated) {
                try {
                    DB::insert('ai_usage', ['user_id' => $uid, 'day' => $day, 'requests' => $n, 'updated_at' => time()]);
                } catch (\Throwable) {
                    // Another process inserted the row at the same moment.
                    DB::exec('UPDATE ai_usage SET requests = requests + ?, updated_at = ? WHERE user_id = ? AND day = ?', [$n, time(), $uid, $day]);
                }
            }
        } catch (\Throwable $e) {
            Support::log('Usage tracking failed: ' . $e->getMessage());
        }
    }

    /** Remembers the provider's own count when it sends rate-limit headers. */
    public static function providerQuota(?int $limit, ?int $remaining): void
    {
        if ($limit !== null && $limit > 0) {
            Support::setSetting('ai_quota', ['limit' => $limit, 'remaining' => $remaining, 'day' => self::day(), 'at' => time()]);
        }
    }

    public static function todayTotal(): int
    {
        return (int) DB::val('SELECT COALESCE(SUM(requests), 0) FROM ai_usage WHERE day = ?', [self::day()]);
    }

    public static function todayForUser(int $uid): int
    {
        return (int) DB::val('SELECT COALESCE(requests, 0) FROM ai_usage WHERE day = ? AND user_id = ?', [self::day(), $uid]);
    }

    /** Daily limit: env AI_DAILY_LIMIT, else what OpenRouter reported, else 50 on the free tier. */
    public static function dailyLimit(): ?int
    {
        $env = Env::int('AI_DAILY_LIMIT', 0);
        if ($env > 0) {
            return $env;
        }
        $q = Support::setting('ai_quota', null);
        if (is_array($q) && (int) ($q['limit'] ?? 0) > 0) {
            return (int) $q['limit'];
        }
        return AI::hasKeys() && AI::isFreeTier() ? 50 : null;
    }

    /** Per-user daily cap set by the admin (0 = no cap). */
    public static function userLimit(): int
    {
        return max(0, (int) Support::setting('ai_user_daily_limit', 0));
    }

    /** Throws when the current (non-admin) user has used their personal daily AI allowance. */
    public static function checkUserLimit(): void
    {
        $limit = self::userLimit();
        if ($limit <= 0 || self::$userId <= 0) {
            return;
        }
        $role = (string) DB::val('SELECT role FROM users WHERE id = ?', [self::$userId]);
        if ($role === 'admin') {
            return;
        }
        if (self::todayForUser(self::$userId) >= $limit) {
            throw new HttpError(429, "Du har brugt dine $limit AI-forespørgsler for i dag. Grænsen nulstilles kl. "
                . date('H:i', strtotime('tomorrow 00:00:00 UTC')) . '. Du kan stadig læse studieguiden og øve dine flashcards.', 'user_limit');
        }
    }

    /** API: today's usage for the meter. */
    public static function meter(): array
    {
        $u = Auth::require();
        $limit = self::dailyLimit();
        $used = self::todayTotal();
        $resetAt = AI::dailyLimitResetAt();
        $q = Support::setting('ai_quota', null);
        // Prefer the provider's own "remaining" count when it is from today and recent.
        $remaining = $limit !== null ? max(0, $limit - $used) : null;
        if (is_array($q) && ($q['day'] ?? '') === self::day() && isset($q['remaining']) && $q['remaining'] !== null) {
            $remaining = min($remaining ?? PHP_INT_MAX, (int) $q['remaining']);
        }
        if ($resetAt) {
            $remaining = 0;
        }
        $userLimit = self::userLimit();
        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'reset_at' => $resetAt ?: strtotime('tomorrow 00:00:00 UTC'),
            'limited' => (bool) $resetAt,
            'mine' => self::todayForUser((int) $u['id']),
            'my_limit' => $u['role'] === 'admin' ? 0 : $userLimit,
            'free_tier' => AI::hasKeys() && AI::isFreeTier(),
        ];
    }
}

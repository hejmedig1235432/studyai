<?php
declare(strict_types=1);

namespace App;

/**
 * Study plan from today until the test date. Computed from the subject's state (no AI requests):
 * read the guide → learn topics (weakest first) → quiz each topic the day after → daily flashcards
 * → practice exam two days before → mistakes & weak topics the day before.
 * Tasks are ticked off automatically from what the student actually did, or manually.
 */
final class Plan
{
    private static function dates(string $from, string $toExclusive): array
    {
        $out = [];
        for ($d = strtotime($from); $d < strtotime($toExclusive) && count($out) < 120; $d += 86400) {
            $out[] = date('Y-m-d', $d);
        }
        return $out;
    }

    public static function build(array $subject, int $uid): array
    {
        $exam = (string) $subject['exam_date'];
        $today = date('Y-m-d');
        $days = $exam > $today ? self::dates($today, $exam) : [$today];
        $n = count($days);
        $topics = DB::all('SELECT t.id, t.name, COALESCE(tp.mastery,0) AS mastery, COALESCE(tp.understood,0) AS understood
            FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = ?
            WHERE t.subject_id = ? AND t.active = 1 ORDER BY t.position', [$uid, $subject['id']]);
        // Not-yet-learned and weak topics first, otherwise course order.
        usort($topics, static fn ($a, $b) => [(int) $a['understood'], (float) $a['mastery']] <=> [(int) $b['understood'], (float) $b['mastery']]);
        $unread = DB::all('SELECT g.id, g.title FROM guide_sections g JOIN topics t ON t.id = g.topic_id
            WHERE g.subject_id = ? AND t.active = 1 AND g.read_at IS NULL ORDER BY g.position', [$subject['id']]);

        $plan = array_fill(0, $n, []);
        $add = static function (int $day, array $task) use (&$plan, $n) {
            $day = max(0, min($n - 1, $day));
            $task['key'] = $task['type'] . ':' . ($task['topic_id'] ?? 'all') . ':' . $day;
            $plan[$day][] = $task;
        };

        // Last days are reserved for the practice exam and the final review.
        $reserved = $n >= 4 ? 2 : ($n >= 2 ? 1 : 0);
        $workDays = max(1, $n - $reserved);

        // 1) Reading: spread unread sections over the first ~25 % of the days.
        $readDays = $unread ? max(1, min($workDays, (int) ceil($workDays * 0.25))) : 0;
        foreach (array_chunk($unread, max(1, (int) ceil(count($unread) / max(1, $readDays)))) as $i => $group) {
            $add($i, ['type' => 'read', 'title' => 'Læs i studieguiden: ' . implode(', ', array_column($group, 'title')), 'section_ids' => array_map('intval', array_column($group, 'id'))]);
        }

        // 2) Learn each topic, then quiz it the day after.
        $start = $n === 1 ? 0 : min($readDays, $workDays - 1);
        $span = max(1, $workDays - $start);
        foreach ($topics as $i => $t) {
            $day = $start + (int) floor($i * $span / max(1, count($topics)));
            if (!(int) $t['understood']) {
                $add($day, ['type' => 'learn', 'title' => 'Lær emnet: ' . $t['name'], 'topic_id' => (int) $t['id']]);
            }
            $add(min($day + 1, $workDays - 1), ['type' => 'quiz', 'title' => 'Quiz: ' . $t['name'], 'topic_id' => (int) $t['id']]);
        }

        // 3) Flashcards every day (after the first reading day).
        for ($d = ($n > 1 && $readDays ? 1 : 0); $d < $n; $d++) {
            $add($d, ['type' => 'cards', 'title' => 'Dagens flashcards']);
        }

        // 4) Practice exam two days before, then mistakes + weak topics the day before.
        if ($n >= 3) {
            $add($n - 2, ['type' => 'exam', 'title' => 'Prøveeksamen med tid']);
        }
        if ($n >= 2) {
            $add($n - 1, ['type' => 'review', 'title' => 'Gentag dine fejl og svage emner']);
        }
        $add($n - 1, ['type' => 'final', 'title' => 'Læs resuméet på én side og “Husk til prøven” i studieguiden']);

        $out = [];
        foreach ($days as $i => $date) {
            $out[] = ['date' => $date, 'tasks' => $plan[$i]];
        }
        return ['exam_date' => $exam, 'generated_at' => time(), 'days' => $out, 'done' => []];
    }

    /** Whether a task was completed, based on what the student actually did. */
    private static function autoDone(array $task, string $date, int $subjectId, int $uid): bool
    {
        $from = strtotime($date . ' 00:00:00');
        $to = $from + 86400;
        switch ($task['type']) {
            case 'read':
                $ids = $task['section_ids'] ?? [];
                return $ids && !(int) DB::val('SELECT COUNT(*) FROM guide_sections WHERE read_at IS NULL AND id IN (' . DB::in($ids) . ')', $ids);
            case 'learn':
                return (bool) DB::val('SELECT understood FROM topic_progress WHERE user_id = ? AND topic_id = ?', [$uid, $task['topic_id']]);
            case 'quiz':
                return (bool) DB::val('SELECT COUNT(*) FROM answers WHERE user_id = ? AND topic_id = ? AND created_at >= ?', [$uid, $task['topic_id'], $from]);
            case 'cards':
                return (int) DB::val('SELECT COUNT(*) FROM card_reviews WHERE user_id = ? AND subject_id = ? AND created_at >= ? AND created_at < ?', [$uid, $subjectId, $from, $to]) >= 5;
            case 'exam':
                return (bool) DB::val("SELECT COUNT(*) FROM attempts WHERE user_id = ? AND subject_id = ? AND mode = 'exam' AND status = 'finished' AND finished_at >= ?", [$uid, $subjectId, $from]);
            case 'review':
                return (int) DB::val('SELECT COUNT(*) FROM answers WHERE user_id = ? AND subject_id = ? AND created_at >= ? AND created_at < ?', [$uid, $subjectId, $from, $to]) >= 5;
        }
        return false;
    }

    /** Adds done/today/overdue flags to the stored plan. */
    private static function present(array $subject, int $uid): ?array
    {
        $plan = Support::decode($subject['plan'], null);
        if (!is_array($plan) || empty($plan['days'])) {
            return null;
        }
        $today = date('Y-m-d');
        $manual = (array) ($plan['done'] ?? []);
        $total = 0;
        $done = 0;
        foreach ($plan['days'] as &$day) {
            foreach ($day['tasks'] as &$t) {
                $t['done'] = array_key_exists($t['key'], $manual) ? (bool) $manual[$t['key']] : ($day['date'] <= $today && self::autoDone($t, $day['date'], (int) $subject['id'], $uid));
                $t['manual'] = array_key_exists($t['key'], $manual);
                $total++;
                $done += $t['done'] ? 1 : 0;
            }
            unset($t);
            $day['is_today'] = $day['date'] === $today;
            $day['is_past'] = $day['date'] < $today;
        }
        unset($day);
        $plan['progress'] = ['done' => $done, 'total' => $total];
        $plan['outdated'] = $plan['exam_date'] !== (string) $subject['exam_date'];
        return $plan;
    }

    public static function get(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        return ['plan' => self::present($s, $uid), 'exam_date' => $s['exam_date']];
    }

    public static function generate(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $date = Http::str('exam_date', (string) $s['exam_date'], 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw HttpError::bad('Vælg den dato, hvor du skal til prøve.');
        }
        if ($date < date('Y-m-d')) {
            throw HttpError::bad('Prøvedatoen ligger i fortiden. Vælg en dato fra i dag og frem.');
        }
        if (!DB::val('SELECT COUNT(*) FROM topics WHERE subject_id = ? AND active = 1', [$s['id']])) {
            throw HttpError::bad('Faget har ingen emner endnu. Tilføj kilder og kør analysen først.');
        }
        DB::update('subjects', ['exam_date' => $date], 'id = ?', [$s['id']]);
        $s['exam_date'] = $date;
        DB::update('subjects', ['plan' => json_encode(self::build($s, $uid), JSON_UNESCAPED_UNICODE)], 'id = ?', [$s['id']]);
        $s = Subjects::owned((int) $s['id'], $uid);
        return ['plan' => self::present($s, $uid), 'exam_date' => $s['exam_date']];
    }

    public static function toggle(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $plan = Support::decode($s['plan'], null);
        if (!is_array($plan)) {
            throw HttpError::bad('Der er ingen studieplan endnu.');
        }
        $key = Http::str('key', '', 120);
        $plan['done'] = (array) ($plan['done'] ?? []);
        $plan['done'][$key] = (bool) (Http::input()['done'] ?? true);
        DB::update('subjects', ['plan' => json_encode($plan, JSON_UNESCAPED_UNICODE)], 'id = ?', [$s['id']]);
        return self::get();
    }

    /** Today's tasks across all subjects (for the dashboard). */
    public static function today(int $uid): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM subjects WHERE user_id = ? AND plan IS NOT NULL', [$uid]) as $s) {
            $p = self::present($s, $uid);
            foreach ((array) ($p['days'] ?? []) as $day) {
                if ($day['is_today']) {
                    foreach ($day['tasks'] as $t) {
                        $out[] = $t + ['subject_id' => (int) $s['id'], 'subject_name' => $s['name'], 'color' => $s['color']];
                    }
                }
            }
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace App;

/** Learning analytics: topic mastery, study time, streaks and the dashboard. */
final class Progress
{
    /** Updates a topic's mastery with an exponential moving average of performance (0..1). */
    public static function recordTopic(int $uid, ?int $topicId, float $score, float $weight = 0.3, bool $countAnswer = true): void
    {
        if (!$topicId) {
            return;
        }
        $topic = DB::one('SELECT subject_id FROM topics WHERE id = ? AND user_id = ?', [$topicId, $uid]);
        if (!$topic) {
            return;
        }
        $row = DB::one('SELECT * FROM topic_progress WHERE user_id = ? AND topic_id = ?', [$uid, $topicId]);
        if (!$row) {
            DB::insert('topic_progress', ['user_id' => $uid, 'subject_id' => $topic['subject_id'], 'topic_id' => $topicId, 'mastery' => 0, 'last_seen_at' => time()]);
            $row = ['mastery' => 0, 'correct' => 0, 'incorrect' => 0];
        }
        $mastery = (float) $row['mastery'] + $weight * ($score - (float) $row['mastery']);
        $data = ['mastery' => round(max(0, min(1, $mastery)), 4), 'last_seen_at' => time()];
        if ($countAnswer) {
            if ($score >= 0.7) {
                $data['correct'] = (int) $row['correct'] + 1;
            } else {
                $data['incorrect'] = (int) $row['incorrect'] + 1;
            }
        }
        DB::update('topic_progress', $data, 'user_id = ? AND topic_id = ?', [$uid, $topicId]);
    }

    public static function markUnderstood(int $uid, int $topicId): void
    {
        self::recordTopic($uid, $topicId, 1.0, 0.25, false);
        DB::exec('UPDATE topic_progress SET understood = 1 WHERE user_id = ? AND topic_id = ?', [$uid, $topicId]);
    }

    /** API: time tracking heartbeat from the study views. */
    public static function heartbeat(): array
    {
        $uid = Auth::id();
        $seconds = max(0, min(120, Http::int('seconds')));
        $activity = preg_replace('/[^a-z_]/', '', Http::str('activity', 'study', 32)) ?: 'study';
        $subjectId = Http::int('subject_id') ?: null;
        if ($subjectId && !DB::val('SELECT id FROM subjects WHERE id = ? AND user_id = ?', [$subjectId, $uid])) {
            $subjectId = null;
        }
        if ($seconds > 0) {
            $day = Support::today();
            $row = DB::one('SELECT id FROM study_log WHERE user_id = ? AND day = ? AND activity = ? AND ' . ($subjectId ? 'subject_id = ?' : 'subject_id IS NULL'),
                array_values(array_filter([$uid, $day, $activity, $subjectId], static fn ($v) => $v !== null)));
            if ($row) {
                DB::exec('UPDATE study_log SET seconds = seconds + ?, updated_at = ? WHERE id = ?', [$seconds, time(), $row['id']]);
            } else {
                DB::insert('study_log', ['user_id' => $uid, 'subject_id' => $subjectId, 'activity' => $activity, 'day' => $day, 'seconds' => $seconds, 'updated_at' => time()]);
            }
            if ($subjectId) {
                DB::update('subjects', ['last_studied_at' => time()], 'id = ?', [$subjectId]);
            }
            $topicId = Http::int('topic_id');
            if ($topicId) {
                DB::exec('UPDATE topic_progress SET seconds = seconds + ? WHERE user_id = ? AND topic_id = ?', [$seconds, $uid, $topicId]);
            }
        }
        return ['ok' => true];
    }

    public static function streak(int $uid): int
    {
        $days = array_flip(array_merge(
            DB::col('SELECT DISTINCT day FROM study_log WHERE user_id = ? AND seconds >= 30', [$uid]),
            array_map(static fn ($t) => date('Y-m-d', (int) $t), DB::col('SELECT created_at FROM answers WHERE user_id = ? AND created_at > ?', [$uid, time() - 400 * 86400])),
            array_map(static fn ($t) => date('Y-m-d', (int) $t), DB::col('SELECT created_at FROM card_reviews WHERE user_id = ? AND created_at > ?', [$uid, time() - 400 * 86400]))
        ));
        $streak = 0;
        $offset = isset($days[Support::today()]) ? 0 : -1;
        while (isset($days[Support::today($offset)])) {
            $streak++;
            $offset--;
        }
        return $streak;
    }

    public static function dashboard(): array
    {
        $uid = Auth::id();
        $now = time();
        $subjects = DB::all('SELECT * FROM subjects WHERE user_id = ? ORDER BY COALESCE(last_studied_at, updated_at) DESC', [$uid]);
        $totalSeconds = (int) DB::val('SELECT COALESCE(SUM(seconds),0) FROM study_log WHERE user_id = ?', [$uid]);
        $todaySeconds = (int) DB::val('SELECT COALESCE(SUM(seconds),0) FROM study_log WHERE user_id = ? AND day = ?', [$uid, Support::today()]);
        $quizzes = (int) DB::val("SELECT COUNT(*) FROM attempts WHERE user_id = ? AND status = 'finished' AND mode IN ('quiz','exam')", [$uid]);
        $avg = DB::val("SELECT AVG(score) FROM attempts WHERE user_id = ? AND status = 'finished' AND mode IN ('quiz','exam') AND total > 0", [$uid]);
        $mastered = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE user_id = ? AND interval_days >= 21', [$uid]);
        $cardsTotal = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE user_id = ?', [$uid]);
        $overall = (float) DB::val('SELECT COALESCE(AVG(COALESCE(tp.mastery,0)),0) FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = t.user_id WHERE t.user_id = ? AND t.active = 1', [$uid]);
        $weak = DB::all('SELECT t.id, t.name, t.subject_id, s.name AS subject_name, tp.mastery, tp.correct, tp.incorrect
            FROM topic_progress tp JOIN topics t ON t.id = tp.topic_id JOIN subjects s ON s.id = t.subject_id
            WHERE tp.user_id = ? AND t.active = 1 AND (tp.correct + tp.incorrect) > 0 ORDER BY tp.mastery ASC LIMIT 5', [$uid]);
        $dueNow = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE user_id = ? AND due_at IS NOT NULL AND due_at <= ?', [$uid, $now]);
        $newCards = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE user_id = ? AND due_at IS NULL', [$uid]);
        $mistakesDue = (int) DB::val('SELECT COUNT(*) FROM questions WHERE user_id = ? AND srs_due_at IS NOT NULL AND srs_due_at <= ?', [$uid, $now]);
        $upcoming = [];
        for ($d = 0; $d < 7; $d++) {
            $start = strtotime(Support::today($d) . ' 00:00:00');
            $end = $start + 86400;
            $upcoming[] = [
                'day' => Support::today($d),
                'cards' => (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE user_id = ? AND due_at >= ? AND due_at < ?', [$uid, $d === 0 ? 0 : $start, $end]),
                'questions' => (int) DB::val('SELECT COUNT(*) FROM questions WHERE user_id = ? AND srs_due_at >= ? AND srs_due_at < ?', [$uid, $d === 0 ? 0 : $start, $end]),
            ];
        }
        $activity = [];
        $logRows = DB::all('SELECT day, SUM(seconds) AS s FROM study_log WHERE user_id = ? AND day >= ? GROUP BY day', [$uid, Support::today(-13)]);
        $byDay = array_column($logRows, 's', 'day');
        for ($d = -13; $d <= 0; $d++) {
            $activity[] = ['day' => Support::today($d), 'seconds' => (int) ($byDay[Support::today($d)] ?? 0)];
        }

        // Recommendations, most useful first.
        $rec = [];
        foreach ($subjects as $s) {
            [$gt, $gr] = Guide::readingProgress((int) $s['id']);
            if ($gt > 0 && $gr < $gt) {
                $rec[] = ['kind' => 'read', 'title' => 'Læs studieguiden til “' . $s['name'] . '”', 'detail' => "$gr af $gt afsnit læst. Flashcards og quizzer låses op, når du har læst det hele.", 'href' => '#/s/' . $s['id'] . '/guide'];
                break;
            }
        }
        if ($dueNow + $mistakesDue > 0) {
            $rec[] = ['kind' => 'memory', 'title' => 'Dagens gentagelse', 'detail' => trim(($dueNow ? "$dueNow flashcards" : '') . ($dueNow && $mistakesDue ? ' og ' : '') . ($mistakesDue ? "$mistakesDue spørgsmål, du svarede forkert på," : '')) . ' skal gentages.', 'href' => '#/memory'];
        }
        foreach ($weak as $w) {
            if ((float) $w['mastery'] < 0.65) {
                $rec[] = ['kind' => 'quiz', 'title' => 'Styrk “' . $w['name'] . '”', 'detail' => 'Dit svageste emne i ' . $w['subject_name'] . ' (' . round((float) $w['mastery'] * 100) . '% beherskelse). Tag en målrettet quiz.', 'href' => '#/s/' . $w['subject_id'] . '/quiz?topic=' . $w['id']];
                break;
            }
        }
        $fresh = DB::one('SELECT t.id, t.name, t.subject_id, s.name AS subject_name FROM topics t JOIN subjects s ON s.id = t.subject_id
            LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = t.user_id
            WHERE t.user_id = ? AND t.active = 1 AND tp.id IS NULL ORDER BY s.last_studied_at DESC, t.position LIMIT 1', [$uid]);
        if ($fresh) {
            $rec[] = ['kind' => 'learn', 'title' => 'Lær “' . $fresh['name'] . '”', 'detail' => 'Du har ikke læst dette emne i ' . $fresh['subject_name'] . ' endnu. Trin-for-trin-læring guider dig igennem det.', 'href' => '#/s/' . $fresh['subject_id'] . '/learn?topic=' . $fresh['id']];
        }
        if ($newCards > 0 && count($rec) < 3) {
            $rec[] = ['kind' => 'cards', 'title' => 'Start på nye flashcards', 'detail' => "$newCards flashcards venter på første gennemgang.", 'href' => '#/memory'];
        }
        foreach ($subjects as $s) {
            if (in_array($s['status'], ['outdated', 'error'], true)) {
                $rec[] = ['kind' => 'process', 'title' => 'Opdatér “' . $s['name'] . '”', 'detail' => 'Kilderne er ændret. Analysér igen for at opdatere studieguide og spørgsmål.', 'href' => '#/s/' . $s['id'] . '/sources'];
                break;
            }
        }
        if (!$subjects) {
            $rec[] = ['kind' => 'create', 'title' => 'Opret dit første fag', 'detail' => 'Upload dine noter, slides eller en video, og få en studieguide, quizzer og flashcards.', 'href' => '#/new'];
        }
        foreach ($subjects as $s) {
            if ($s['exam_date'] && strtotime($s['exam_date']) >= strtotime(Support::today())) {
                $days = (int) round((strtotime($s['exam_date']) - strtotime(Support::today())) / 86400);
                if ($days <= 14) {
                    array_unshift($rec, ['kind' => 'exam', 'title' => 'Prøve i ' . $s['name'] . ' om ' . $days . ($days === 1 ? ' dag' : ' dage'), 'detail' => 'Tag en prøveeksamen med tid for at se, hvor du står.', 'href' => '#/s/' . $s['id'] . '/exam']);
                    break;
                }
            }
        }

        return [
            'stats' => [
                'total_seconds' => $totalSeconds,
                'today_seconds' => $todaySeconds,
                'streak' => self::streak($uid),
                'quizzes_completed' => $quizzes,
                'average_score' => $avg === null ? null : round((float) $avg * 100),
                'cards_mastered' => $mastered,
                'cards_total' => $cardsTotal,
                'overall_progress' => (int) round($overall * 100),
                'due_now' => $dueNow + $mistakesDue,
            ],
            'plan_today' => Plan::today($uid),
            'weak_topics' => array_map(static fn ($w) => ['id' => (int) $w['id'], 'name' => $w['name'], 'subject_id' => (int) $w['subject_id'], 'subject_name' => $w['subject_name'], 'mastery' => round((float) $w['mastery'], 3)], $weak),
            'recommendations' => array_slice($rec, 0, 4),
            'upcoming' => $upcoming,
            'activity' => $activity,
            'continue' => array_map([Subjects::class, 'summary'], array_slice($subjects, 0, 4)),
            'recent_attempts' => DB::all("SELECT a.id, a.mode, a.score, a.correct, a.total, a.finished_at, a.subject_id, s.name AS subject_name FROM attempts a JOIN subjects s ON s.id = a.subject_id WHERE a.user_id = ? AND a.status = 'finished' ORDER BY a.finished_at DESC LIMIT 6", [$uid]),
        ];
    }

    /** Per-subject progress page: topics over time, accuracy, time per topic. */
    public static function subject(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $history = DB::all("SELECT id, mode, score, correct, total, finished_at FROM attempts WHERE subject_id = ? AND status = 'finished' ORDER BY finished_at", [$s['id']]);
        $answers = DB::all('SELECT is_correct, created_at FROM answers WHERE subject_id = ? ORDER BY created_at', [$s['id']]);
        $seconds = (int) DB::val('SELECT COALESCE(SUM(seconds),0) FROM study_log WHERE subject_id = ?', [$s['id']]);
        return [
            'history' => $history,
            'answered' => count($answers),
            'correct' => count(array_filter($answers, static fn ($a) => (int) $a['is_correct'] === 1)),
            'seconds' => $seconds,
        ];
    }
}

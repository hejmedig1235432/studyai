<?php
declare(strict_types=1);

namespace App;

/** Flashcards with spaced repetition (SM-2 style) and the Memory Training views. */
final class Cards
{
    public const MASTERED_DAYS = 21;

    /** Estimated chance (0..1) the student still remembers a card right now. */
    public static function retention(array $c): ?float
    {
        if (!$c['last_reviewed_at']) {
            return null;
        }
        $elapsed = max(0, time() - (int) $c['last_reviewed_at']) / 86400;
        $stability = max(0.02, (float) $c['interval_days']);
        return round(exp(log(0.9) * $elapsed / $stability), 3);
    }

    public static function present(array $c, ?array $refs = null): array
    {
        return [
            'id' => (int) $c['id'],
            'subject_id' => (int) $c['subject_id'],
            'topic_id' => $c['topic_id'] ? (int) $c['topic_id'] : null,
            'topic' => $c['topic_name'] ?? null,
            'front' => $c['front'],
            'back' => $c['back'],
            'refs' => Support::decode($c['refs']),
            'reps' => (int) $c['reps'],
            'lapses' => (int) $c['lapses'],
            'interval_days' => (float) $c['interval_days'],
            'due_at' => $c['due_at'] ? (int) $c['due_at'] : null,
            'last_rating' => $c['last_rating'],
            'mastered' => (float) $c['interval_days'] >= self::MASTERED_DAYS,
            'retention' => self::retention($c),
            'state' => !$c['last_reviewed_at'] ? 'new' : ((float) $c['interval_days'] >= self::MASTERED_DAYS ? 'mastered' : ($c['last_rating'] === 'hard' ? 'struggling' : 'learning')),
        ];
    }

    private static function refsFor(array $cards, int $uid): array
    {
        $ids = [];
        foreach ($cards as $c) {
            foreach (Support::decode($c['refs']) as $r) {
                $ids[] = (int) substr((string) $r, 1);
            }
        }
        return Retriever::refMeta($ids, $uid);
    }

    public static function list(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $rows = DB::all('SELECT f.*, t.name AS topic_name FROM flashcards f LEFT JOIN topics t ON t.id = f.topic_id WHERE f.subject_id = ? ORDER BY t.position, f.id', [$s['id']]);
        $cards = array_map([self::class, 'present'], $rows);
        $stats = ['total' => count($cards), 'new' => 0, 'learning' => 0, 'struggling' => 0, 'mastered' => 0, 'due' => 0];
        foreach ($cards as $c) {
            $stats[$c['state']]++;
            if ($c['due_at'] === null || $c['due_at'] <= time()) {
                $stats['due']++;
            }
        }
        return ['cards' => $cards, 'stats' => $stats, 'refs' => self::refsFor($rows, $uid)];
    }

    /** Ordered study queue: due/struggling first, then new cards. */
    public static function session(): array
    {
        $uid = Auth::id();
        $subjectId = Http::int('subject_id');
        $mode = Http::str('mode', 'smart');
        $limit = max(5, min(200, Http::int('limit', 20)));
        $where = 'f.user_id = ?';
        $params = [$uid];
        if ($subjectId) {
            Subjects::owned($subjectId, $uid);
            Guide::requireRead($subjectId);
            $where .= ' AND f.subject_id = ?';
            $params[] = $subjectId;
        } else {
            // Daily review across subjects: leave out subjects whose study guide hasn't been read yet.
            foreach (DB::col('SELECT id FROM subjects WHERE user_id = ?', [$uid]) as $sid) {
                [$t, $d] = Guide::readingProgress((int) $sid);
                if ($t > 0 && $d < $t) {
                    $where .= ' AND f.subject_id != ?';
                    $params[] = (int) $sid;
                }
            }
        }
        if ($topic = Http::int('topic_id')) {
            $where .= ' AND f.topic_id = ?';
            $params[] = $topic;
        }
        $rows = DB::all("SELECT f.*, t.name AS topic_name FROM flashcards f LEFT JOIN topics t ON t.id = f.topic_id WHERE $where", $params);
        $now = time();
        if ($mode === 'hard') {
            $rows = array_filter($rows, static fn ($r) => $r['last_rating'] === 'hard' || (int) $r['lapses'] > 0 || (float) $r['ease'] < 2.2);
        } elseif ($mode === 'smart') {
            $rows = array_filter($rows, static fn ($r) => $r['due_at'] === null || (int) $r['due_at'] <= $now);
        }
        $rows = array_values($rows);
        usort($rows, static function ($a, $b) use ($now) {
            // Overdue (most overdue first) → never seen → the rest by difficulty.
            $pa = $a['due_at'] !== null && (int) $a['due_at'] <= $now ? 0 : ($a['due_at'] === null ? 1 : 2);
            $pb = $b['due_at'] !== null && (int) $b['due_at'] <= $now ? 0 : ($b['due_at'] === null ? 1 : 2);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($pa === 0) {
                return (int) $a['due_at'] <=> (int) $b['due_at'];
            }
            return (float) $a['ease'] <=> (float) $b['ease'];
        });
        // Keep new cards from flooding a session.
        $out = [];
        $newCount = 0;
        foreach ($rows as $r) {
            if ($r['due_at'] === null && $mode === 'smart' && $newCount >= max(10, intdiv($limit, 2))) {
                continue;
            }
            if ($r['due_at'] === null) {
                $newCount++;
            }
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        if ($mode === 'all') {
            shuffle($out);
        }
        return ['cards' => array_map([self::class, 'present'], $out), 'refs' => self::refsFor($out, $uid)];
    }

    public static function schedule(array $c, string $rating): array
    {
        $ease = (float) $c['ease'];
        $interval = (float) $c['interval_days'];
        $reps = (int) $c['reps'];
        $lapses = (int) $c['lapses'];
        switch ($rating) {
            case 'hard':
                if ($reps > 0) {
                    $lapses++;
                }
                $reps = 0;
                $interval = 0.007; // ~10 minutes: repeat in this session / soon
                $ease = max(1.3, $ease - 0.2);
                break;
            case 'medium':
                $reps++;
                $interval = $reps === 1 ? 1 : ($reps === 2 ? 3 : round(max($interval, 1) * $ease, 1));
                $ease = max(1.3, $ease - 0.02);
                break;
            case 'easy':
                $reps++;
                $interval = $reps === 1 ? 3 : ($reps === 2 ? 7 : round(max($interval, 1) * $ease * 1.3, 1));
                $ease = min(3.0, $ease + 0.1);
                break;
            default:
                throw HttpError::bad('Vurderingen skal være let, middel eller svær.');
        }
        $interval = min($interval, 365);
        return [
            'ease' => round($ease, 3),
            'interval_days' => $interval,
            'reps' => $reps,
            'lapses' => $lapses,
            'due_at' => time() + (int) round($interval * 86400),
            'last_rating' => $rating,
            'last_reviewed_at' => time(),
        ];
    }

    public static function review(): array
    {
        $uid = Auth::id();
        $c = DB::one('SELECT * FROM flashcards WHERE id = ? AND user_id = ?', [Http::int('card_id'), $uid]);
        if (!$c) {
            throw HttpError::notFound('Flashcard');
        }
        $rating = Http::str('rating');
        $next = self::schedule($c, $rating);
        DB::update('flashcards', $next, 'id = ?', [$c['id']]);
        DB::insert('card_reviews', ['user_id' => $uid, 'subject_id' => $c['subject_id'], 'card_id' => $c['id'], 'rating' => $rating, 'created_at' => time()]);
        Progress::recordTopic($uid, $c['topic_id'] ? (int) $c['topic_id'] : null, ['easy' => 1.0, 'medium' => 0.7, 'hard' => 0.15][$rating], 0.1, false);
        DB::update('subjects', ['last_studied_at' => time()], 'id = ?', [$c['subject_id']]);
        $c = array_merge($c, $next);
        $c['topic_name'] = null;
        return ['card' => self::present($c)];
    }

    public static function generate(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        Auth::release();
        $topicId = Http::int('topic_id');
        $count = max(3, min(25, Http::int('count', 10)));
        $topics = $topicId
            ? DB::all('SELECT * FROM topics WHERE id = ? AND subject_id = ?', [$topicId, $s['id']])
            : DB::all('SELECT * FROM topics WHERE subject_id = ? AND active = 1 ORDER BY position', [$s['id']]);
        if (!$topics) {
            throw HttpError::bad('Ingen emner endnu. Analysér dine kilder først.');
        }
        if (!$topicId) {
            // Spread across the weakest topics with the fewest cards.
            usort($topics, static fn ($a, $b) => (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE topic_id = ?', [$a['id']]) <=> (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE topic_id = ?', [$b['id']]));
            $topics = array_slice($topics, 0, 3);
        }
        $per = (int) ceil($count / count($topics));
        $added = 0;
        foreach ($topics as $t) {
            $chunks = Analyzer::topicChunks($s, $t, 18);
            shuffle($chunks);
            $added += Analyzer::generateCards($s, $t, Retriever::context($chunks, 20000), $per);
        }
        return ['added' => $added];
    }

    public static function save(): array
    {
        $uid = Auth::id();
        $front = Http::str('front', '', 500);
        $back = Http::str('back', '', 1500);
        if ($front === '' || $back === '') {
            throw HttpError::bad('Begge sider af kortet skal have tekst.');
        }
        $id = Http::int('id');
        if ($id) {
            $n = DB::update('flashcards', ['front' => $front, 'back' => $back, 'hash' => md5(Support::norm($front))], 'id = ? AND user_id = ?', [$id, $uid]);
            if (!$n && !DB::val('SELECT id FROM flashcards WHERE id = ? AND user_id = ?', [$id, $uid])) {
                throw HttpError::notFound('Flashcard');
            }
        } else {
            $s = Subjects::owned(Http::int('subject_id'), $uid);
            $topicId = Http::int('topic_id') ?: null;
            if ($topicId && !DB::val('SELECT id FROM topics WHERE id = ? AND subject_id = ?', [$topicId, $s['id']])) {
                $topicId = null;
            }
            $id = DB::insert('flashcards', [
                'user_id' => $uid, 'subject_id' => $s['id'], 'topic_id' => $topicId, 'front' => $front, 'back' => $back,
                'refs' => '[]', 'hash' => md5(Support::norm($front)), 'created_at' => time(),
            ]);
        }
        return ['id' => $id];
    }

    public static function delete(): array
    {
        $uid = Auth::id();
        $id = Http::int('id');
        DB::exec('DELETE FROM card_reviews WHERE card_id = ? AND user_id = ?', [$id, $uid]);
        if (!DB::exec('DELETE FROM flashcards WHERE id = ? AND user_id = ?', [$id, $uid])) {
            throw HttpError::notFound('Flashcard');
        }
        return ['ok' => true];
    }

    public static function reset(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        DB::exec('UPDATE flashcards SET ease = 2.5, interval_days = 0, reps = 0, lapses = 0, due_at = NULL, last_rating = NULL, last_reviewed_at = NULL WHERE subject_id = ? AND user_id = ?', [$s['id'], $uid]);
        return ['ok' => true];
    }

    public static function export(): never
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $format = Http::str('format', 'csv');
        $rows = DB::all('SELECT f.front, f.back, t.name AS topic FROM flashcards f LEFT JOIN topics t ON t.id = f.topic_id WHERE f.subject_id = ? ORDER BY t.position, f.id', [$s['id']]);
        $base = $s['name'] . ' - flashcards';
        if ($format === 'json') {
            Http::download("$base.json", 'application/json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        if ($format === 'anki') {
            // Anki: File → Import, "Fields separated by: Tab", allow HTML.
            $out = "#separator:tab\n#html:true\n#columns:Front\tBack\tTags\n";
            foreach ($rows as $r) {
                $tag = preg_replace('/\s+/', '_', (string) ($r['topic'] ?? ''));
                $out .= str_replace(["\t", "\n"], [' ', '<br>'], $r['front']) . "\t" . str_replace(["\t", "\n"], [' ', '<br>'], $r['back']) . "\t" . $tag . "\n";
            }
            Http::download("$base (Anki).txt", 'text/plain; charset=utf-8', $out);
        }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Front', 'Back', 'Topic']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['front'], $r['back'], $r['topic']]);
        }
        rewind($fh);
        Http::download("$base.csv", 'text/csv; charset=utf-8', (string) stream_get_contents($fh));
    }

    // ------------------------------------------------------------- memory training

    public static function memory(): array
    {
        $uid = Auth::id();
        $subjectId = Http::int('subject_id') ?: null;
        $where = 'f.user_id = ?' . ($subjectId ? ' AND f.subject_id = ?' : '');
        $params = $subjectId ? [$uid, $subjectId] : [$uid];
        if ($subjectId) {
            Subjects::owned($subjectId, $uid);
        }
        $cards = DB::all("SELECT f.*, t.name AS topic_name, s.name AS subject_name FROM flashcards f LEFT JOIN topics t ON t.id = f.topic_id JOIN subjects s ON s.id = f.subject_id WHERE $where", $params);
        $now = time();
        $topicStrength = [];
        $forgotten = [];
        $due = 0;
        $new = 0;
        foreach ($cards as $c) {
            $r = self::retention($c);
            if ($c['due_at'] === null) {
                $new++;
            } elseif ((int) $c['due_at'] <= $now) {
                $due++;
            }
            if ($r !== null) {
                $key = $c['topic_id'] ?: 0;
                $topicStrength[$key] ??= ['topic_id' => $c['topic_id'] ? (int) $c['topic_id'] : null, 'name' => $c['topic_name'] ?? 'Generelt', 'subject' => $c['subject_name'], 'subject_id' => (int) $c['subject_id'], 'sum' => 0, 'n' => 0];
                $topicStrength[$key]['sum'] += $r;
                $topicStrength[$key]['n']++;
                if ($r < 0.6 || ($c['last_rating'] === 'hard' && (int) $c['lapses'] > 0)) {
                    $forgotten[] = ['id' => (int) $c['id'], 'front' => $c['front'], 'topic' => $c['topic_name'], 'subject' => $c['subject_name'], 'retention' => $r];
                }
            }
        }
        $strength = array_values(array_map(static fn ($t) => [
            'topic_id' => $t['topic_id'], 'name' => $t['name'], 'subject' => $t['subject'], 'subject_id' => $t['subject_id'],
            'strength' => round($t['sum'] / max(1, $t['n']), 3), 'cards' => $t['n'],
        ], $topicStrength));
        usort($strength, static fn ($a, $b) => $a['strength'] <=> $b['strength']);
        usort($forgotten, static fn ($a, $b) => $a['retention'] <=> $b['retention']);
        $qWhere = 'user_id = ? AND srs_due_at IS NOT NULL' . ($subjectId ? ' AND subject_id = ?' : '');
        $schedule = [];
        for ($d = 0; $d < 14; $d++) {
            $start = strtotime(Support::today($d) . ' 00:00:00');
            $end = $start + 86400;
            $lo = $d === 0 ? 0 : $start;
            $schedule[] = [
                'day' => Support::today($d),
                'count' => count(array_filter($cards, static fn ($c) => $c['due_at'] !== null && (int) $c['due_at'] >= $lo && (int) $c['due_at'] < $end))
                    + (int) DB::val("SELECT COUNT(*) FROM questions WHERE $qWhere AND srs_due_at >= ? AND srs_due_at < ?", array_merge($params, [$lo, $end])),
            ];
        }
        $mistakes = (int) DB::val("SELECT COUNT(*) FROM questions WHERE $qWhere AND srs_due_at <= ?", array_merge($params, [$now]));
        $reviewedToday = (int) DB::val('SELECT COUNT(*) FROM card_reviews WHERE user_id = ? AND created_at >= ?', [$uid, strtotime(Support::today() . ' 00:00:00')]);
        return [
            'due_cards' => $due,
            'new_cards' => $new,
            'due_questions' => $mistakes,
            'reviewed_today' => $reviewedToday,
            'total_cards' => count($cards),
            'average_retention' => $strength ? round(array_sum(array_column($strength, 'strength')) / count($strength), 3) : null,
            'strength' => $strength,
            'forgotten' => array_slice($forgotten, 0, 12),
            'schedule' => $schedule,
        ];
    }
}

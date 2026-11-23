<?php
declare(strict_types=1);

namespace App;

/** AI quizzes and practice exams: grounded question generation, grading and results. */
final class Quiz
{
    public const TYPES = ['mcq', 'tf', 'fill', 'short', 'exam'];
    private const TYPE_NAMES = [
        'mcq' => 'multiple choice',
        'tf' => 'true/false',
        'fill' => 'fill in the blank',
        'short' => 'short answer',
        'exam' => 'exam-style open question',
    ];
    private const DIFF = [
        1 => 'EASY: recall of one fact, term or definition.',
        2 => 'MEDIUM: understanding — explain, compare, or apply a concept to a familiar situation.',
        3 => 'HARD: analysis — combine several concepts, cause/effect chains, tricky distractors based on common misconceptions.',
    ];

    // ---------------------------------------------------------------- generation

    /** Picks topics, favouring weak, untested and important ones. */
    private static function pickTopics(int $subjectId, int $uid, array $topicIds, int $n): array
    {
        $rows = DB::all('SELECT t.*, COALESCE(tp.mastery,0) AS mastery, COALESCE(tp.correct,0)+COALESCE(tp.incorrect,0) AS answered
            FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = ?
            WHERE t.subject_id = ? AND t.active = 1', [$uid, $subjectId]);
        if ($topicIds) {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array((int) $r['id'], $topicIds, true))) ?: $rows;
        }
        if (!$rows) {
            throw HttpError::bad('Faget har ingen emner endnu. Tilføj kilder og kør analysen først.');
        }
        $picked = [];
        for ($i = 0; $i < $n; $i++) {
            $weights = [];
            foreach ($rows as $k => $r) {
                $w = (1.15 - (float) $r['mastery']) * (0.6 + 0.3 * (int) $r['importance']);
                if ((int) $r['answered'] === 0) {
                    $w += 0.4;
                }
                $weights[$k] = max(0.05, $w);
            }
            $sum = array_sum($weights);
            $x = mt_rand() / mt_getrandmax() * $sum;
            foreach ($weights as $k => $w) {
                $x -= $w;
                if ($x <= 0) {
                    $picked[] = $rows[$k];
                    break;
                }
            }
            if (count($picked) <= $i) {
                $picked[] = $rows[array_key_last($rows)];
            }
        }
        return $picked;
    }

    private static function difficultyFor(string $setting, float $mastery): int
    {
        return match ($setting) {
            'easy' => 1,
            'medium' => 2,
            'hard', 'exam' => 3,
            default => $mastery < 0.4 ? 1 : ($mastery < 0.75 ? 2 : 3), // adaptive
        };
    }

    /**
     * Generates and stores new questions. Returns ids.
     * @param array{types?:string[],difficulty?:string,topic_ids?:int[]} $opts
     */
    public static function generate(array $subject, int $uid, array $opts, int $count, bool $usePool = true): array
    {
        $types = array_values(array_intersect($opts['types'] ?? ['mcq', 'tf', 'fill', 'short'], self::TYPES)) ?: ['mcq'];
        $difficulty = $opts['difficulty'] ?? 'adaptive';
        // Instant: take ready-made, never-used questions from the background pool first.
        $ids = $usePool ? self::fromPool((int) $subject['id'], $types, $difficulty, $opts['topic_ids'] ?? [], $count) : [];
        $guard = 0;
        while (count($ids) < $count && $guard++ < (int) ceil($count / 8) + 2) {
            $batch = min(10, $count - count($ids));
            $topics = self::pickTopics((int) $subject['id'], $uid, $opts['topic_ids'] ?? [], min(3, $batch));
            $uniq = [];
            foreach ($topics as $t) {
                $uniq[(int) $t['id']] = $t;
            }
            $ids = array_merge($ids, self::generateBatch($subject, $uid, array_values($uniq), $types, $difficulty, $batch));
        }
        return array_slice($ids, 0, $count);
    }

    // ------------------------------------------------------------ question pool

    public const POOL_PER_TOPIC = 6;

    /** Never-used pre-generated questions matching the quiz settings (random order). */
    private static function fromPool(int $subjectId, array $types, string $difficulty, array $topicIds, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $rows = DB::all('SELECT id, type, difficulty, topic_id FROM questions WHERE subject_id = ? AND times_asked = 0 AND last_asked_at IS NULL', [$subjectId]);
        $want = ['easy' => 1, 'medium' => 2, 'hard' => 3, 'exam' => 3][$difficulty] ?? null;
        $rows = array_values(array_filter($rows, static fn ($r) => in_array($r['type'], $types, true)
            && (!$topicIds || in_array((int) $r['topic_id'], $topicIds, true))
            && ($want === null || abs((int) $r['difficulty'] - $want) <= ($want === 2 ? 1 : 0))));
        shuffle($rows);
        return array_map(static fn ($r) => (int) $r['id'], array_slice($rows, 0, $count));
    }

    /** Marks questions as handed out so the pool doesn't give them to another quiz. */
    public static function markUsed(array $ids): void
    {
        if ($ids) {
            DB::exec('UPDATE questions SET last_asked_at = ? WHERE last_asked_at IS NULL AND id IN (' . DB::in($ids) . ')', array_merge([time()], array_values($ids)));
        }
    }

    /** Topics whose pool of unused questions is below the target. */
    public static function topicsNeedingQuestions(int $subjectId): array
    {
        $out = [];
        foreach (DB::all('SELECT id FROM topics WHERE subject_id = ? AND active = 1 ORDER BY position', [$subjectId]) as $t) {
            $n = (int) DB::val('SELECT COUNT(*) FROM questions WHERE topic_id = ? AND times_asked = 0 AND last_asked_at IS NULL', [$t['id']]);
            if ($n < self::POOL_PER_TOPIC) {
                $out[] = (int) $t['id'];
            }
        }
        return $out;
    }

    /** Queues a background job that tops up the question pool (if needed and not already queued). */
    public static function ensurePool(int $subjectId, int $uid): void
    {
        if (DB::val("SELECT id FROM jobs WHERE subject_id = ? AND type = 'prefill' AND status IN ('pending','running')", [$subjectId])) {
            return;
        }
        $topics = self::topicsNeedingQuestions($subjectId);
        if (!$topics) {
            return;
        }
        DB::insert('jobs', [
            'user_id' => $uid, 'subject_id' => $subjectId, 'type' => 'prefill', 'status' => 'pending', 'stage' => 'prefill', 'progress' => 0,
            'message' => 'Forbereder quizspørgsmål', 'payload' => json_encode(['queue' => $topics, 'total' => count($topics)]),
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    /** Writes pool questions for one topic (run in a parallel background process). */
    public static function prefillTopic(array $subject, int $topicId): int
    {
        $have = (int) DB::val('SELECT COUNT(*) FROM questions WHERE topic_id = ? AND times_asked = 0 AND last_asked_at IS NULL', [$topicId]);
        $need = self::POOL_PER_TOPIC - $have;
        if ($need <= 0) {
            return 0;
        }
        $topic = DB::one('SELECT t.*, 0 AS mastery FROM topics t WHERE t.id = ?', [$topicId]);
        if (!$topic) {
            return 0;
        }
        return count(self::generateBatch($subject, (int) $subject['user_id'], [$topic], ['mcq', 'tf', 'fill', 'short'], 'medium', $need + 2));
    }

    private static function generateBatch(array $subject, int $uid, array $topics, array $types, string $difficulty, int $count): array
    {
        $chunks = [];
        $seen = [];
        foreach ($topics as $t) {
            // Shuffle a wider pool for variety, so repeated quizzes cover different passages.
            $pool = Analyzer::topicChunks($subject, $t, 14, 24000);
            shuffle($pool);
            foreach (array_slice($pool, 0, (int) ceil(10 / count($topics))) as $c) {
                if (!isset($seen[$c['id']])) {
                    $seen[$c['id']] = true;
                    $chunks[] = $c;
                }
            }
        }
        $context = Retriever::context($chunks, 14000);
        $topicLines = [];
        $diffLines = [];
        foreach ($topics as $t) {
            $d = self::difficultyFor($difficulty, (float) ($t['mastery'] ?? 0));
            $topicLines[] = "- topic_id {$t['id']}: {$t['name']}";
            $diffLines[] = "- topic_id {$t['id']}: " . self::DIFF[$d];
        }
        $avoid = DB::col('SELECT prompt FROM questions WHERE subject_id = ? AND topic_id IN (' . DB::in(array_column($topics, 'id')) . ') ORDER BY id DESC LIMIT 40',
            array_merge([$subject['id']], array_map(static fn ($t) => (int) $t['id'], $topics)));
        // Recent wrong answers steer questions toward the student's misconceptions.
        $mistakes = DB::all('SELECT q.prompt, a.user_answer FROM answers a JOIN questions q ON q.id = a.question_id
            WHERE a.user_id = ? AND a.is_correct = 0 AND q.topic_id IN (' . DB::in(array_column($topics, 'id')) . ') ORDER BY a.id DESC LIMIT 6',
            array_merge([$uid], array_map(static fn ($t) => (int) $t['id'], $topics)));
        $typeList = implode(', ', array_map(static fn ($t) => "$t (" . self::TYPE_NAMES[$t] . ')', $types));
        $schema = '{"questions": [{
  "type": "mcq|tf|fill|short|exam",
  "topic_id": 123,
  "difficulty": 1-3,
  "prompt": "the question (fill: a sentence with the missing part written as _____)",
  "options": ["4 options, only for mcq"],
  "answer": "mcq: exact text of the correct option | tf: true or false | fill: the missing word(s) | short/exam: a model answer",
  "accept": ["fill only: other acceptable answers/spellings"],
  "key_points": ["short/exam only: the points a full answer must contain"],
  "explanation": "why the answer is correct, in clear student-friendly language",
  "option_notes": {"<each wrong option text>": "why it is wrong"},
  "refs": ["C12"]
}]}';
        $user = "Write $count NEW quiz questions for the subject \"{$subject['name']}\" based ONLY on the excerpts.\n"
            . "Topics (use these topic_id values):\n" . implode("\n", $topicLines) . "\n"
            . "Difficulty per topic:\n" . implode("\n", $diffLines) . "\n"
            . "Allowed question types: $typeList. Use a varied mix of the allowed types.\n"
            . "Return JSON exactly in this shape:\n$schema\n"
            . "Rules: each question must be answerable from the cited excerpt(s) alone; mcq has exactly 4 options with one clearly correct answer and plausible distractors drawn from the material; for tf make about half of the statements false by changing one meaningful detail; never ask about page numbers, file names or the excerpts themselves; questions must be self-contained (no 'according to the text').\n"
            . ($avoid ? "Do NOT repeat or closely rephrase these existing questions:\n- " . implode("\n- ", array_map(static fn ($p) => Support::excerpt($p, 160), $avoid)) . "\n" : '')
            . ($mistakes ? "The student previously got these wrong — include questions that check the same ideas from a different angle:\n- " . implode("\n- ", array_map(static fn ($m) => Support::excerpt($m['prompt'], 140) . ' (student answered: ' . Support::excerpt((string) $m['user_answer'], 60) . ')', $mistakes)) . "\n" : '')
            . "\nEXCERPTS:\n" . $context;
        $r = AI::json(Analyzer::GROUNDING, $user, ['max_tokens' => 5000, 'temperature' => 0.8]);
        $topicIds = array_map(static fn ($t) => (int) $t['id'], $topics);
        $ids = [];
        foreach ((array) ($r['questions'] ?? []) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $id = self::store($subject, $uid, $q, $topicIds, $types);
            if ($id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private static function store(array $subject, int $uid, array $q, array $topicIds, array $types): ?int
    {
        $type = strtolower(trim((string) ($q['type'] ?? '')));
        $type = ['multiple_choice' => 'mcq', 'true_false' => 'tf', 'truefalse' => 'tf', 'fill_in_the_blank' => 'fill', 'open' => 'short'][$type] ?? $type;
        if (!in_array($type, $types, true)) {
            return null;
        }
        $prompt = trim((string) ($q['prompt'] ?? ''));
        $answer = is_scalar($q['answer'] ?? null) ? trim((string) $q['answer']) : '';
        if ($prompt === '' || $answer === '') {
            return null;
        }
        $refs = Retriever::refIds($q['refs'] ?? [], $uid);
        if (!$refs) {
            return null; // ungrounded questions are discarded
        }
        $options = null;
        $notes = [];
        $accept = [];
        if ($type === 'mcq') {
            $opts = array_values(array_unique(array_filter(array_map(static fn ($o) => is_scalar($o) ? trim((string) $o) : '', (array) ($q['options'] ?? [])))));
            if (count($opts) < 3 || count($opts) > 6) {
                return null;
            }
            // Accept "B" / index answers by mapping them to option text.
            if (preg_match('/^[A-F]$/i', $answer)) {
                $answer = $opts[ord(strtoupper($answer)) - 65] ?? $answer;
            } elseif (ctype_digit($answer) && isset($opts[(int) $answer])) {
                $answer = $opts[(int) $answer];
            }
            $match = null;
            foreach ($opts as $o) {
                if (Support::norm($o) === Support::norm($answer) || Support::norm(preg_replace('/^[A-F][).:]\s*/', '', $o) ?? $o) === Support::norm($answer)) {
                    $match = $o;
                }
            }
            if ($match === null) {
                return null;
            }
            $answer = $match;
            shuffle($opts); // avoid position bias
            $options = $opts;
            foreach ((array) ($q['option_notes'] ?? []) as $k => $v) {
                if (is_scalar($v)) {
                    $notes[(string) $k] = (string) $v;
                }
            }
        } elseif ($type === 'tf') {
            $a = Support::norm($answer);
            $answer = in_array($a, ['true', 'waar', 'juist', 'yes', 'correct', 'vrai', 'wahr'], true) ? 'true' : (in_array($a, ['false', 'onwaar', 'onjuist', 'no', 'incorrect', 'faux', 'falsch'], true) ? 'false' : '');
            if ($answer === '') {
                return null;
            }
        } elseif ($type === 'fill') {
            if (!preg_match('/_{3,}/', $prompt)) {
                if (mb_stripos($prompt, $answer) === false) {
                    return null;
                }
                $prompt = (string) preg_replace('/' . preg_quote($answer, '/') . '/iu', '_____', $prompt, 1);
            }
            $accept = array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', (array) ($q['accept'] ?? []))));
        } else {
            $accept = array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', (array) ($q['key_points'] ?? []))));
        }
        $hash = md5(Support::norm($prompt));
        if (DB::val('SELECT id FROM questions WHERE subject_id = ? AND hash = ?', [$subject['id'], $hash])) {
            return null;
        }
        $topicId = (int) ($q['topic_id'] ?? 0);
        if (!in_array($topicId, $topicIds, true)) {
            $topicId = $topicIds[0] ?? null;
        }
        return DB::insert('questions', [
            'user_id' => $uid,
            'subject_id' => $subject['id'],
            'topic_id' => $topicId,
            'type' => $type,
            'difficulty' => max(1, min(3, (int) ($q['difficulty'] ?? 2))),
            'prompt' => mb_substr($prompt, 0, 2000),
            'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'answer' => mb_substr($answer, 0, 3000),
            'accept' => json_encode($accept, JSON_UNESCAPED_UNICODE),
            'explanation' => mb_substr(Retriever::normalizeCitations(trim((string) ($q['explanation'] ?? ''))), 0, 3000),
            'option_notes' => $notes ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null,
            'refs' => json_encode(array_map(static fn ($i) => 'C' . $i, $refs)),
            'hash' => $hash,
            'created_at' => time(),
        ]);
    }

    // ------------------------------------------------------------------ grading

    private static function fuzzyEqual(string $a, string $b): bool
    {
        $a = Support::norm($a);
        $b = Support::norm($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $len = max(mb_strlen($a), mb_strlen($b));
        $tol = $len >= 10 ? 2 : ($len >= 5 ? 1 : 0);
        return $tol > 0 && strlen($a) < 255 && strlen($b) < 255 && levenshtein($a, $b) <= $tol;
    }

    /** Local grading for objective types; null for types needing AI. */
    private static function gradeLocal(array $q, string $answer): ?array
    {
        switch ($q['type']) {
            case 'mcq':
                $ok = Support::norm($answer) === Support::norm($q['answer']);
                return ['is_correct' => $ok, 'score' => $ok ? 1.0 : 0.0, 'feedback' => null];
            case 'tf':
                $ok = Support::norm($answer) === $q['answer'];
                return ['is_correct' => $ok, 'score' => $ok ? 1.0 : 0.0, 'feedback' => null];
            case 'fill':
                $ok = self::fuzzyEqual($answer, $q['answer']);
                foreach (Support::decode($q['accept']) as $alt) {
                    $ok = $ok || self::fuzzyEqual($answer, (string) $alt);
                }
                return ['is_correct' => $ok, 'score' => $ok ? 1.0 : 0.0, 'feedback' => null];
        }
        return null;
    }

    /**
     * AI grading of open answers in one call.
     * @param array<int, array{q:array, answer:string}> $items keyed by question id
     * @return array<int, array{is_correct:bool, score:float, feedback:string}>
     */
    public static function gradeOpen(array $items, int $uid): array
    {
        $out = [];
        foreach (array_chunk($items, 12, true) as $batch) {
            $list = [];
            $ctxIds = [];
            foreach ($batch as $qid => $it) {
                if (trim($it['answer']) === '') {
                    $out[$qid] = ['is_correct' => false, 'score' => 0.0, 'feedback' => 'Intet svar.'];
                    continue;
                }
                $list[] = [
                    'id' => $qid,
                    'question' => $it['q']['prompt'],
                    'model_answer' => $it['q']['answer'],
                    'key_points' => Support::decode($it['q']['accept']),
                    'student_answer' => mb_substr($it['answer'], 0, 4000),
                ];
                foreach (Support::decode($it['q']['refs']) as $r) {
                    $ctxIds[] = (int) substr((string) $r, 1);
                }
            }
            if (!$list) {
                continue;
            }
            $context = Retriever::context(Retriever::hydrate(array_slice(array_unique($ctxIds), 0, 16)), 14000);
            $r = AI::json(
                "You are a fair, encouraging teacher grading a student's answers. Grade each answer against its model answer and key points, which come from the student's own course material (excerpts provided). Accept answers that are correct in meaning even if worded differently. Ignore spelling mistakes unless they change the meaning. Never reward information that contradicts the material. Accept correct answers written in a different language than the material (e.g. Danish answers to English material).",
                "Grade these answers. Return JSON {\"results\": [{\"id\": <id>, \"score\": 0.0-1.0 (share of key points correctly covered), \"verdict\": \"correct|partial|incorrect\", \"feedback\": \"2-3 sentences: what was right, what is missing or wrong, and the correct idea\"}]}\n\nANSWERS:\n"
                    . json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\nCOURSE EXCERPTS:\n" . $context,
                ['max_tokens' => 4000, 'temperature' => 0.1]
            );
            foreach ((array) ($r['results'] ?? []) as $res) {
                $qid = (int) ($res['id'] ?? 0);
                if (!isset($batch[$qid])) {
                    continue;
                }
                $score = max(0, min(1, (float) ($res['score'] ?? 0)));
                $out[$qid] = ['is_correct' => $score >= 0.7, 'score' => $score, 'feedback' => trim((string) ($res['feedback'] ?? ''))];
            }
            foreach ($batch as $qid => $_) {
                $out[$qid] ??= ['is_correct' => false, 'score' => 0.0, 'feedback' => 'Svaret kunne ikke rettes automatisk. Sammenlign det med modelsvaret nedenfor.'];
            }
        }
        return $out;
    }

    /** Persists an answer and updates stats, topic mastery and the mistake-review schedule. */
    private static function record(int $uid, array $q, ?int $attemptId, string $answer, array $g, float $masteryWeight = 0.3): void
    {
        DB::insert('answers', [
            'user_id' => $uid, 'subject_id' => $q['subject_id'], 'attempt_id' => $attemptId, 'question_id' => $q['id'],
            'topic_id' => $q['topic_id'], 'user_answer' => mb_substr($answer, 0, 5000), 'is_correct' => $g['is_correct'] ? 1 : 0,
            'score' => $g['score'], 'feedback' => $g['feedback'], 'created_at' => time(),
        ]);
        $data = ['times_asked' => (int) $q['times_asked'] + 1, 'times_correct' => (int) $q['times_correct'] + ($g['is_correct'] ? 1 : 0), 'last_asked_at' => time()];
        if (!$g['is_correct']) {
            $data['srs_interval'] = 1;
            $data['srs_due_at'] = time() + 86400;
        } elseif ($q['srs_interval'] !== null) {
            $iv = (float) $q['srs_interval'] * 2.5;
            $data['srs_interval'] = $iv > 30 ? null : $iv;
            $data['srs_due_at'] = $iv > 30 ? null : time() + (int) ($iv * 86400);
        }
        DB::update('questions', $data, 'id = ?', [$q['id']]);
        Progress::recordTopic($uid, $q['topic_id'] ? (int) $q['topic_id'] : null, (float) $g['score'], $masteryWeight);
        DB::update('subjects', ['last_studied_at' => time()], 'id = ?', [$q['subject_id']]);
    }

    public static function presentQuestion(array $q, bool $withAnswer = false): array
    {
        $out = [
            'id' => (int) $q['id'],
            'type' => $q['type'],
            'topic_id' => $q['topic_id'] ? (int) $q['topic_id'] : null,
            'topic' => $q['topic_name'] ?? null,
            'difficulty' => (int) $q['difficulty'],
            'prompt' => $q['prompt'],
            'options' => $q['type'] === 'tf' ? ['true', 'false'] : Support::decode($q['options'], null),
            'refs' => Support::decode($q['refs']),
        ];
        if ($withAnswer) {
            $out['answer'] = $q['answer'];
            $out['explanation'] = $q['explanation'];
            $out['option_notes'] = Support::decode($q['option_notes'], new \stdClass());
            $out['key_points'] = in_array($q['type'], ['short', 'exam'], true) ? Support::decode($q['accept']) : [];
        }
        return $out;
    }

    private static function questions(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = DB::all('SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON t.id = q.topic_id WHERE q.id IN (' . DB::in($ids) . ')', $ids);
        $byId = array_column($rows, null, 'id');
        return array_values(array_filter(array_map(static fn ($id) => $byId[$id] ?? null, $ids)));
    }

    private static function refsForQuestions(array $qs, int $uid): array
    {
        $ids = [];
        foreach ($qs as $q) {
            foreach (Support::decode($q['refs']) as $r) {
                $ids[] = (int) substr((string) $r, 1);
            }
            $ids = array_merge($ids, Retriever::citedIds((string) $q['explanation']));
        }
        return Retriever::refMeta($ids, $uid);
    }

    private static function ownedAttempt(int $id, int $uid): array
    {
        $a = DB::one('SELECT * FROM attempts WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$a) {
            throw HttpError::notFound('Quiz');
        }
        return $a;
    }

    private static function readOptions(): array
    {
        $types = array_values(array_intersect(array_map('strval', Http::arr('types')), self::TYPES));
        $topicIds = array_map('intval', Http::arr('topic_ids'));
        if ($t = Http::int('topic_id')) {
            $topicIds[] = $t;
        }
        $difficulty = Http::str('difficulty', 'adaptive');
        if (!in_array($difficulty, ['easy', 'medium', 'hard', 'adaptive', 'exam'], true)) {
            $difficulty = 'adaptive';
        }
        return ['types' => $types ?: ['mcq', 'tf', 'fill', 'short'], 'topic_ids' => array_values(array_unique($topicIds)), 'difficulty' => $difficulty];
    }

    // ------------------------------------------------------------------- quiz API

    public static function start(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        Guide::requireRead((int) $s['id']);
        Auth::release();
        @set_time_limit(300);
        $count = max(3, min(30, Http::int('count', 10)));
        $opts = self::readOptions();
        $ids = [];
        // Mix in due "mistake review" questions (spaced repetition of past errors).
        if ((Http::input()['include_review'] ?? true) !== false) {
            $params = [$s['id'], time()];
            $sql = 'SELECT id FROM questions WHERE subject_id = ? AND srs_due_at IS NOT NULL AND srs_due_at <= ? AND type IN (' . DB::in($opts['types']) . ')';
            $params = array_merge($params, $opts['types']);
            if ($opts['topic_ids']) {
                $sql .= ' AND topic_id IN (' . DB::in($opts['topic_ids']) . ')';
                $params = array_merge($params, $opts['topic_ids']);
            }
            $ids = array_map('intval', DB::col($sql . ' ORDER BY srs_due_at LIMIT ' . (int) floor($count * 0.3), $params));
        }
        $ids = array_merge($ids, self::generate($s, $uid, $opts, $count - count($ids)));
        if (!$ids) {
            throw new HttpError(502, 'AI’en kunne ikke lave spørgsmål ud fra materialet. Prøv igen, eller vælg andre spørgsmålstyper.');
        }
        shuffle($ids);
        self::markUsed($ids);
        $attemptId = DB::insert('attempts', [
            'user_id' => $uid, 'subject_id' => $s['id'], 'mode' => 'quiz', 'settings' => json_encode($opts + ['count' => $count]),
            'question_ids' => json_encode($ids), 'status' => 'active', 'total' => count($ids), 'started_at' => time(),
        ]);
        self::ensurePool((int) $s['id'], $uid); // top the pool back up in the background
        $qs = self::questions($ids);
        return [
            'attempt' => ['id' => $attemptId, 'total' => count($ids), 'review_count' => 0],
            'questions' => array_map(static fn ($q) => self::presentQuestion($q), $qs),
            'refs' => self::refsForQuestions($qs, $uid),
        ];
    }

    /** Instant feedback for one answer (quiz, memory review). */
    public static function answer(): array
    {
        $uid = Auth::id();
        $attemptId = Http::int('attempt_id') ?: null;
        $attempt = $attemptId ? self::ownedAttempt($attemptId, $uid) : null;
        $q = DB::one('SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON t.id = q.topic_id WHERE q.id = ? AND q.user_id = ?', [Http::int('question_id'), $uid]);
        if (!$q) {
            throw HttpError::notFound('Question');
        }
        if ($attempt && !in_array((int) $q['id'], Support::decode($attempt['question_ids']), true)) {
            throw HttpError::bad('Spørgsmålet hører ikke til denne quiz.');
        }
        if ($attempt && DB::val('SELECT id FROM answers WHERE attempt_id = ? AND question_id = ?', [$attempt['id'], $q['id']])) {
            throw HttpError::bad('Du har allerede svaret på dette spørgsmål.');
        }
        Lang::forSubjectId((int) $q['subject_id']);
        Auth::release();
        $answer = Http::str('answer', '', 5000);
        Security::inspect([$answer], 'AI-quiz');
        $g = self::gradeLocal($q, $answer) ?? (self::gradeOpen([(int) $q['id'] => ['q' => $q, 'answer' => $answer]], $uid)[(int) $q['id']]);
        self::record($uid, $q, $attempt ? (int) $attempt['id'] : null, $answer, $g);
        if ($attempt) {
            DB::exec('UPDATE attempts SET correct = correct + ? WHERE id = ?', [$g['is_correct'] ? 1 : 0, $attempt['id']]);
        }
        return [
            'result' => $g,
            'question' => self::presentQuestion($q, true),
            'refs' => self::refsForQuestions([$q], $uid),
        ];
    }

    public static function finish(): array
    {
        $uid = Auth::id();
        $a = self::ownedAttempt(Http::int('attempt_id'), $uid);
        if ($a['status'] !== 'finished') {
            $rows = DB::all('SELECT score, is_correct FROM answers WHERE attempt_id = ?', [$a['id']]);
            $total = (int) $a['total'];
            $sum = array_sum(array_map(static fn ($r) => (float) $r['score'], $rows));
            DB::update('attempts', [
                'status' => 'finished', 'finished_at' => time(),
                'correct' => count(array_filter($rows, static fn ($r) => (int) $r['is_correct'] === 1)),
                'score' => $total ? round($sum / $total, 4) : 0,
            ], 'id = ?', [$a['id']]);
        }
        return self::results((int) $a['id'], $uid);
    }

    /** Full results of a finished quiz/exam: answers, explanations, per-topic breakdown. */
    public static function results(int $attemptId, int $uid): array
    {
        $a = self::ownedAttempt($attemptId, $uid);
        $ids = array_map('intval', Support::decode($a['question_ids']));
        $qs = self::questions($ids);
        $answers = array_column(DB::all('SELECT * FROM answers WHERE attempt_id = ?', [$a['id']]), null, 'question_id');
        $items = [];
        $byTopic = [];
        foreach ($qs as $q) {
            $ans = $answers[$q['id']] ?? null;
            $items[] = [
                'question' => self::presentQuestion($q, true),
                'user_answer' => $ans['user_answer'] ?? null,
                'is_correct' => $ans ? (bool) $ans['is_correct'] : false,
                'score' => $ans ? (float) $ans['score'] : 0.0,
                'feedback' => $ans['feedback'] ?? null,
                'answered' => (bool) $ans,
            ];
            $tid = (int) ($q['topic_id'] ?? 0);
            $byTopic[$tid] ??= ['topic_id' => $tid ?: null, 'name' => $q['topic_name'] ?? 'Generelt', 'total' => 0, 'score' => 0.0];
            $byTopic[$tid]['total']++;
            $byTopic[$tid]['score'] += $ans ? (float) $ans['score'] : 0;
        }
        $byTopic = array_values(array_map(static fn ($t) => $t + ['percent' => (int) round(100 * $t['score'] / max(1, $t['total']))], $byTopic));
        usort($byTopic, static fn ($x, $y) => $x['percent'] <=> $y['percent']);
        $weak = array_values(array_filter($byTopic, static fn ($t) => $t['percent'] < 70));
        return [
            'attempt' => [
                'id' => (int) $a['id'], 'mode' => $a['mode'], 'status' => $a['status'], 'subject_id' => (int) $a['subject_id'],
                'score' => $a['score'] !== null ? (float) $a['score'] : null, 'correct' => (int) $a['correct'], 'total' => (int) $a['total'],
                'started_at' => (int) $a['started_at'], 'finished_at' => $a['finished_at'] ? (int) $a['finished_at'] : null,
                'time_limit_sec' => $a['time_limit_sec'] ? (int) $a['time_limit_sec'] : null,
                'settings' => Support::decode($a['settings']),
                'meta' => Support::decode($a['result']),
            ],
            'items' => $items,
            'by_topic' => $byTopic,
            'weak_topics' => $weak,
            'refs' => self::refsForQuestions($qs, $uid),
        ];
    }

    public static function resultsApi(): array
    {
        return self::results(Http::int('attempt_id'), Auth::id());
    }

    public static function history(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $mode = Http::str('mode', '');
        $sql = "SELECT id, mode, status, score, correct, total, started_at, finished_at FROM attempts WHERE subject_id = ? AND status = 'finished'";
        $params = [$s['id']];
        if (in_array($mode, ['quiz', 'exam'], true)) {
            $sql .= ' AND mode = ?';
            $params[] = $mode;
        }
        return ['attempts' => DB::all($sql . ' ORDER BY finished_at DESC LIMIT 50', $params)];
    }

    /** Due mistake-review questions for Memory Training. */
    public static function reviewQuestions(): array
    {
        $uid = Auth::id();
        $sid = Http::int('subject_id');
        $sql = 'SELECT q.*, t.name AS topic_name FROM questions q LEFT JOIN topics t ON t.id = q.topic_id WHERE q.user_id = ? AND q.srs_due_at IS NOT NULL AND q.srs_due_at <= ?';
        $params = [$uid, time()];
        if ($sid) {
            $sql .= ' AND q.subject_id = ?';
            $params[] = $sid;
        }
        $qs = DB::all($sql . ' ORDER BY q.srs_due_at LIMIT ' . max(1, min(50, Http::int('limit', 15))), $params);
        return ['questions' => array_map(static fn ($q) => self::presentQuestion($q), $qs), 'refs' => self::refsForQuestions($qs, $uid)];
    }

    // ------------------------------------------------------------------- exams

    public static function examCreate(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        if (!DB::val('SELECT COUNT(*) FROM topics WHERE subject_id = ? AND active = 1', [$s['id']])) {
            throw HttpError::bad('Faget har ingen emner endnu. Tilføj kilder og kør analysen først.');
        }
        Guide::requireRead((int) $s['id']);
        $count = max(5, min(60, Http::int('count', 20)));
        $opts = self::readOptions();
        if (!Http::arr('types')) {
            $opts['types'] = ['mcq', 'tf', 'fill', 'short', 'exam'];
        }
        $limitMin = max(0, min(300, Http::int('time_limit_min', 0)));
        $attemptId = DB::insert('attempts', [
            'user_id' => $uid, 'subject_id' => $s['id'], 'mode' => 'exam', 'settings' => json_encode($opts + ['count' => $count]),
            'question_ids' => '[]', 'status' => 'generating', 'total' => $count, 'time_limit_sec' => $limitMin ? $limitMin * 60 : null,
            'started_at' => time(),
        ]);
        $jobId = DB::insert('jobs', [
            'user_id' => $uid, 'subject_id' => $s['id'], 'type' => 'exam', 'status' => 'pending', 'stage' => 'generate', 'progress' => 0,
            'message' => 'Skriver din eksamen', 'payload' => json_encode(['attempt_id' => $attemptId, 'count' => $count, 'opts' => $opts]),
            'created_at' => time(), 'updated_at' => time(),
        ]);
        return ['attempt_id' => $attemptId, 'job_id' => $jobId];
    }

    /** One background step: writes up to 10 exam questions. */
    public static function examStep(array $job, array $payload): void
    {
        $attempt = DB::one('SELECT * FROM attempts WHERE id = ?', [$payload['attempt_id'] ?? 0]);
        if (!$attempt) {
            DB::update('jobs', ['status' => 'failed', 'error' => 'Eksamen blev slettet.', 'updated_at' => time()], 'id = ?', [$job['id']]);
            return;
        }
        $subject = DB::one('SELECT * FROM subjects WHERE id = ?', [$attempt['subject_id']]);
        $ids = array_map('intval', Support::decode($attempt['question_ids']));
        $count = (int) $payload['count'];
        if (count($ids) < $count) {
            $new = self::generate($subject, (int) $attempt['user_id'], (array) $payload['opts'], min(10, $count - count($ids)));
            $ids = array_merge($ids, $new);
            $tries = (int) ($payload['empty_tries'] ?? 0) + ($new ? 0 : 1);
            $payload['empty_tries'] = $tries;
            DB::update('attempts', ['question_ids' => json_encode($ids)], 'id = ?', [$attempt['id']]);
            DB::update('jobs', [
                'progress' => (int) (100 * count($ids) / max(1, $count)),
                'message' => 'Skriver spørgsmål (' . count($ids) . ' af ' . $count . ')',
                'payload' => json_encode($payload), 'updated_at' => time(),
            ], 'id = ?', [$job['id']]);
            if (count($ids) < $count && $tries < 3) {
                return;
            }
        }
        if (!$ids) {
            DB::update('jobs', ['status' => 'failed', 'error' => 'Der kunne ikke laves nogen spørgsmål.', 'updated_at' => time()], 'id = ?', [$job['id']]);
            DB::update('attempts', ['status' => 'abandoned'], 'id = ?', [$attempt['id']]);
            return;
        }
        // Order like a real exam: objective questions first, open questions last.
        $order = ['tf' => 0, 'mcq' => 1, 'fill' => 2, 'short' => 3, 'exam' => 4];
        $qs = self::questions($ids);
        usort($qs, static fn ($a, $b) => ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9));
        $ids = array_map(static fn ($q) => (int) $q['id'], $qs);
        DB::update('attempts', ['question_ids' => json_encode($ids), 'total' => count($ids), 'status' => 'ready'], 'id = ?', [$attempt['id']]);
        self::markUsed($ids);
        self::ensurePool((int) $attempt['subject_id'], (int) $attempt['user_id']);
        DB::update('jobs', ['status' => 'done', 'progress' => 100, 'message' => 'Eksamen er klar', 'updated_at' => time()], 'id = ?', [$job['id']]);
    }

    public static function examGet(): array
    {
        $uid = Auth::id();
        $a = self::ownedAttempt(Http::int('attempt_id'), $uid);
        if ($a['status'] === 'finished') {
            return ['state' => 'finished'] + self::results((int) $a['id'], $uid);
        }
        if ($a['status'] === 'generating') {
            $job = DB::one("SELECT * FROM jobs WHERE type = 'exam' AND user_id = ? AND payload LIKE ? ORDER BY id DESC LIMIT 1", [$uid, '%"attempt_id":' . (int) $a['id'] . ',%']);
            return ['state' => 'generating', 'job' => Pipeline::present($job)];
        }
        if ($a['status'] === 'abandoned') {
            throw HttpError::bad('Eksamen kunne ikke laves. Start en ny.');
        }
        $meta = Support::decode($a['result']);
        $qs = self::questions(array_map('intval', Support::decode($a['question_ids'])));
        return [
            'state' => $a['status'], // ready | active
            'attempt' => [
                'id' => (int) $a['id'], 'total' => count($qs), 'time_limit_sec' => $a['time_limit_sec'] ? (int) $a['time_limit_sec'] : null,
                'started_at' => (int) $a['started_at'], 'now' => time(), 'settings' => Support::decode($a['settings']),
            ],
            'questions' => array_map(static fn ($q) => self::presentQuestion($q), $qs),
            'draft' => $meta['draft'] ?? new \stdClass(),
            'flags' => $meta['flags'] ?? [],
        ];
    }

    public static function examBegin(): array
    {
        $uid = Auth::id();
        $a = self::ownedAttempt(Http::int('attempt_id'), $uid);
        if ($a['status'] === 'ready') {
            DB::update('attempts', ['status' => 'active', 'started_at' => time()], 'id = ?', [$a['id']]);
        }
        return self::examGet();
    }

    public static function examSave(): array
    {
        $uid = Auth::id();
        $a = self::ownedAttempt(Http::int('attempt_id'), $uid);
        if ($a['status'] !== 'active') {
            return ['ok' => false];
        }
        $meta = Support::decode($a['result']);
        $meta['draft'] = array_map(static fn ($v) => mb_substr((string) $v, 0, 5000), array_filter(Http::arr('answers'), 'is_scalar'));
        $meta['flags'] = array_map('intval', Http::arr('flags'));
        DB::update('attempts', ['result' => json_encode($meta, JSON_UNESCAPED_UNICODE)], 'id = ?', [$a['id']]);
        return ['ok' => true];
    }

    public static function examSubmit(): array
    {
        $uid = Auth::id();
        $a = self::ownedAttempt(Http::int('attempt_id'), $uid);
        if ($a['status'] === 'finished') {
            return self::results((int) $a['id'], $uid);
        }
        if ($a['status'] !== 'active') {
            throw HttpError::bad('Eksamen er ikke startet.');
        }
        Lang::forSubjectId((int) $a['subject_id']);
        Auth::release();
        @set_time_limit(300);
        $answers = Http::arr('answers');
        $qs = self::questions(array_map('intval', Support::decode($a['question_ids'])));
        $grades = [];
        $open = [];
        foreach ($qs as $q) {
            $ans = is_scalar($answers[$q['id']] ?? null) ? trim((string) $answers[$q['id']]) : '';
            $local = self::gradeLocal($q, $ans);
            if ($local !== null) {
                $grades[(int) $q['id']] = $local;
            } else {
                $open[(int) $q['id']] = ['q' => $q, 'answer' => $ans];
            }
        }
        $grades += self::gradeOpen($open, $uid);
        $correct = 0;
        $sum = 0.0;
        foreach ($qs as $q) {
            $ans = is_scalar($answers[$q['id']] ?? null) ? trim((string) $answers[$q['id']]) : '';
            $g = $grades[(int) $q['id']];
            self::record($uid, $q, (int) $a['id'], $ans, $g, 0.35);
            $correct += $g['is_correct'] ? 1 : 0;
            $sum += $g['score'];
        }
        $used = time() - (int) $a['started_at'];
        $meta = Support::decode($a['result']);
        unset($meta['draft']);
        $meta['time_used_sec'] = $used;
        $meta['overtime'] = $a['time_limit_sec'] && $used > (int) $a['time_limit_sec'] + 30;
        DB::update('attempts', [
            'status' => 'finished', 'finished_at' => time(), 'correct' => $correct, 'total' => count($qs),
            'score' => count($qs) ? round($sum / count($qs), 4) : 0, 'result' => json_encode($meta),
        ], 'id = ?', [$a['id']]);
        return self::results((int) $a['id'], $uid);
    }
}

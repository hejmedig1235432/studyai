<?php
declare(strict_types=1);

namespace App;

/**
 * Learn mode: explain a topic → ask a question → evaluate → explain mistakes / give
 * another example → move on once the student answers correctly twice in a row.
 */
final class Learn
{
    private const NEEDED_STREAK = 2;

    private static function owned(int $id, int $uid): array
    {
        $s = DB::one('SELECT * FROM learn_sessions WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$s) {
            throw HttpError::notFound('Learn session');
        }
        return $s;
    }

    /** Client view of the state (hides answers). */
    private static function view(array $session, int $uid): array
    {
        $state = Support::decode($session['state']);
        $events = $state['events'] ?? [];
        foreach ($events as &$e) {
            if (($e['type'] ?? '') === 'question') {
                unset($e['answer'], $e['key_points']);
            }
        }
        unset($e);
        $ids = [];
        foreach ($events as $e) {
            $ids = array_merge($ids, Retriever::citedIds(json_encode($e, JSON_UNESCAPED_UNICODE) ?: ''));
        }
        $topics = [];
        foreach ((array) ($state['plan'] ?? []) as $tid) {
            $t = DB::one('SELECT id, name FROM topics WHERE id = ?', [$tid]);
            if ($t) {
                $topics[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'done' => in_array((int) $tid, (array) ($state['done'] ?? []), true), 'current' => (int) $tid === (int) ($state['current'] ?? 0)];
            }
        }
        return [
            'session' => ['id' => (int) $session['id'], 'status' => $session['status'], 'subject_id' => (int) $session['subject_id']],
            'topics' => $topics,
            'events' => $events,
            'awaiting' => $state['awaiting'] ?? null,
            'streak' => (int) ($state['streak'] ?? 0),
            'needed' => self::NEEDED_STREAK,
            'refs' => Retriever::refMeta($ids, $uid),
        ];
    }

    public static function start(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        Auth::release();
        @set_time_limit(240);
        $topicId = Http::int('topic_id');
        $rows = DB::all('SELECT t.id, COALESCE(tp.mastery,0) AS mastery, COALESCE(tp.understood,0) AS understood, t.position
            FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = ? WHERE t.subject_id = ? AND t.active = 1 ORDER BY t.position', [$uid, $s['id']]);
        if (!$rows) {
            throw HttpError::bad('Faget har ingen emner endnu. Tilføj kilder og kør analysen først.');
        }
        if ($topicId) {
            $plan = [$topicId];
            if (!in_array($topicId, array_map('intval', array_column($rows, 'id')), true)) {
                throw HttpError::notFound('Topic');
            }
        } else {
            // Unlearned topics in course order first, then weakest learned ones.
            $todo = array_values(array_filter($rows, static fn ($r) => !(int) $r['understood']));
            $learned = array_values(array_filter($rows, static fn ($r) => (int) $r['understood']));
            usort($learned, static fn ($a, $b) => (float) $a['mastery'] <=> (float) $b['mastery']);
            $plan = array_map(static fn ($r) => (int) $r['id'], array_slice(array_merge($todo, $learned), 0, max(1, min(8, Http::int('topics', 4)))));
        }
        $state = ['plan' => $plan, 'done' => [], 'current' => $plan[0], 'events' => [], 'streak' => 0, 'awaiting' => null];
        $id = DB::insert('learn_sessions', ['user_id' => $uid, 'subject_id' => $s['id'], 'state' => json_encode($state), 'status' => 'active', 'created_at' => time(), 'updated_at' => time()]);
        $state = self::teach($s, $uid, $state, $plan[0]);
        DB::update('learn_sessions', ['state' => json_encode($state, JSON_UNESCAPED_UNICODE), 'updated_at' => time()], 'id = ?', [$id]);
        return self::view(self::owned($id, $uid), $uid);
    }

    public static function get(): array
    {
        $uid = Auth::id();
        return self::view(self::owned(Http::int('session_id'), $uid), $uid);
    }

    public static function latest(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $row = DB::one("SELECT * FROM learn_sessions WHERE subject_id = ? AND user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$s['id'], $uid]);
        return ['session' => $row ? self::view($row, $uid) : null];
    }

    private static function questionEvent(array $q, int $topicId): array
    {
        $type = ($q['type'] ?? 'open') === 'mcq' && count((array) ($q['options'] ?? [])) >= 3 ? 'mcq' : 'open';
        return [
            'type' => 'question',
            'topic_id' => $topicId,
            'kind' => $type,
            'prompt' => (string) ($q['prompt'] ?? ''),
            'options' => $type === 'mcq' ? array_values(array_map('strval', (array) $q['options'])) : null,
            'answer' => (string) ($q['answer'] ?? ''),
            'key_points' => array_values(array_map('strval', (array) ($q['key_points'] ?? []))),
        ];
    }

    /** Explains a topic and asks the first check question. */
    private static function teach(array $subject, int $uid, array $state, int $topicId): array
    {
        $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$topicId]);
        $context = Retriever::context(Analyzer::topicChunks($subject, $topic, 10, 16000), 16000);
        $r = AI::json(Tutor::identity() . "\n\n" . Analyzer::GROUNDING . "\nYou are teaching one topic interactively, like a private tutor.",
            "Teach the topic \"{$topic['name']}\" to the student using only the excerpts. Return JSON:\n"
            . '{"explanation": "Markdown, 150-300 words: start with the core idea in one sentence, then explain step by step, bold key terms, include one concrete example from the excerpts, cite like [C12]", '
            . '"question": {"type": "open or mcq", "prompt": "one question that checks understanding (not just recall)", "options": ["4 options if mcq"], "answer": "correct option text or model answer", "key_points": ["for open questions"]}}'
            . "\n\nEXCERPTS:\n" . $context,
            ['max_tokens' => 3000, 'temperature' => 0.5]
        );
        $state['current'] = $topicId;
        $state['streak'] = 0;
        $state['events'][] = ['type' => 'topic', 'topic_id' => $topicId, 'name' => $topic['name']];
        $state['events'][] = ['type' => 'explain', 'topic_id' => $topicId, 'content' => Retriever::scrubCitations((string) ($r['explanation'] ?? ''), $uid)];
        $state['events'][] = self::questionEvent((array) ($r['question'] ?? []), $topicId);
        $state['awaiting'] = 'answer';
        return $state;
    }

    public static function answer(): array
    {
        $uid = Auth::id();
        $session = self::owned(Http::int('session_id'), $uid);
        if ($session['status'] !== 'active') {
            throw HttpError::bad('Læringsrunden er afsluttet.');
        }
        Auth::release();
        @set_time_limit(240);
        $subject = Subjects::owned((int) $session['subject_id'], $uid);
        $state = Support::decode($session['state']);
        $answer = Http::str('answer', '', 4000);
        if ($answer === '') {
            throw HttpError::bad('Skriv et svar først (eller tryk “Ved ikke”).');
        }
        Security::inspect([$answer], 'Lær trin for trin');
        $question = null;
        for ($i = count($state['events']) - 1; $i >= 0; $i--) {
            if ($state['events'][$i]['type'] === 'question') {
                $question = $state['events'][$i];
                break;
            }
        }
        if (!$question) {
            throw HttpError::bad('Der er intet åbent spørgsmål.');
        }
        $topicId = (int) $state['current'];
        $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$topicId]);
        $context = Retriever::context(Analyzer::topicChunks($subject, $topic, 8, 12000), 12000);
        $dontKnow = (bool) (Http::input()['dont_know'] ?? false);
        $mcqCorrect = $question['kind'] === 'mcq' ? Support::norm($answer) === Support::norm($question['answer']) : null;

        $r = AI::json(Tutor::identity() . "\n\n" . Analyzer::GROUNDING . "\nYou are an encouraging private tutor evaluating a student's answer in an interactive lesson.",
            "Topic: {$topic['name']}\nQuestion: {$question['prompt']}\n"
            . ($question['kind'] === 'mcq' ? 'Options: ' . implode(' | ', (array) $question['options']) . "\n" : '')
            . "Correct answer: {$question['answer']}\n" . ($question['key_points'] ? 'Key points: ' . implode('; ', $question['key_points']) . "\n" : '')
            . 'Student answer: ' . ($dontKnow ? "(the student said they don't know)" : $answer) . "\n"
            . ($mcqCorrect !== null ? 'The student\'s choice is ' . ($mcqCorrect ? 'CORRECT' : 'WRONG') . ".\n" : '')
            . "\nReturn JSON:\n"
            . '{"score": 0.0-1.0, "feedback": "Markdown, 2-5 sentences: say what was right; if wrong or incomplete, explain the mistake and the correct idea clearly", '
            . '"example": "if the answer was wrong or incomplete: a NEW short worked example or analogy from the excerpts that makes it click (Markdown, cite [C12]); otherwise empty string", '
            . '"next_question": {"type": "open or mcq", "prompt": "a new question on the same topic from a different angle", "options": ["4 options if mcq"], "answer": "...", "key_points": ["..."]}}'
            . "\n\nEXCERPTS:\n" . $context,
            ['max_tokens' => 2500, 'temperature' => 0.5]
        );
        $score = $dontKnow ? 0.0 : ($mcqCorrect !== null ? ($mcqCorrect ? 1.0 : 0.0) : max(0, min(1, (float) ($r['score'] ?? 0))));
        $correct = $score >= 0.7;
        Progress::recordTopic($uid, $topicId, $score, 0.25);
        $state['events'][] = ['type' => 'answer', 'topic_id' => $topicId, 'content' => $dontKnow ? 'Ved ikke' : $answer];
        $state['events'][] = ['type' => 'feedback', 'topic_id' => $topicId, 'correct' => $correct, 'score' => $score, 'content' => Retriever::scrubCitations((string) ($r['feedback'] ?? ''), $uid), 'answer' => $correct ? null : $question['answer']];
        $state['streak'] = $correct ? (int) $state['streak'] + 1 : 0;
        if (!$correct && !empty($r['example'])) {
            $state['events'][] = ['type' => 'example', 'topic_id' => $topicId, 'content' => Retriever::scrubCitations((string) $r['example'], $uid)];
        }
        if ($state['streak'] >= self::NEEDED_STREAK) {
            Progress::markUnderstood($uid, $topicId);
            $state['done'][] = $topicId;
            $state['events'][] = ['type' => 'topic_done', 'topic_id' => $topicId, 'name' => $topic['name']];
            $next = null;
            foreach ($state['plan'] as $tid) {
                if (!in_array((int) $tid, $state['done'], true)) {
                    $next = (int) $tid;
                    break;
                }
            }
            if ($next) {
                $state = self::teach($subject, $uid, $state, $next);
            } else {
                $state['awaiting'] = null;
                $state['events'][] = ['type' => 'complete'];
                DB::update('learn_sessions', ['status' => 'complete'], 'id = ?', [$session['id']]);
            }
        } else {
            $state['events'][] = self::questionEvent((array) ($r['next_question'] ?? []), $topicId);
            $state['awaiting'] = 'answer';
        }
        DB::update('learn_sessions', ['state' => json_encode($state, JSON_UNESCAPED_UNICODE), 'updated_at' => time()], 'id = ?', [$session['id']]);
        DB::update('subjects', ['last_studied_at' => time()], 'id = ?', [$subject['id']]);
        return self::view(self::owned((int) $session['id'], $uid), $uid);
    }

    /** Student asks to skip ahead or to get a different explanation. */
    public static function action(): array
    {
        $uid = Auth::id();
        $session = self::owned(Http::int('session_id'), $uid);
        Auth::release();
        @set_time_limit(240);
        $subject = Subjects::owned((int) $session['subject_id'], $uid);
        $state = Support::decode($session['state']);
        $action = Http::str('action');
        $topicId = (int) $state['current'];
        $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$topicId]);
        if ($action === 'explain_differently') {
            $prev = '';
            foreach (array_reverse($state['events']) as $e) {
                if ($e['type'] === 'explain') {
                    $prev = $e['content'];
                    break;
                }
            }
            $context = Retriever::context(Analyzer::topicChunks($subject, $topic, 10, 14000), 14000);
            $text = AI::chat([
                ['role' => 'system', 'content' => Analyzer::GROUNDING . "\nOutput Markdown only."],
                ['role' => 'user', 'content' => "The student did not understand this explanation of \"{$topic['name']}\":\n\n$prev\n\nExplain it in a completely different way: simpler words, a relatable analogy, then build up step by step. Max 250 words. Cite [C12] for facts from the excerpts.\n\nEXCERPTS:\n$context"],
            ], ['max_tokens' => 1500, 'temperature' => 0.7]);
            // Insert before the open question so the flow stays readable.
            $q = array_pop($state['events']);
            $state['events'][] = ['type' => 'explain', 'topic_id' => $topicId, 'content' => Retriever::scrubCitations($text, $uid), 'alt' => true];
            $state['events'][] = $q;
        } elseif ($action === 'skip') {
            $state['done'][] = $topicId;
            $state['events'][] = ['type' => 'topic_skipped', 'topic_id' => $topicId, 'name' => $topic['name']];
            $next = null;
            foreach ($state['plan'] as $tid) {
                if (!in_array((int) $tid, $state['done'], true)) {
                    $next = (int) $tid;
                    break;
                }
            }
            if ($next) {
                $state = self::teach($subject, $uid, $state, $next);
            } else {
                $state['awaiting'] = null;
                $state['events'][] = ['type' => 'complete'];
                DB::update('learn_sessions', ['status' => 'complete'], 'id = ?', [$session['id']]);
            }
        } elseif ($action === 'end') {
            DB::update('learn_sessions', ['status' => 'complete'], 'id = ?', [$session['id']]);
        } else {
            throw HttpError::bad('Ukendt handling.');
        }
        DB::update('learn_sessions', ['state' => json_encode($state, JSON_UNESCAPED_UNICODE), 'updated_at' => time()], 'id = ?', [$session['id']]);
        return self::view(self::owned((int) $session['id'], $uid), $uid);
    }
}

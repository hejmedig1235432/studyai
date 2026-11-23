<?php
declare(strict_types=1);

namespace App;

final class Subjects
{
    private const COLORS = ['#6d7cff', '#8b5cf6', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899', '#14b8a6'];

    public static function owned(int $id, int $uid): array
    {
        $s = DB::one('SELECT * FROM subjects WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$s) {
            throw HttpError::notFound('Subject');
        }
        // AI output language for this request: optional per-request override (e.g. tutor switch) → subject → user default.
        Lang::forSubject($s, Http::str('language', '', 10) ?: null);
        return $s;
    }

    public static function touch(int $id, ?string $status = null): void
    {
        $data = ['updated_at' => time()];
        if ($status !== null) {
            $cur = DB::val('SELECT status FROM subjects WHERE id = ?', [$id]);
            // Don't downgrade "processing"; the running job picks up new sources itself.
            if ($cur !== 'processing' || $status === 'empty') {
                $data['status'] = $status;
            }
        }
        DB::update('subjects', $data, 'id = ?', [$id]);
    }

    public static function summary(array $s): array
    {
        $id = (int) $s['id'];
        $topics = (int) DB::val('SELECT COUNT(*) FROM topics WHERE subject_id = ? AND active = 1', [$id]);
        $mastery = (float) DB::val('SELECT COALESCE(AVG(tp.mastery),0) FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id WHERE t.subject_id = ? AND t.active = 1', [$id]);
        $cards = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE subject_id = ?', [$id]);
        $due = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE subject_id = ? AND (due_at IS NULL OR due_at <= ?)', [$id, time()]);
        $job = DB::one("SELECT id, status, stage, progress, message FROM jobs WHERE subject_id = ? AND type = 'analyze' ORDER BY id DESC LIMIT 1", [$id]);
        [$guideTotal, $guideRead] = Guide::readingProgress($id);
        return [
            'id' => $id,
            'name' => $s['name'],
            'description' => $s['description'],
            'color' => $s['color'],
            'status' => $s['status'],
            'exam_date' => $s['exam_date'],
            'language' => $s['language'] ?? null,
            'created_at' => (int) $s['created_at'],
            'updated_at' => (int) $s['updated_at'],
            'last_studied_at' => $s['last_studied_at'] ? (int) $s['last_studied_at'] : null,
            'source_count' => (int) DB::val('SELECT COUNT(*) FROM sources WHERE subject_id = ? AND parent_id IS NULL', [$id]),
            'topic_count' => $topics,
            'card_count' => $cards,
            'due_cards' => $due,
            'progress' => (int) round($mastery * 100),
            'guide_total' => $guideTotal,
            'guide_read' => $guideRead,
            'job' => $job && in_array($job['status'], ['pending', 'running'], true) ? $job : null,
        ];
    }

    public static function list(): array
    {
        $uid = Auth::id();
        $rows = DB::all('SELECT * FROM subjects WHERE user_id = ? ORDER BY COALESCE(last_studied_at, updated_at) DESC', [$uid]);
        return ['subjects' => array_map([self::class, 'summary'], $rows)];
    }

    public static function create(): array
    {
        $uid = Auth::id();
        $name = Http::str('name', '', 100);
        if ($name === '') {
            throw HttpError::bad('Giv faget et navn.');
        }
        if ((int) DB::val('SELECT COUNT(*) FROM subjects WHERE user_id = ?', [$uid]) >= 200) {
            throw HttpError::bad('Du har nået det maksimale antal fag.');
        }
        $count = (int) DB::val('SELECT COUNT(*) FROM subjects WHERE user_id = ?', [$uid]);
        $color = Http::str('color', '', 16);
        $id = DB::insert('subjects', [
            'user_id' => $uid,
            'name' => $name,
            'description' => Http::str('description', '', 500) ?: null,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::COLORS[$count % count(self::COLORS)],
            'status' => 'empty',
            'exam_date' => self::date(Http::str('exam_date', '', 10)),
            'language' => Lang::valid(Http::str('subject_language', '', 10)) ? Http::str('subject_language', '', 10) : null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return ['subject' => self::summary(self::owned($id, $uid))];
    }

    private static function date(string $d): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    public static function get(): array
    {
        $uid = Auth::id();
        $s = self::owned(Http::int('id'), $uid);
        $topics = DB::all('SELECT t.id, t.name, t.summary, t.subtopics, t.importance, t.position,
                COALESCE(tp.mastery,0) AS mastery, COALESCE(tp.correct,0) AS correct, COALESCE(tp.incorrect,0) AS incorrect,
                COALESCE(tp.seconds,0) AS seconds, COALESCE(tp.understood,0) AS understood
            FROM topics t LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.user_id = ?
            WHERE t.subject_id = ? AND t.active = 1 ORDER BY t.position', [$uid, $s['id']]);
        foreach ($topics as &$t) {
            $t['id'] = (int) $t['id'];
            $t['subtopics'] = Support::decode($t['subtopics']);
            $t['mastery'] = round((float) $t['mastery'], 3);
            $t['cards'] = (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE topic_id = ?', [$t['id']]);
        }
        unset($t);
        $analysis = Support::decode($s['analysis'], null);
        $refIds = [];
        if (is_array($analysis)) {
            // Citations are stored as "C<chunk id>" strings.
            array_walk_recursive($analysis, static function ($v) use (&$refIds) {
                if (is_string($v) && preg_match('/^C(\d+)$/', $v, $m)) {
                    $refIds[] = (int) $m[1];
                }
            });
        }
        $recent = DB::all("SELECT mode, score, correct, total, finished_at FROM attempts WHERE subject_id = ? AND status = 'finished' ORDER BY finished_at DESC LIMIT 5", [$s['id']]);
        return [
            'subject' => self::summary($s),
            'topics' => $topics,
            'analysis' => $analysis,
            'refs' => Retriever::refMeta($refIds, $uid),
            'recent_attempts' => $recent,
        ];
    }

    public static function update(): array
    {
        $uid = Auth::id();
        $s = self::owned(Http::int('id'), $uid);
        $in = Http::input();
        $data = ['updated_at' => time()];
        if (array_key_exists('name', $in)) {
            $name = Http::str('name', '', 100);
            if ($name === '') {
                throw HttpError::bad('Navnet må ikke være tomt.');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('description', $in)) {
            $data['description'] = Http::str('description', '', 500) ?: null;
        }
        if (array_key_exists('color', $in) && preg_match('/^#[0-9a-fA-F]{6}$/', Http::str('color'))) {
            $data['color'] = Http::str('color');
        }
        if (array_key_exists('subject_language', $in)) {
            $l = Http::str('subject_language', '', 10);
            $data['language'] = Lang::valid($l) ? $l : null; // empty = use my default
        }
        if (array_key_exists('exam_date', $in)) {
            $data['exam_date'] = self::date(Http::str('exam_date', '', 10));
        }
        DB::update('subjects', $data, 'id = ?', [$s['id']]);
        return ['subject' => self::summary(self::owned((int) $s['id'], $uid))];
    }

    public static function delete(): array
    {
        $uid = Auth::id();
        $s = self::owned(Http::int('id'), $uid);
        self::destroy((int) $s['id'], $uid);
        return ['ok' => true];
    }

    /** Application-level cascade (MySQL ignores inline REFERENCES). */
    public static function destroy(int $id, int $uid): void
    {
        foreach (DB::col('SELECT id FROM sources WHERE subject_id = ? AND user_id = ?', [$id, $uid]) as $sid) {
            Sources::destroy((int) $sid);
        }
        DB::tx(static function () use ($id, $uid) {
            DB::exec('DELETE FROM tutor_messages WHERE thread_id IN (SELECT id FROM tutor_threads WHERE subject_id = ? AND user_id = ?)', [$id, $uid]);
            foreach (['tutor_threads', 'learn_sessions', 'answers', 'attempts', 'card_reviews', 'flashcards', 'questions', 'topic_progress', 'guide_sections', 'topics', 'chunks', 'jobs', 'study_log'] as $t) {
                DB::exec("DELETE FROM $t WHERE subject_id = ? AND user_id = ?", [$id, $uid]);
            }
            DB::exec('UPDATE essay_reviews SET subject_id = NULL WHERE subject_id = ? AND user_id = ?', [$id, $uid]);
            DB::exec('DELETE FROM subjects WHERE id = ? AND user_id = ?', [$id, $uid]);
        });
    }

    /** Starts (or resumes) background processing + AI analysis for a subject. */
    public static function process(): array
    {
        $uid = Auth::id();
        $s = self::owned(Http::int('id'), $uid);
        if (!(int) DB::val('SELECT COUNT(*) FROM sources WHERE subject_id = ?', [$s['id']])) {
            throw HttpError::bad('Tilføj mindst én kilde først.');
        }
        return ['job' => Pipeline::start((int) $s['id'], $uid, (bool) (Http::input()['full'] ?? false))];
    }
}

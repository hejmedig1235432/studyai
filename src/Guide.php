<?php
declare(strict_types=1);

namespace App;

/** Study guide: sections per topic, editing, AI rewrites and export. */
final class Guide
{
    public static function get(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $sections = self::sections((int) $s['id']);
        $ids = [];
        foreach ($sections as $sec) {
            $ids = array_merge($ids, Retriever::citedIds($sec['content']));
        }
        return ['sections' => $sections, 'refs' => Retriever::refMeta($ids, $uid)];
    }

    private static function sections(int $subjectId): array
    {
        $rows = DB::all('SELECT g.id, g.topic_id, g.title, g.content, g.position, g.edited, g.updated_at, g.read_at
            FROM guide_sections g LEFT JOIN topics t ON t.id = g.topic_id
            WHERE g.subject_id = ? AND (g.topic_id IS NULL OR t.active = 1)
            ORDER BY g.position, g.id', [$subjectId]);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['topic_id'] = $r['topic_id'] ? (int) $r['topic_id'] : null;
            $r['edited'] = (bool) $r['edited'];
            $r['read_at'] = $r['read_at'] ? (int) $r['read_at'] : null;
        }
        return $rows;
    }

    /** [total, read] topic sections the student must read before practising. */
    public static function readingProgress(int $subjectId): array
    {
        $row = DB::one('SELECT COUNT(*) AS total, SUM(CASE WHEN g.read_at IS NOT NULL THEN 1 ELSE 0 END) AS done
            FROM guide_sections g JOIN topics t ON t.id = g.topic_id WHERE g.subject_id = ? AND t.active = 1', [$subjectId]);
        return [(int) ($row['total'] ?? 0), (int) ($row['done'] ?? 0)];
    }

    /** Practice (quiz, flashcards, exam) unlocks once every study-guide section has been read. */
    public static function requireRead(int $subjectId): void
    {
        [$total, $done] = self::readingProgress($subjectId);
        if ($total > 0 && $done < $total) {
            throw new HttpError(423, "Læs din studieguide først ($done af $total afsnit læst). Flashcards, quizzer og prøveeksamener låses op, når du har læst alle afsnit.", 'read_first');
        }
    }

    public static function markRead(): array
    {
        $uid = Auth::id();
        $sec = self::owned(Http::int('id'), $uid);
        $read = (Http::input()['read'] ?? true) !== false;
        DB::update('guide_sections', ['read_at' => $read ? time() : null], 'id = ?', [$sec['id']]);
        [$total, $done] = self::readingProgress((int) $sec['subject_id']);
        return ['total' => $total, 'read' => $done];
    }

    private static function owned(int $id, int $uid): array
    {
        $sec = DB::one('SELECT * FROM guide_sections WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$sec) {
            throw HttpError::notFound('Section');
        }
        return $sec;
    }

    public static function save(): array
    {
        $uid = Auth::id();
        $sec = self::owned(Http::int('id'), $uid);
        $content = (string) (Http::input()['content'] ?? '');
        if (strlen($content) > 300000) {
            throw HttpError::bad('Afsnittet er for langt.');
        }
        $title = Http::str('title', $sec['title'], 150) ?: $sec['title'];
        DB::update('guide_sections', ['content' => $content, 'title' => $title, 'edited' => 1, 'updated_at' => time()], 'id = ?', [$sec['id']]);
        return ['ok' => true];
    }

    public static function addNote(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $id = DB::insert('guide_sections', [
            'user_id' => $uid, 'subject_id' => $s['id'], 'topic_id' => null,
            'title' => Http::str('title', 'Mine noter', 150) ?: 'Mine noter',
            'content' => (string) (Http::input()['content'] ?? ''),
            'position' => 1000, 'edited' => 1, 'updated_at' => time(),
        ]);
        return ['id' => $id];
    }

    public static function delete(): array
    {
        $uid = Auth::id();
        $sec = self::owned(Http::int('id'), $uid);
        if ($sec['topic_id']) {
            throw HttpError::bad('Emneafsnit kan ikke slettes, men du kan redigere dem eller skrive dem igen.');
        }
        DB::exec('DELETE FROM guide_sections WHERE id = ?', [$sec['id']]);
        return ['ok' => true];
    }

    /** Regenerates a topic section from the sources (overwrites edits). */
    public static function regenerate(): array
    {
        $uid = Auth::id();
        $sec = self::owned(Http::int('id'), $uid);
        Auth::release();
        if (!$sec['topic_id']) {
            throw HttpError::bad('Kun emneafsnit kan skrives igen.');
        }
        $subject = Subjects::owned((int) $sec['subject_id'], $uid);
        $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$sec['topic_id']]);
        $others = DB::col('SELECT name FROM topics WHERE subject_id = ? AND active = 1 AND id != ?', [$subject['id'], $topic['id']]);
        $content = Analyzer::guideSection($subject, $topic, Retriever::context(Analyzer::topicChunks($subject, $topic), 24000), $others, $uid);
        DB::update('guide_sections', ['content' => $content, 'edited' => 0, 'read_at' => null, 'updated_at' => time()], 'id = ?', [$sec['id']]);
        return ['content' => $content, 'refs' => Retriever::refMeta(Retriever::citedIds($content), $uid)];
    }

    /** AI rewrite of a section: simplify | shorter | detailed | highlight. Returns a proposal (not saved). */
    public static function transform(): array
    {
        $uid = Auth::id();
        $sec = self::owned(Http::int('id'), $uid);
        Auth::release();
        $mode = Http::str('mode');
        Lang::forSubjectId((int) $sec['subject_id'], Http::str('language', '', 10) ?: null);
        $content = (string) (Http::input()['content'] ?? $sec['content']);
        if (isset(Http::input()['content'])) {
            Security::inspect([$content], 'Studieguide', true);
        }
        $instructions = match ($mode) {
            'simplify' => 'Rewrite this study-guide section in simpler language that a 14-year-old can follow: short sentences, everyday words, explain any jargon the first time it appears. Keep every fact and every [C12] citation.',
            'shorter' => 'Condense this section into a short revision summary (roughly a third of the length): only the most important points as tight bullets. Keep the [C12] citations of the points you keep.',
            'detailed' => 'Expand this section into a more detailed explanation: explain each concept step by step and how the points connect. You may only add details that appear in the SOURCE EXCERPTS below, cited with [C12]. Do not add outside facts.',
            'translate' => 'Translate this section into ' . Lang::name() . '. Keep the Markdown structure, ==highlights== and every [C12] citation exactly where they are. Translate faithfully; do not add or remove information.',
            'highlight' => 'Return the section unchanged, except wrap the most important, test-relevant phrases (key terms, core facts, dates) in ==double equals== to highlight them. Highlight about 10-20% of the text. Do not change any other characters.',
            default => throw HttpError::bad('Ukendt omskrivning.'),
        };
        $extra = '';
        if ($mode === 'detailed' && $sec['topic_id']) {
            $subject = Subjects::owned((int) $sec['subject_id'], $uid);
            $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$sec['topic_id']]);
            if ($topic) {
                $extra = "\n\nSOURCE EXCERPTS:\n" . Retriever::context(Analyzer::topicChunks($subject, $topic), 20000);
            }
        }
        $out = AI::chat([
            ['role' => 'system', 'content' => Analyzer::GROUNDING . "\nYou are editing a student's study notes. Output Markdown only, no preamble."],
            ['role' => 'user', 'content' => $instructions . "\n\nSECTION:\n" . $content . $extra],
        ], ['max_tokens' => 6000, 'temperature' => 0.3]);
        $out = preg_replace('/^```(?:markdown|md)?\s*|\s*```$/i', '', trim($out)) ?? $out;
        $out = Retriever::scrubCitations($out, $uid);
        return ['content' => $out, 'refs' => Retriever::refMeta(Retriever::citedIds($out), $uid)];
    }

    /** One-page cheat sheet built from all sections. */
    public static function summary(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        Auth::release();
        $sections = self::sections((int) $s['id']);
        if (!$sections) {
            throw HttpError::bad('Studieguiden er tom. Analysér dine kilder først.');
        }
        $all = '';
        foreach ($sections as $sec) {
            if ($sec['title'] === 'Resumé på én side') {
                continue;
            }
            $all .= "## {$sec['title']}\n{$sec['content']}\n\n";
        }
        $out = AI::chat([
            ['role' => 'system', 'content' => Analyzer::GROUNDING . "\nOutput Markdown only."],
            ['role' => 'user', 'content' => "Create a one-page revision cheat sheet from these study notes: for each topic a ### heading with 3-6 compact bullets of the most test-relevant points. End with a final checklist section (heading translated into the output language). Keep [C12] citations. Use only what is in the notes.\n\nNOTES:\n" . mb_substr($all, 0, 60000)],
        ], ['max_tokens' => 5000, 'temperature' => 0.3]);
        $out = Retriever::scrubCitations(preg_replace('/^```(?:markdown|md)?\s*|\s*```$/i', '', trim($out)) ?? $out, $uid);
        $existing = DB::one("SELECT id FROM guide_sections WHERE subject_id = ? AND topic_id IS NULL AND title = 'Resumé på én side'", [$s['id']]);
        if ($existing) {
            DB::update('guide_sections', ['content' => $out, 'updated_at' => time()], 'id = ?', [$existing['id']]);
        } else {
            DB::insert('guide_sections', ['user_id' => $uid, 'subject_id' => $s['id'], 'topic_id' => null, 'title' => 'Resumé på én side', 'content' => $out, 'position' => -1, 'edited' => 0, 'updated_at' => time()]);
        }
        return self::get();
    }

    /** Markdown download of the full guide. */
    public static function exportMarkdown(): never
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('subject_id'), $uid);
        $md = '# ' . $s['name'] . " – Studieguide\n\n";
        $sections = self::sections((int) $s['id']);
        $ids = [];
        foreach ($sections as $sec) {
            $md .= '## ' . $sec['title'] . "\n\n" . $sec['content'] . "\n\n";
            $ids = array_merge($ids, Retriever::citedIds($sec['content']));
        }
        $refs = Retriever::refMeta($ids, $uid);
        if ($refs) {
            $md .= "## Kilder\n\n";
            foreach ($refs as $id => $r) {
                $md .= "- [C$id] {$r['source_title']}" . ($r['label'] ? " ({$r['label']})" : '') . "\n";
            }
        }
        Http::download($s['name'] . ' - studieguide.md', 'text/markdown; charset=utf-8', $md);
    }
}

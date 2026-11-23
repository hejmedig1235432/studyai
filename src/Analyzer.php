<?php
declare(strict_types=1);

namespace App;

/** AI analysis of a subject's material: map/reduce analysis, topics, study guide and flashcards. */
final class Analyzer
{
    public const GROUNDING = "You are Studiepilot, an expert teacher who turns a student's own learning material into study material.\n"
        . "STRICT RULES:\n"
        . "- Use ONLY information stated in the provided source excerpts. Never add facts, names, dates, numbers or claims from outside knowledge.\n"
        . "- Every item must cite the excerpt ids it is based on, using the ids shown in square brackets, e.g. C12.\n"
        . "- If the excerpts are ambiguous, contradictory or incomplete about something, say so instead of guessing.";

    private const BATCH_CHARS = 22000;
    private const MAX_BATCHES = 60;

    /** Groups all chunk ids of a subject (in reading order) into batches for the map step. */
    public static function batches(int $subjectId): array
    {
        $rows = DB::all('SELECT id, LENGTH(content) AS len FROM chunks WHERE subject_id = ? ORDER BY source_id, position', [$subjectId]);
        $total = array_sum(array_map(static fn ($r) => (int) $r['len'], $rows));
        $size = max(self::BATCH_CHARS, (int) ceil($total / self::MAX_BATCHES));
        $batches = [];
        $cur = [];
        $len = 0;
        foreach ($rows as $r) {
            if ($len + (int) $r['len'] > $size && $cur) {
                $batches[] = $cur;
                $cur = [];
                $len = 0;
            }
            $cur[] = (int) $r['id'];
            $len += (int) $r['len'];
        }
        if ($cur) {
            $batches[] = $cur;
        }
        return $batches;
    }

    /** Keeps only valid citations (as "C<id>") and drops items that cite nothing real. */
    public static function cleanItems(mixed $items, int $uid, array $fields, int $limit = 200, bool $requireRefs = true): array
    {
        $out = [];
        foreach ((array) $items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $row = [];
            foreach ($fields as $f) {
                $v = $it[$f] ?? null;
                if (is_array($v)) {
                    $row[$f] = array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v)));
                } elseif (is_numeric($v) && $f === 'importance') {
                    $row[$f] = max(1, min(3, (int) $v));
                } else {
                    $row[$f] = is_scalar($v) ? trim((string) $v) : '';
                }
            }
            $ids = Retriever::refIds($it['refs'] ?? [], $uid);
            if ($requireRefs && !$ids) {
                continue;
            }
            $row['refs'] = array_map(static fn ($i) => 'C' . $i, $ids);
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public static function mapBatch(int $uid, array $chunkIds): array
    {
        $chunks = Retriever::hydrate($chunkIds);
        $context = Retriever::context($chunks, 70000);
        $schema = '{
 "topics": [{"name": "short topic name", "subtopics": ["..."], "summary": "1-2 sentences", "refs": ["C1"]}],
 "definitions": [{"term": "...", "definition": "...", "refs": ["C1"]}],
 "key_facts": [{"fact": "one self-contained fact", "importance": 1-3, "refs": ["C1"]}],
 "dates": [{"date": "as written in the source", "event": "...", "refs": ["C1"]}],
 "people": [{"name": "...", "description": "who they are / why they matter", "refs": ["C1"]}],
 "relationships": [{"from": "concept A", "to": "concept B", "relation": "how A relates to B (cause, part of, contrast, leads to...)", "refs": ["C1"]}],
 "test_focus": [{"point": "something likely to be asked on a test", "why": "why it is important", "refs": ["C1"]}],
 "unclear": [{"issue": "what is unclear, incomplete or contradictory in the material", "refs": ["C1"]}]
}';
        $user = "Analyse these excerpts from the student's material. Extract everything that matters for studying, as JSON with this shape:\n$schema\n\n"
            . "importance: 3 = core/very likely tested, 2 = important, 1 = detail. Leave a list empty if the excerpts contain nothing for it. Be thorough but avoid trivia (page numbers, copyright, table of contents).\n\nEXCERPTS:\n" . $context;
        $r = AI::json(self::GROUNDING, $user, ['max_tokens' => 7000, 'temperature' => 0.2]);
        return [
            'topics' => self::cleanItems($r['topics'] ?? [], $uid, ['name', 'subtopics', 'summary'], 20),
            'definitions' => self::cleanItems($r['definitions'] ?? [], $uid, ['term', 'definition'], 60),
            'key_facts' => self::cleanItems($r['key_facts'] ?? [], $uid, ['fact', 'importance'], 60),
            'dates' => self::cleanItems($r['dates'] ?? [], $uid, ['date', 'event'], 40),
            'people' => self::cleanItems($r['people'] ?? [], $uid, ['name', 'description'], 30),
            'relationships' => self::cleanItems($r['relationships'] ?? [], $uid, ['from', 'to', 'relation'], 30),
            'test_focus' => self::cleanItems($r['test_focus'] ?? [], $uid, ['point', 'why'], 20),
            'unclear' => self::cleanItems($r['unclear'] ?? [], $uid, ['issue'], 10),
        ];
    }

    /** Merges list items with the same key, unioning their refs. */
    private static function dedupe(array $items, callable $key, int $limit): array
    {
        $out = [];
        foreach ($items as $it) {
            $k = $key($it);
            if ($k === '') {
                continue;
            }
            if (isset($out[$k])) {
                $out[$k]['refs'] = array_values(array_unique(array_merge($out[$k]['refs'], $it['refs'])));
                if (isset($it['importance'])) {
                    $out[$k]['importance'] = max($out[$k]['importance'] ?? 1, $it['importance']);
                }
            } else {
                $out[$k] = $it;
            }
        }
        $out = array_values($out);
        if (isset($out[0]['importance'])) {
            usort($out, static fn ($a, $b) => ($b['importance'] ?? 1) <=> ($a['importance'] ?? 1));
        }
        return array_slice($out, 0, $limit);
    }

    /** @return int[] ids of the active topics, in learning order */
    public static function reduce(array $subject, array $partials): array
    {
        $uid = (int) $subject['user_id'];
        $sid = (int) $subject['id'];
        $all = ['topics' => [], 'definitions' => [], 'key_facts' => [], 'dates' => [], 'people' => [], 'relationships' => [], 'test_focus' => [], 'unclear' => []];
        foreach ($partials as $p) {
            foreach ($all as $k => $_) {
                $all[$k] = array_merge($all[$k], (array) ($p[$k] ?? []));
            }
        }
        $topicLines = [];
        foreach ($all['topics'] as $i => $t) {
            $topicLines[] = "$i. {$t['name']}" . ($t['subtopics'] ? ' — subtopics: ' . implode('; ', array_slice($t['subtopics'], 0, 8)) : '') . ($t['summary'] ? " — {$t['summary']}" : '');
        }
        $focusLines = [];
        foreach (array_slice($all['test_focus'], 0, 80) as $i => $f) {
            $focusLines[] = "$i. {$f['point']} ({$f['why']})";
        }
        $user = "These topic notes were extracted from consecutive parts of a student's material for the subject \"{$subject['name']}\".\n"
            . "Merge them into ONE clean topic structure for studying. Return JSON:\n"
            . '{"overview": "3-5 sentence overview of what the material covers", '
            . '"topics": [{"name": "...", "summary": "2-3 sentences", "subtopics": ["..."], "importance": 1-3, "from": [indices of the source topic notes merged into this topic]}], '
            . '"test_focus": [{"from": index of a test-focus note, "point": "rephrased if needed"}]}' . "\n"
            . "Rules: 3 to 14 topics, ordered so they can be learned in sequence (foundations first). Merge duplicates and near-duplicates. Do not invent topics that are not in the notes. Pick the 12 most important test-focus notes.\n\n"
            . "TOPIC NOTES:\n" . implode("\n", $topicLines) . "\n\nTEST-FOCUS NOTES:\n" . implode("\n", $focusLines);
        if (mb_strlen($user) > 60000) {
            $user = mb_substr($user, 0, 60000);
        }
        $r = AI::json('You organise study notes. Only reorganise what is given; never add new information.', $user, ['max_tokens' => 5000, 'temperature' => 0.2]);

        // Final topics, with citations inherited from merged partial topics.
        $finalTopics = [];
        foreach ((array) ($r['topics'] ?? []) as $t) {
            if (!is_array($t) || trim((string) ($t['name'] ?? '')) === '') {
                continue;
            }
            $refs = [];
            foreach ((array) ($t['from'] ?? []) as $idx) {
                if (is_numeric($idx) && isset($all['topics'][(int) $idx])) {
                    $refs = array_merge($refs, $all['topics'][(int) $idx]['refs']);
                }
            }
            $finalTopics[] = [
                'name' => Support::excerpt(trim((string) $t['name']), 100),
                'summary' => trim((string) ($t['summary'] ?? '')),
                'subtopics' => array_values(array_filter(array_map('strval', (array) ($t['subtopics'] ?? [])))),
                'importance' => max(1, min(3, (int) ($t['importance'] ?? 2))),
                'refs' => array_values(array_unique($refs)),
            ];
        }
        if (!$finalTopics) {
            // Fall back to deduplicated partial topics.
            $finalTopics = array_map(static fn ($t) => $t + ['importance' => 2], self::dedupe($all['topics'], static fn ($t) => Support::norm($t['name']), 12));
        }
        $focus = [];
        foreach ((array) ($r['test_focus'] ?? []) as $f) {
            $idx = is_array($f) ? ($f['from'] ?? null) : $f;
            if (is_numeric($idx) && isset($all['test_focus'][(int) $idx])) {
                $item = $all['test_focus'][(int) $idx];
                if (is_array($f) && !empty($f['point'])) {
                    $item['point'] = (string) $f['point'];
                }
                $focus[] = $item;
            }
        }
        if (!$focus) {
            $focus = array_slice($all['test_focus'], 0, 12);
        }

        // Upsert topics, keeping ids (and the student's progress) for topics that survive re-analysis.
        $existing = [];
        foreach (DB::all('SELECT id, name FROM topics WHERE subject_id = ?', [$sid]) as $row) {
            $existing[Support::norm($row['name'])] = (int) $row['id'];
        }
        $ids = [];
        DB::exec('UPDATE topics SET active = 0 WHERE subject_id = ?', [$sid]);
        foreach ($finalTopics as $pos => $t) {
            $data = [
                'name' => $t['name'],
                'summary' => $t['summary'],
                'subtopics' => json_encode($t['subtopics'], JSON_UNESCAPED_UNICODE),
                'importance' => $t['importance'],
                'position' => $pos,
                'active' => 1,
            ];
            $key = Support::norm($t['name']);
            if (isset($existing[$key]) && !in_array($existing[$key], $ids, true)) {
                DB::update('topics', $data, 'id = ?', [$existing[$key]]);
                $ids[] = $existing[$key];
            } else {
                $ids[] = DB::insert('topics', $data + ['user_id' => $uid, 'subject_id' => $sid, 'created_at' => time()]);
            }
        }

        // Assign items to topics by keyword overlap.
        $topicTerms = [];
        foreach ($finalTopics as $i => $t) {
            $terms = [];
            foreach (Retriever::tokenize($t['name']) as $w) {
                $terms[$w] = ($terms[$w] ?? 0) + 3;
            }
            foreach (Retriever::tokenize(implode(' ', $t['subtopics'])) as $w) {
                $terms[$w] = ($terms[$w] ?? 0) + 2;
            }
            foreach (Retriever::tokenize($t['summary']) as $w) {
                $terms[$w] = ($terms[$w] ?? 0) + 1;
            }
            $topicTerms[$i] = $terms;
        }
        $assign = static function (string $text) use ($topicTerms, $ids): ?int {
            $best = null;
            $bestScore = 0;
            $words = array_unique(Retriever::tokenize($text));
            foreach ($topicTerms as $i => $terms) {
                $score = 0;
                foreach ($words as $w) {
                    $score += $terms[$w] ?? 0;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $ids[$i];
                }
            }
            return $best;
        };
        $lists = [
            'definitions' => [self::dedupe($all['definitions'], static fn ($d) => Support::norm($d['term']), 150), static fn ($d) => $d['term'] . ' ' . $d['term'] . ' ' . $d['definition']],
            'key_facts' => [self::dedupe($all['key_facts'], static fn ($f) => mb_substr(Support::norm($f['fact']), 0, 80), 200), static fn ($f) => $f['fact']],
            'dates' => [self::dedupe($all['dates'], static fn ($d) => Support::norm($d['date'] . ' ' . $d['event']), 100), static fn ($d) => $d['event']],
            'people' => [self::dedupe($all['people'], static fn ($p) => Support::norm($p['name']), 80), static fn ($p) => $p['name'] . ' ' . $p['description']],
            'relationships' => [self::dedupe($all['relationships'], static fn ($r) => Support::norm($r['from'] . ' ' . $r['to']), 80), static fn ($r) => $r['from'] . ' ' . $r['to'] . ' ' . $r['relation']],
        ];
        $analysis = [];
        foreach ($lists as $k => [$items, $textOf]) {
            foreach ($items as &$it) {
                $it['topic_id'] = $assign($textOf($it));
            }
            unset($it);
            $analysis[$k] = $items;
        }
        $analysis['test_focus'] = $focus;
        $analysis['unclear'] = self::dedupe($all['unclear'], static fn ($u) => mb_substr(Support::norm($u['issue']), 0, 60), 25);
        $analysis['overview'] = trim((string) ($r['overview'] ?? ''));
        $analysis['topics'] = array_map(static fn ($id, $t) => ['id' => $id, 'name' => $t['name'], 'refs' => array_slice($t['refs'], 0, 12)], $ids, $finalTopics);
        $analysis['stats'] = [
            'sources' => (int) DB::val("SELECT COUNT(*) FROM sources WHERE subject_id = ? AND status = 'ready'", [$sid]),
            'chunks' => (int) DB::val('SELECT COUNT(*) FROM chunks WHERE subject_id = ?', [$sid]),
            'chars' => (int) DB::val("SELECT COALESCE(SUM(char_count),0) FROM sources WHERE subject_id = ? AND status = 'ready'", [$sid]),
        ];
        $analysis['generated_at'] = time();
        DB::update('subjects', ['analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE), 'updated_at' => time()], 'id = ?', [$sid]);
        return $ids;
    }

    /** Relevant chunks for a topic: its cited chunks first, then retrieval results. */
    public static function topicChunks(array $subject, array $topic, int $k = 14, int $maxChars = 22000): array
    {
        $analysis = Support::decode($subject['analysis']);
        $cited = [];
        foreach ((array) ($analysis['topics'] ?? []) as $t) {
            if ((int) $t['id'] === (int) $topic['id']) {
                foreach ((array) $t['refs'] as $r) {
                    $cited[] = (int) substr((string) $r, 1);
                }
            }
        }
        $query = $topic['name'] . ' ' . implode(' ', Support::decode($topic['subtopics'])) . ' ' . $topic['summary'];
        $found = Retriever::search((int) $subject['user_id'], (int) $subject['id'], $query, $k, ['exclude' => $cited]);
        $chunks = array_merge(Retriever::hydrate(array_slice($cited, 0, 6)), $found);
        $out = [];
        $len = 0;
        foreach ($chunks as $c) {
            if ($len + mb_strlen($c['content']) > $maxChars) {
                break;
            }
            $out[] = $c;
            $len += mb_strlen($c['content']);
        }
        return $out ?: Retriever::sample((int) $subject['id'], 6);
    }

    /** @param string $part 'all' | 'guide' | 'cards' (parts can run in separate processes in parallel) */
    public static function topicMaterials(array $subject, array $topic, string $part = 'all'): void
    {
        $uid = (int) $subject['user_id'];
        $chunks = self::topicChunks($subject, $topic);
        $context = Retriever::context($chunks, 24000);
        if ($part === 'cards') {
            self::generateCards($subject, $topic, $context, 12);
            return;
        }
        $others = DB::col('SELECT name FROM topics WHERE subject_id = ? AND active = 1 AND id != ?', [$subject['id'], $topic['id']]);

        // Study guide section (kept if the student edited it).
        $section = DB::one('SELECT * FROM guide_sections WHERE subject_id = ? AND topic_id = ?', [$subject['id'], $topic['id']]);
        if (!$section || !(int) $section['edited']) {
            $content = self::guideSection($subject, $topic, $context, $others, $uid);
            if ($section) {
                DB::update('guide_sections', ['title' => $topic['name'], 'content' => $content, 'position' => $topic['position'], 'read_at' => null, 'updated_at' => time()], 'id = ?', [$section['id']]);
            } else {
                DB::insert('guide_sections', [
                    'user_id' => $uid, 'subject_id' => $subject['id'], 'topic_id' => $topic['id'], 'title' => $topic['name'],
                    'content' => $content, 'position' => $topic['position'], 'edited' => 0, 'updated_at' => time(),
                ]);
            }
        } else {
            DB::update('guide_sections', ['position' => $topic['position']], 'id = ?', [$section['id']]);
        }
        if ($part === 'all') {
            self::generateCards($subject, $topic, $context, 12);
        }
    }

    public static function guideSection(array $subject, array $topic, string $context, array $otherTopics, int $uid): string
    {
        $user = "Write the study-guide section for the topic \"{$topic['name']}\" (subject: {$subject['name']}), using only the excerpts below.\n"
            . "Format: Markdown. Use these ### headings in this order, and SKIP any heading the excerpts have nothing for:\n"
            . "### Summary\n### Key concepts  (bullets: **Concept** — clear explanation)\n### Definitions  (bullets: **Term**: definition)\n### Key facts\n### Important dates  (only if the excerpts contain dates)\n### Examples  (only examples given in the excerpts)\n### How it connects  (relationships between concepts, and to these other topics in the subject where the excerpts support it: " . implode('; ', array_slice($otherTopics, 0, 15)) . ")\n### Remember for the test  (3-7 bullets with the most testable points)\n\n"
            . "Cite the excerpt id after each statement like [C12]. Explain clearly for a student, but do not add facts that are not in the excerpts. "
            . "If something important is unclear or contradictory in the excerpts, add a line starting with \"> **Unclear in your sources:**\". Do not include a top-level title.\n\nEXCERPTS:\n" . $context;
        $md = AI::chat([
            ['role' => 'system', 'content' => self::GROUNDING],
            ['role' => 'user', 'content' => $user],
        ], ['max_tokens' => 5000, 'temperature' => 0.3]);
        $md = preg_replace('/^```(?:markdown|md)?\s*|\s*```$/i', '', trim($md)) ?? $md;
        return Retriever::scrubCitations($md, $uid);
    }

    /** Generates new flashcards for a topic, avoiding duplicates of existing cards. Returns the number added. */
    public static function generateCards(array $subject, array $topic, string $context, int $max = 12): int
    {
        $uid = (int) $subject['user_id'];
        $existing = DB::col('SELECT front FROM flashcards WHERE subject_id = ? AND topic_id = ?', [$subject['id'], $topic['id']]);
        $user = "Create up to $max flashcards for the topic \"{$topic['name']}\" from these excerpts. Return JSON: {\"cards\": [{\"front\": \"question or term\", \"back\": \"concise answer (max ~40 words)\", \"refs\": [\"C12\"]}]}\n"
            . "Mix card types: definitions, key facts, cause → effect, comparisons, dates/people (only if present), and 'why/how' questions. Each card tests ONE thing. The answer must be fully supported by the cited excerpt. Fewer good cards are better than padding.\n"
            . ($existing ? "Do NOT repeat or rephrase these existing cards:\n- " . implode("\n- ", array_slice($existing, 0, 60)) . "\n" : '')
            . "\nEXCERPTS:\n" . $context;
        $r = AI::json(self::GROUNDING, $user, ['max_tokens' => 4000, 'temperature' => 0.5]);
        $cards = self::cleanItems($r['cards'] ?? [], $uid, ['front', 'back'], $max);
        $seen = array_flip(array_map(static fn ($f) => md5(Support::norm($f)), $existing));
        $added = 0;
        foreach ($cards as $c) {
            if ($c['front'] === '' || $c['back'] === '') {
                continue;
            }
            $h = md5(Support::norm($c['front']));
            if (isset($seen[$h])) {
                continue;
            }
            $seen[$h] = true;
            DB::insert('flashcards', [
                'user_id' => $uid, 'subject_id' => $subject['id'], 'topic_id' => $topic['id'],
                'front' => mb_substr($c['front'], 0, 500), 'back' => mb_substr($c['back'], 0, 1500),
                'refs' => json_encode($c['refs']), 'hash' => $h, 'due_at' => null, 'created_at' => time(),
            ]);
            $added++;
        }
        return $added;
    }
}

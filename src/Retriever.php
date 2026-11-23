<?php
declare(strict_types=1);

namespace App;

/**
 * Chunking + retrieval for RAG. Uses BM25 keyword ranking (always available) and blends
 * in embedding similarity when an embedding model is configured.
 */
final class Retriever
{
    private const TARGET = 1400;
    private const OVERLAP = 180;

    private const STOP = ['the','a','an','and','or','but','if','of','to','in','on','at','by','for','with','about','as','into','from','is','are','was','were','be','been','being','it','its','this','that','these','those','which','who','whom','what','when','where','why','how','not','no','do','does','did','has','have','had','can','could','will','would','should','may','might','must','than','then','so','such','there','their','they','them','he','she','his','her','we','our','you','your','i','me','my','also','more','most','other','some','any','each','all','both','very','just','only','own','same','too','de','het','een','en','of','van','in','op','te','met','voor','aan','is','zijn','was','waren','dat','die','dit','deze','niet','geen','ook','als','bij','door','naar','om','tot','uit','wat','wie','hoe','er','hij','zij','ze','we','wij','je','jij','u','ik','mijn','hun','haar','zo','dan','maar','nog','wel','al','worden','wordt','werd','kan','kunnen','moet','moeten','heeft','hebben','had'];

    public static function tokenize(string $text): array
    {
        static $stop = null;
        $stop ??= array_flip(self::STOP);
        $text = mb_strtolower($text);
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $m);
        $out = [];
        foreach ($m[0] as $t) {
            if (isset($stop[$t])) {
                continue;
            }
            // Light stemming so "cells"/"cell" and "dividing"/"divide" match.
            if (mb_strlen($t) > 4) {
                $t = preg_replace('/(ing|ies|es|s|ed)$/u', '', $t) ?? $t;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Splits labelled segments into overlapping chunks, merging tiny neighbouring segments.
     * @param array<int, array{label:string, text:string}> $segments
     * @return array<int, array{label:string, text:string}>
     */
    public static function chunk(array $segments): array
    {
        $chunks = [];
        $buf = '';
        $labels = [];
        $flush = static function () use (&$chunks, &$buf, &$labels): void {
            $t = trim($buf);
            if ($t !== '') {
                $label = $labels ? (count($labels) > 1 ? $labels[0] . ' – ' . end($labels) : $labels[0]) : '';
                $chunks[] = ['label' => $label, 'text' => $t];
            }
            $buf = '';
            $labels = [];
        };
        foreach ($segments as $seg) {
            $text = trim((string) $seg['text']);
            if ($text === '') {
                continue;
            }
            $label = (string) ($seg['label'] ?? '');
            if (mb_strlen($text) <= self::TARGET) {
                if (mb_strlen($buf) + mb_strlen($text) > self::TARGET && $buf !== '') {
                    $flush();
                }
                $buf .= ($buf === '' ? '' : "\n\n") . $text;
                if ($label !== '' && !in_array($label, $labels, true)) {
                    $labels[] = $label;
                }
                continue;
            }
            $flush();
            foreach (self::split($text) as $piece) {
                $chunks[] = ['label' => $label, 'text' => $piece];
            }
        }
        $flush();
        return $chunks;
    }

    /** Splits long text at paragraph/sentence boundaries with overlap. */
    private static function split(string $text): array
    {
        $out = [];
        $len = mb_strlen($text);
        $pos = 0;
        while ($pos < $len) {
            $end = min($len, $pos + self::TARGET);
            if ($end < $len) {
                $window = mb_substr($text, $pos, self::TARGET);
                $cut = max(
                    (int) mb_strrpos($window, "\n\n"),
                    (int) mb_strrpos($window, '. '),
                    (int) mb_strrpos($window, "\n")
                );
                if ($cut > self::TARGET * 0.5) {
                    $end = $pos + $cut + 1;
                }
            }
            $out[] = trim(mb_substr($text, $pos, $end - $pos));
            if ($end >= $len) {
                break;
            }
            $pos = max($pos + 1, $end - self::OVERLAP);
        }
        return array_values(array_filter($out, static fn ($c) => $c !== ''));
    }

    /** Stores chunks for a source and (optionally) computes embeddings. Returns chunk count. */
    public static function index(array $source, array $segments): int
    {
        DB::exec('DELETE FROM chunks WHERE source_id = ?', [$source['id']]);
        $chunks = self::chunk($segments);
        $ids = [];
        DB::tx(static function () use ($chunks, $source, &$ids) {
            foreach ($chunks as $i => $c) {
                $tokens = self::tokenize($c['text'] . ' ' . $source['title']);
                $ids[] = DB::insert('chunks', [
                    'user_id' => $source['user_id'],
                    'subject_id' => $source['subject_id'],
                    'source_id' => $source['id'],
                    'position' => $i,
                    'label' => mb_substr($c['label'], 0, 120),
                    'content' => $c['text'],
                    'terms' => json_encode(array_count_values($tokens), JSON_UNESCAPED_UNICODE),
                    'term_count' => max(1, count($tokens)),
                ]);
            }
        });
        // Embeddings in batches of 64 (silently skipped if no embedding model).
        foreach (array_chunk(array_keys($chunks), 64) as $batch) {
            $vecs = AI::embed(array_map(static fn ($i) => mb_substr($chunks[$i]['text'], 0, 6000), $batch));
            if ($vecs === null) {
                break;
            }
            foreach ($batch as $j => $i) {
                DB::update('chunks', ['embedding' => self::packVec($vecs[$j])], 'id = ?', [$ids[$i]]);
            }
        }
        return count($chunks);
    }

    private static function packVec(array $v): string
    {
        return base64_encode(pack('g*', ...$v));
    }

    private static function unpackVec(?string $s): ?array
    {
        if (!$s) {
            return null;
        }
        $raw = base64_decode($s, true);
        return $raw === false ? null : array_values(unpack('g*', $raw) ?: []);
    }

    private static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /**
     * @return array<int, array> top chunks (id, source_id, label, content, score, source_title)
     */
    public static function search(int $userId, ?int $subjectId, string $query, int $k = 8, array $opt = []): array
    {
        $where = 'c.user_id = ?';
        $params = [$userId];
        if ($subjectId) {
            $where .= ' AND c.subject_id = ?';
            $params[] = $subjectId;
        }
        if (!empty($opt['source_ids'])) {
            $where .= ' AND c.source_id IN (' . DB::in($opt['source_ids']) . ')';
            $params = array_merge($params, $opt['source_ids']);
        }
        $rows = DB::all("SELECT c.id, c.terms, c.term_count, c.embedding FROM chunks c WHERE $where", $params);
        if (!$rows) {
            return [];
        }
        $qTerms = array_unique(self::tokenize($query));
        $N = count($rows);
        $avg = array_sum(array_column($rows, 'term_count')) / max(1, $N);
        $df = array_fill_keys($qTerms, 0);
        $parsed = [];
        foreach ($rows as $r) {
            $t = json_decode($r['terms'], true) ?: [];
            $parsed[$r['id']] = $t;
            foreach ($qTerms as $q) {
                if (isset($t[$q])) {
                    $df[$q]++;
                }
            }
        }
        $k1 = 1.4;
        $b = 0.75;
        $bm = [];
        foreach ($rows as $r) {
            $t = $parsed[$r['id']];
            $score = 0.0;
            foreach ($qTerms as $q) {
                if (!isset($t[$q])) {
                    continue;
                }
                $idf = log(1 + ($N - $df[$q] + 0.5) / ($df[$q] + 0.5));
                $tf = $t[$q];
                $score += $idf * ($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * $r['term_count'] / $avg));
            }
            $bm[$r['id']] = $score;
        }
        $final = $bm;
        // Hybrid: blend in embedding similarity when vectors exist.
        $hasVec = false;
        foreach ($rows as $r) {
            if ($r['embedding']) {
                $hasVec = true;
                break;
            }
        }
        if ($hasVec && empty($opt['no_vectors'])) {
            $qv = AI::embed([mb_substr($query, 0, 4000)]);
            if ($qv) {
                $max = max($bm) ?: 1;
                foreach ($rows as $r) {
                    $v = self::unpackVec($r['embedding']);
                    $sim = $v ? self::cosine($qv[0], $v) : 0;
                    $final[$r['id']] = 0.45 * ($bm[$r['id']] / $max) + 0.55 * max(0, $sim);
                }
            }
        }
        arsort($final);
        $exclude = array_flip($opt['exclude'] ?? []);
        $top = [];
        foreach ($final as $id => $score) {
            if (isset($exclude[$id])) {
                continue;
            }
            if ($score <= 0 && empty($opt['allow_zero'])) {
                break;
            }
            $top[$id] = $score;
            if (count($top) >= $k) {
                break;
            }
        }
        return self::hydrate(array_keys($top), $top);
    }

    public static function hydrate(array $ids, array $scores = []): array
    {
        if (!$ids) {
            return [];
        }
        $rows = DB::all('SELECT c.id, c.source_id, c.subject_id, c.label, c.content, c.position, s.title AS source_title, s.kind AS source_kind
            FROM chunks c JOIN sources s ON s.id = c.source_id WHERE c.id IN (' . DB::in($ids) . ')', array_values($ids));
        $byId = [];
        foreach ($rows as $r) {
            $r['score'] = round((float) ($scores[$r['id']] ?? 0), 4);
            $byId[$r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    /** Random chunks of a subject (for variety in question generation). */
    public static function sample(int $subjectId, int $k, array $exclude = []): array
    {
        $ids = DB::col('SELECT id FROM chunks WHERE subject_id = ?', [$subjectId]);
        $ids = array_values(array_diff($ids, $exclude));
        shuffle($ids);
        return self::hydrate(array_slice($ids, 0, $k));
    }

    /** Formats chunks as a context block the model can cite with [C<id>]. */
    public static function context(array $chunks, int $maxChars = 24000): string
    {
        $out = '';
        foreach ($chunks as $c) {
            $head = '[C' . $c['id'] . '] (source: "' . $c['source_title'] . '"' . ($c['label'] ? ', ' . $c['label'] : '') . ')';
            $block = $head . "\n" . $c['content'] . "\n\n";
            if (mb_strlen($out) + mb_strlen($block) > $maxChars) {
                break;
            }
            $out .= $block;
        }
        return trim($out);
    }

    /** Turns ["C12", 12, "c13"] into validated chunk ids belonging to the user. */
    public static function refIds(mixed $refs, int $userId): array
    {
        $ids = [];
        foreach ((array) $refs as $r) {
            if (preg_match('/(\d+)/', (string) $r, $m)) {
                $ids[] = (int) $m[1];
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        return array_map('intval', DB::col('SELECT id FROM chunks WHERE user_id = ? AND id IN (' . DB::in($ids) . ')', array_merge([$userId], $ids)));
    }

    /** Citation metadata for display: id → {source_id, source_title, label, excerpt}. */
    public static function refMeta(array $ids, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = DB::all('SELECT c.id, c.source_id, c.label, c.content, s.title AS source_title FROM chunks c JOIN sources s ON s.id = c.source_id
            WHERE c.user_id = ? AND c.id IN (' . DB::in($ids) . ')', array_merge([$userId], $ids));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'source_id' => (int) $r['source_id'],
                'source_title' => $r['source_title'],
                'label' => $r['label'],
                'excerpt' => Support::excerpt($r['content'], 280),
            ];
        }
        return $out;
    }

    /** Normalises "[C1, C2]" / "[C1; c2]" / "(C3)" into "[C1][C2]" so the UI can render chips. */
    public static function normalizeCitations(string $text): string
    {
        // Repair malformed variants the models sometimes write: "[<C125]", "[C125>]", "[ C 125 ]", "【C125】", "<C125>".
        $text = preg_replace('/[\[【]\s*<?\s*[Cc]\s*(\d+)\s*>?\s*[\]】]/u', '[C$1]', $text) ?? $text;
        $text = preg_replace('/<\s*[Cc](\d+)\s*>?\]?/', '[C$1]', $text) ?? $text;
        $text = preg_replace_callback('/\[\s*([Cc]\d+(?:\s*[,;]\s*[Cc]?\d+)+)\s*\]/', static function ($m) {
            preg_match_all('/\d+/', $m[1], $n);
            return implode('', array_map(static fn ($i) => "[C$i]", $n[0]));
        }, $text) ?? $text;
        $text = preg_replace('/\(\s*C(\d+)\s*\)/', '[C$1]', $text) ?? $text;
        return preg_replace('/\[c(\d+)\]/', '[C$1]', $text) ?? $text;
    }

    /** Removes citations to chunk ids that don't belong to the user (hallucinated ids). */
    public static function scrubCitations(string $text, int $userId): string
    {
        $text = self::normalizeCitations($text);
        $valid = array_flip(self::refIds(array_map(static fn ($i) => "C$i", self::citedIds($text)), $userId));
        return preg_replace_callback('/\[C(\d+)\]/', static fn ($m) => isset($valid[(int) $m[1]]) ? $m[0] : '', $text) ?? $text;
    }

    /** Extracts [C123] citation ids from generated text. */
    public static function citedIds(string $text): array
    {
        preg_match_all('/\[C(\d+)\]/', $text, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }
}

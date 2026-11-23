<?php
declare(strict_types=1);

namespace App;

/**
 * Share a subject with a link. Anyone with the link can copy the finished subject (sources,
 * study guide, topics, flashcards and questions) into their own account. No AI requests are used,
 * progress is not copied, and every source citation is remapped to the copy's own text.
 */
final class Share
{
    public static function url(string $token): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8088'), '/') . '/#/share/' . $token;
    }

    /** Turns the share link on (new or existing token) or off. */
    public static function toggle(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('id'), $uid);
        $enable = (Http::input()['enable'] ?? true) !== false;
        if (!$enable) {
            DB::update('subjects', ['share_token' => null], 'id = ?', [$s['id']]);
            return ['shared' => false, 'url' => null];
        }
        if (!DB::val('SELECT COUNT(*) FROM topics WHERE subject_id = ? AND active = 1', [$s['id']])) {
            throw HttpError::bad('Analysér faget, før du deler det.');
        }
        $token = $s['share_token'] ?: bin2hex(random_bytes(12));
        DB::update('subjects', ['share_token' => $token], 'id = ?', [$s['id']]);
        return ['shared' => true, 'url' => self::url($token)];
    }

    public static function status(): array
    {
        $uid = Auth::id();
        $s = Subjects::owned(Http::int('id'), $uid);
        return ['shared' => (bool) $s['share_token'], 'url' => $s['share_token'] ? self::url($s['share_token']) : null];
    }

    private static function byToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            throw HttpError::bad('Linket er ugyldigt.');
        }
        $s = DB::one('SELECT * FROM subjects WHERE share_token = ?', [$token]);
        if (!$s) {
            throw new HttpError(404, 'Linket virker ikke længere. Ejeren har måske stoppet delingen.');
        }
        return $s;
    }

    /** Public preview of a shared subject (no login needed). */
    public static function info(): array
    {
        $s = self::byToken(Http::str('token', '', 64));
        $id = (int) $s['id'];
        return [
            'name' => $s['name'],
            'description' => $s['description'],
            'color' => $s['color'],
            'owner' => (string) DB::val('SELECT name FROM users WHERE id = ?', [$s['user_id']]),
            'topics' => DB::col('SELECT name FROM topics WHERE subject_id = ? AND active = 1 ORDER BY position', [$id]),
            'sources' => (int) DB::val('SELECT COUNT(*) FROM sources WHERE subject_id = ? AND parent_id IS NULL', [$id]),
            'sections' => (int) DB::val('SELECT COUNT(*) FROM guide_sections WHERE subject_id = ?', [$id]),
            'cards' => (int) DB::val('SELECT COUNT(*) FROM flashcards WHERE subject_id = ?', [$id]),
            'questions' => (int) DB::val('SELECT COUNT(*) FROM questions WHERE subject_id = ?', [$id]),
            'is_mine' => Auth::user() && (int) Auth::user()['id'] === (int) $s['user_id'],
        ];
    }

    /** Copies the shared subject into the signed-in user's account. */
    public static function copy(): array
    {
        $uid = Auth::id();
        $src = self::byToken(Http::str('token', '', 64));
        $srcId = (int) $src['id'];
        $now = time();
        $newId = DB::tx(static function () use ($src, $srcId, $uid, $now) {
            $newId = DB::insert('subjects', [
                'user_id' => $uid, 'name' => $src['name'], 'description' => $src['description'], 'color' => $src['color'],
                'status' => 'ready', 'analysis' => null, 'exam_date' => null, 'language' => $src['language'] ?? null,
                'copied_from' => $srcId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            // Sources (parents before children) with their files.
            $sourceMap = [];
            foreach (DB::all('SELECT * FROM sources WHERE subject_id = ? ORDER BY (parent_id IS NOT NULL), id', [$srcId]) as $s) {
                $meta = Support::decode($s['meta']);
                unset($meta['client_pages'], $meta['segments_file'], $meta['pending_ocr'], $meta['ocr_done']);
                $path = null;
                if ($s['storage_path'] && is_file(Sources::abs($s['storage_path']))) {
                    $ext = strtolower(pathinfo((string) $s['storage_path'], PATHINFO_EXTENSION)) ?: 'bin';
                    $path = Sources::storeBytes($uid, (string) file_get_contents(Sources::abs($s['storage_path'])), $ext);
                }
                $sourceMap[(int) $s['id']] = DB::insert('sources', [
                    'user_id' => $uid, 'subject_id' => $newId, 'parent_id' => $s['parent_id'] ? ($sourceMap[(int) $s['parent_id']] ?? null) : null,
                    'kind' => $s['kind'], 'title' => $s['title'], 'original_name' => $s['original_name'], 'mime' => $s['mime'],
                    'size_bytes' => $s['size_bytes'], 'storage_path' => $path, 'url' => $s['url'], 'status' => $s['status'],
                    'error' => $s['error'], 'char_count' => $s['char_count'], 'meta' => json_encode($meta), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Text chunks (keep a map so [C123] citations can be rewritten).
            $chunkMap = [];
            foreach (DB::all('SELECT * FROM chunks WHERE subject_id = ? ORDER BY id', [$srcId]) as $c) {
                if (!isset($sourceMap[(int) $c['source_id']])) {
                    continue;
                }
                $chunkMap[(int) $c['id']] = DB::insert('chunks', [
                    'user_id' => $uid, 'subject_id' => $newId, 'source_id' => $sourceMap[(int) $c['source_id']], 'position' => $c['position'],
                    'label' => $c['label'], 'content' => $c['content'], 'terms' => $c['terms'], 'term_count' => $c['term_count'], 'embedding' => $c['embedding'],
                ]);
            }
            $text = static fn (?string $t) => $t === null ? null : (preg_replace_callback('/\[C(\d+)\]/', static fn ($m) => isset($chunkMap[(int) $m[1]]) ? '[C' . $chunkMap[(int) $m[1]] . ']' : '', $t) ?? $t);
            $refs = static fn (?string $json) => json_encode(array_values(array_filter(array_map(
                static fn ($r) => isset($chunkMap[(int) substr((string) $r, 1)]) ? 'C' . $chunkMap[(int) substr((string) $r, 1)] : null,
                (array) Support::decode($json)))));

            // Topics.
            $topicMap = [];
            foreach (DB::all('SELECT * FROM topics WHERE subject_id = ? ORDER BY id', [$srcId]) as $t) {
                $topicMap[(int) $t['id']] = DB::insert('topics', [
                    'user_id' => $uid, 'subject_id' => $newId, 'name' => $t['name'], 'summary' => $t['summary'], 'subtopics' => $t['subtopics'],
                    'importance' => $t['importance'], 'position' => $t['position'], 'active' => $t['active'], 'created_at' => $now,
                ]);
            }
            $topic = static fn ($id) => $id ? ($topicMap[(int) $id] ?? null) : null;

            foreach (DB::all('SELECT * FROM guide_sections WHERE subject_id = ?', [$srcId]) as $g) {
                DB::insert('guide_sections', [
                    'user_id' => $uid, 'subject_id' => $newId, 'topic_id' => $topic($g['topic_id']), 'title' => $g['title'],
                    'content' => $text($g['content']), 'position' => $g['position'], 'edited' => $g['edited'], 'updated_at' => $now,
                ]);
            }
            foreach (DB::all('SELECT * FROM flashcards WHERE subject_id = ?', [$srcId]) as $f) {
                DB::insert('flashcards', [
                    'user_id' => $uid, 'subject_id' => $newId, 'topic_id' => $topic($f['topic_id']), 'front' => $f['front'], 'back' => $f['back'],
                    'refs' => $refs($f['refs']), 'hash' => $f['hash'], 'created_at' => $now,
                ]);
            }
            foreach (DB::all('SELECT * FROM questions WHERE subject_id = ?', [$srcId]) as $q) {
                DB::insert('questions', [
                    'user_id' => $uid, 'subject_id' => $newId, 'topic_id' => $topic($q['topic_id']), 'type' => $q['type'], 'difficulty' => $q['difficulty'],
                    'prompt' => $q['prompt'], 'options' => $q['options'], 'answer' => $q['answer'], 'accept' => $q['accept'],
                    'explanation' => $text($q['explanation']), 'option_notes' => $q['option_notes'], 'refs' => $refs($q['refs']),
                    'hash' => $q['hash'], 'created_at' => $now,
                ]);
            }

            // Analysis: rewrite "C123" citations and topic ids.
            $analysis = Support::decode($src['analysis'], null);
            if (is_array($analysis)) {
                $walk = static function ($v, $k = null) use (&$walk, $chunkMap, $topicMap) {
                    if (is_array($v)) {
                        $out = [];
                        foreach ($v as $kk => $vv) {
                            $out[$kk] = $walk($vv, $kk);
                        }
                        return $out;
                    }
                    if (is_string($v) && preg_match('/^C(\d+)$/', $v, $m)) {
                        return isset($chunkMap[(int) $m[1]]) ? 'C' . $chunkMap[(int) $m[1]] : $v;
                    }
                    if (($k === 'topic_id' || $k === 'id') && is_int($v)) {
                        return $topicMap[$v] ?? $v;
                    }
                    return $v;
                };
                DB::update('subjects', ['analysis' => json_encode($walk($analysis), JSON_UNESCAPED_UNICODE)], 'id = ?', [$newId]);
            }
            return $newId;
        });
        Quiz::ensurePool($newId, $uid);
        return ['subject_id' => $newId];
    }
}

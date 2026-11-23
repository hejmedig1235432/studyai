<?php
declare(strict_types=1);

namespace App;

use App\Extract\Office;
use App\Extract\Pdf;

/**
 * Opgavefeedback: the student pastes (or uploads) their own assignment text plus the assessment
 * criteria, and gets a score and concrete feedback per criterion. Quotes are checked against the
 * student's text so the feedback never refers to sentences that aren't there.
 */
final class Essay
{
    public const MAX_CHARS = 40000;
    private const SCALES = ['7' => '7-trinsskalaen', 'points' => 'point', 'none' => 'kun feedback'];
    private const GRADES = ['-3', '00', '02', '4', '7', '10', '12'];

    private const SYSTEM = "You are an experienced, fair teacher giving written feedback on a student's own assignment (essay, analysis, report, stil, opgave).\n"
        . "Assess the text ONLY against the given criteria and assignment. Be honest but encouraging, and specific: every point must refer to something concrete in the text.\n"
        . "RULES:\n"
        . "- Quotes in \"evidence\" and \"language_errors\" must be copied EXACTLY, character for character, from the student's text (max ~25 words each). Never invent or paraphrase quotes.\n"
        . "- Scores per criterion are 1-10 (10 = excellent). Calibrate them realistically for the student's level; don't give everything 7-8.\n"
        . "- Improvements must be actionable (what to do and where), not generic advice. Do not rewrite the whole assignment; an example rewrite of ONE sentence or short passage is fine.\n"
        . "- language_errors: real spelling, grammar, punctuation or word-choice mistakes only (max 15, most important first), each with the corrected version.\n"
        . "- If course-material excerpts are provided, check the text's factual claims against them and cite like \"C12\" in refs. Never cite ids that were not provided. If no excerpts are given, leave fact_checks empty.\n"
        . "- If the text is not a school assignment at all, say so in summary and give low scores.";

    // ------------------------------------------------------------------ API

    public static function list(): array
    {
        $uid = Auth::id();
        $rows = DB::all('SELECT e.id, e.title, e.subject_id, e.scale, e.score, e.grade, e.parent_id, e.created_at, s.name AS subject_name, s.color AS subject_color
            FROM essay_reviews e LEFT JOIN subjects s ON s.id = e.subject_id WHERE e.user_id = ? ORDER BY e.id DESC LIMIT 100', [$uid]);
        return ['reviews' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'title' => $r['title'], 'subject_id' => $r['subject_id'] ? (int) $r['subject_id'] : null,
            'subject_name' => $r['subject_name'], 'subject_color' => $r['subject_color'], 'scale' => $r['scale'],
            'score' => $r['score'] !== null ? (int) $r['score'] : null, 'grade' => $r['grade'], 'parent_id' => $r['parent_id'] ? (int) $r['parent_id'] : null,
            'created_at' => (int) $r['created_at'],
        ], $rows)];
    }

    public static function get(): array
    {
        $uid = Auth::id();
        $r = self::owned(Http::int('id'), $uid);
        return ['review' => self::present($r, $uid)];
    }

    public static function delete(): array
    {
        $uid = Auth::id();
        $r = self::owned(Http::int('id'), $uid);
        DB::exec('UPDATE essay_reviews SET parent_id = NULL WHERE parent_id = ? AND user_id = ?', [$r['id'], $uid]);
        DB::exec('DELETE FROM essay_reviews WHERE id = ? AND user_id = ?', [$r['id'], $uid]);
        return ['ok' => true];
    }

    /** Reads the text out of an uploaded .docx / .pdf / .txt so the student doesn't have to copy-paste. */
    public static function extract(): array
    {
        Auth::id();
        $f = $_FILES['file'] ?? null;
        if (!$f || is_array($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
            throw HttpError::bad('Filen kunne ikke modtages. Prøv igen, eller indsæt teksten direkte.');
        }
        $name = Support::excerpt(basename(str_replace('\\', '/', (string) $f['name'])), 180);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'pdf', 'txt', 'md'], true)) {
            throw HttpError::bad('Upload din opgave som Word (.docx), PDF eller tekstfil – eller indsæt teksten direkte.');
        }
        if ($f['size'] > 20 * 1048576 || !Sources::sniff($f['tmp_name'], $ext)) {
            throw HttpError::bad("“$name” kunne ikke læses (for stor eller beskadiget).");
        }
        $segments = match ($ext) {
            'docx' => Office::docx($f['tmp_name']),
            'pdf' => Pdf::extract($f['tmp_name']),
            default => [['label' => '', 'text' => Support::utf8((string) file_get_contents($f['tmp_name']))]],
        };
        $text = trim(implode("\n\n", array_map(static fn ($s) => trim((string) $s['text']), $segments)));
        if (mb_strlen($text) < 30) {
            throw HttpError::bad('Der blev ikke fundet tekst i filen. Er det en scannet PDF? Så indsæt teksten direkte.');
        }
        return ['text' => mb_substr($text, 0, self::MAX_CHARS), 'title' => pathinfo($name, PATHINFO_FILENAME), 'truncated' => mb_strlen($text) > self::MAX_CHARS];
    }

    /** Runs the assessment and saves it. */
    public static function review(): array
    {
        $p = self::prepare();
        $raw = AI::json($p['system'], $p['user'], ['temperature' => 0.2, 'max_tokens' => 5000, 'timeout' => 180]);
        return self::store($p, $raw);
    }

    /**
     * Same as review(), but streams live progress (Server-Sent Events) while the AI writes, so the
     * student sees which part is being assessed instead of a frozen page.
     */
    public static function reviewStream(): never
    {
        $p = self::prepare(); // validation errors are still normal JSON errors
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
        $emit = static function (string $event, array $data): void {
            echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            @flush();
        };
        $emit('progress', ['section' => 'start', 'chars' => 0]);
        // The order the AI writes the JSON in: the last key seen tells which part it is working on.
        $sections = ['"summary"' => 'summary', '"criteria"' => 'criteria', '"requirements"' => 'requirements', '"strengths"' => 'strengths',
            '"next_steps"' => 'next_steps', '"language_errors"' => 'language_errors', '"fact_checks"' => 'fact_checks', '"example_rewrite"' => 'example_rewrite'];
        $text = '';
        $sent = 0;
        $current = 'start';
        try {
            $system = $p['system'] . "\n\nRespond with a single valid JSON object only. No markdown fences, no commentary.";
            $out = AI::chatStream([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $p['user']]],
                ['temperature' => 0.2, 'max_tokens' => 5000, 'json' => true],
                static function (string $d) use (&$text, &$sent, &$current, $sections, $emit): void {
                    $text .= $d;
                    $section = $current;
                    $best = -1;
                    foreach ($sections as $needle => $name) {
                        $pos = strrpos($text, $needle);
                        if ($pos !== false && $pos > $best) {
                            $best = $pos;
                            $section = $name;
                        }
                    }
                    if ($section !== $current || strlen($text) - $sent > 400) {
                        $current = $section;
                        $sent = strlen($text);
                        $emit('progress', ['section' => $section, 'chars' => mb_strlen($text)]);
                    }
                });
            $raw = $out !== null ? AI::parseJson($out) : null;
            if ($raw === null) {
                // Streaming wasn't possible or the answer was cut off: the normal way (waits for rate limits, retries).
                $emit('progress', ['section' => 'retry', 'chars' => 0]);
                $raw = AI::json($p['system'], $p['user'], ['temperature' => 0.2, 'max_tokens' => 5000, 'timeout' => 180]);
            }
            $emit('done', self::store($p, $raw));
        } catch (\Throwable $e) {
            if (!$e instanceof HttpError) {
                Support::log('Essay stream error: ' . $e->getMessage());
            }
            $emit('error', ['message' => $e instanceof HttpError ? $e->getMessage() : 'Der gik noget galt. Prøv igen.', 'code' => $e instanceof HttpError ? $e->code_ : null]);
        }
        exit;
    }

    /** Validates the input and builds the prompt. */
    private static function prepare(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        Auth::release();
        @set_time_limit(300);

        $essay = trim(str_replace("\r\n", "\n", Http::str('essay', '', self::MAX_CHARS + 1000)));
        $assignment = trim(Http::str('assignment', '', 8000));
        $criteria = array_values(array_filter(array_map(static fn ($c) => Support::excerpt(trim((string) $c), 300),
            Http::arr('criteria') ?: preg_split('/\R/', Http::str('criteria', '', 6000))), static fn ($c) => $c !== ''));
        $criteria = array_slice(array_values(array_unique($criteria)), 0, 12);
        // The assignment's requirements, one per line (bullets/numbers stripped). Each one is checked.
        $requirements = array_values(array_unique(array_filter(array_map(
            static fn ($c) => Support::excerpt(trim((string) preg_replace('/^\s*(?:[-•*·]|\d+[.)])\s*/u', '', (string) $c)), 300),
            Http::arr('requirements') ?: preg_split('/\R/', Http::str('requirements', '', 6000))), static fn ($c) => $c !== '')));
        $requirements = array_slice($requirements, 0, 20);
        $scale = isset(self::SCALES[Http::str('scale')]) ? Http::str('scale') : '7';
        $title = Http::str('title', '', 160) ?: Support::excerpt(strtok($essay, "\n") ?: 'Opgave', 80);
        $parentId = Http::int('parent_id') ?: null;
        $subjectId = Http::int('subject_id') ?: null;

        if (mb_strlen($essay) < 150) {
            throw HttpError::bad('Indsæt hele din opgave (mindst et par afsnit), så feedbacken bliver brugbar.');
        }
        if (mb_strlen($essay) > self::MAX_CHARS) {
            throw HttpError::bad('Opgaven er for lang (maks. ' . number_format(self::MAX_CHARS, 0, ',', '.') . ' tegn). Del den op, og få feedback på én del ad gangen.');
        }
        if (!$criteria) {
            throw HttpError::bad('Skriv mindst ét bedømmelseskriterie – eller vælg en af skabelonerne.');
        }
        if ($parentId) {
            self::owned($parentId, $uid);
        }
        Security::inspect(array_merge([$title, $assignment], $criteria, $requirements), 'Opgavefeedback');
        Security::inspect([$essay], 'Opgavefeedback', true);
        $subject = $subjectId ? Subjects::owned($subjectId, $uid) : null;
        Lang::forSubject($subject ?? ['user_id' => $uid, 'language' => ''], Http::str('language') ?: null);

        // Course material for fact-checking (optional).
        $chunks = [];
        if ($subject) {
            $chunks = Retriever::search($uid, (int) $subject['id'], Support::excerpt($assignment . ' ' . $title . ' ' . $essay, 1500), 8);
        }
        $context = $chunks ? Retriever::context($chunks, 9000) : '';

        $scaleText = match ($scale) {
            '7' => 'Also estimate the grade on the Danish 7-point scale (one of: ' . implode(', ', self::GRADES) . ') in "grade", with a one-sentence reason in "grade_note". '
                . 'What the grades mean: 12 = excellent, a flawless or nearly flawless performance (no or only insignificant shortcomings); '
                . '10 = very good, a high level with only a few minor shortcomings; 7 = good, a solid performance with a number of shortcomings; '
                . '4 = fair ("okay"), acceptable but with several significant shortcomings; 02 = adequate, just the minimum acceptable to pass; '
                . '00 = inadequate, FAIL; -3 = completely unacceptable, FAIL. Requirements that are not met (e.g. missing length, missing parts) must pull the grade down.',
            'points' => 'Do not give a grade; set "grade" to null.',
            default => 'Do not give a grade; set "grade" to null. Focus on the written feedback.',
        };
        $user = ($assignment !== '' ? "ASSIGNMENT / TASK DESCRIPTION:\n$assignment\n\n" : "ASSIGNMENT: (not provided – judge it as the kind of text it appears to be)\n\n")
            . "CRITERIA (assess each one, in this order, using these exact names):\n" . implode("\n", array_map(static fn ($c, $i) => ($i + 1) . '. ' . $c, $criteria, array_keys($criteria))) . "\n\n"
            . ($requirements ? "REQUIREMENTS FOR THE TEXT (check EVERY one, in this order, using the exact wording in \"requirement\"; in \"missing\" say exactly what the student must add or change to meet it, or \"\" if met):\n"
                . implode("\n", array_map(static fn ($c, $i) => ($i + 1) . '. ' . $c, $requirements, array_keys($requirements))) . "\n"
                . "(Word count of the text: " . self::wordCount($essay) . ' words, ' . mb_strlen($essay) . " characters incl. spaces; 1 normalside = 2400 characters.)\n\n" : '')
            . ($context !== '' ? "COURSE MATERIAL EXCERPTS (for fact-checking):\n$context\n\n" : '')
            . "STUDENT'S TEXT:\n\"\"\"\n$essay\n\"\"\"\n\n"
            . "$scaleText\n\nReturn JSON:\n"
            . '{"summary":"3-4 sentences overall assessment","overall_score":0-100,"grade":"7"|null,"grade_note":"",'
            . '"criteria":[{"name":"","score":1-10,"assessment":"2-4 sentences","evidence":["exact quote"],"improvements":["concrete action"]}],'
            . '"requirements":[{"requirement":"","met":"yes"|"partly"|"no","note":"how the text does on this, with a short quote if relevant","missing":"what exactly is missing"}],'
            . '"strengths":["..."],"next_steps":["the 3 most important things to fix, most important first"],'
            . '"language_errors":[{"quote":"exact text","correction":"","explanation":""}],'
            . '"fact_checks":[{"claim":"","verdict":"supported"|"contradicted"|"not_in_material","note":"","refs":["C12"]}],'
            . '"example_rewrite":{"original":"exact sentence/short passage","improved":"","why":""}}' . "\n"
            . ($requirements ? 'In "requirements", list the given requirements first; you may add up to 3 other important requirements from the assignment description after them.'
                : 'In "requirements", list the concrete requirements from the assignment description; leave it empty if no assignment was given.');

        return compact('uid', 'subject', 'parentId', 'title', 'assignment', 'criteria', 'requirements', 'scale', 'essay', 'chunks', 'user')
            + ['system' => Tutor::identity() . "\n\n" . self::SYSTEM];
    }

    /** Cleans the AI's assessment and saves it. */
    private static function store(array $p, array $raw): array
    {
        extract($p);
        $result = self::clean($raw, $essay, $criteria, $scale, array_map(static fn ($c) => (int) $c['id'], $chunks), $uid, $requirements);

        $id = DB::insert('essay_reviews', [
            'user_id' => $uid,
            'subject_id' => $subject ? (int) $subject['id'] : null,
            'parent_id' => $parentId,
            'title' => $title,
            'assignment' => $assignment,
            'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE),
            'requirements' => json_encode($requirements, JSON_UNESCAPED_UNICODE),
            'scale' => $scale,
            'language' => Lang::code(),
            'essay' => $essay,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'score' => $result['overall_score'],
            'grade' => $result['grade'],
            'created_at' => time(),
        ]);
        return ['review' => self::present(self::owned($id, $uid), $uid)];
    }

    // ------------------------------------------------------------------ helpers

    private static function owned(int $id, int $uid): array
    {
        $r = DB::one('SELECT * FROM essay_reviews WHERE id = ? AND user_id = ?', [$id, $uid]);
        if (!$r) {
            throw new HttpError(404, 'Feedbacken findes ikke længere.');
        }
        return $r;
    }

    private static function present(array $r, int $uid): array
    {
        $result = Support::decode((string) $r['result'], []);
        $refIds = [];
        foreach ((array) ($result['fact_checks'] ?? []) as $f) {
            foreach ((array) ($f['refs'] ?? []) as $ref) {
                $refIds[] = (int) $ref;
            }
        }
        $parent = $r['parent_id'] ? DB::one('SELECT id, score, grade, created_at, result FROM essay_reviews WHERE id = ? AND user_id = ?', [$r['parent_id'], $uid]) : null;
        $subject = $r['subject_id'] ? DB::one('SELECT id, name, color FROM subjects WHERE id = ? AND user_id = ?', [$r['subject_id'], $uid]) : null;
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'subject' => $subject ? ['id' => (int) $subject['id'], 'name' => $subject['name'], 'color' => $subject['color']] : null,
            'assignment' => $r['assignment'],
            'criteria' => Support::decode((string) $r['criteria'], []),
            'requirements' => Support::decode((string) ($r['requirements'] ?? ''), []),
            'scale' => $r['scale'],
            'language' => $r['language'],
            'essay' => $r['essay'],
            'result' => $result,
            'created_at' => (int) $r['created_at'],
            'refs' => (object) Retriever::refMeta($refIds, $uid),
            'parent' => $parent ? [
                'id' => (int) $parent['id'], 'score' => $parent['score'] !== null ? (int) $parent['score'] : null, 'grade' => $parent['grade'],
                'created_at' => (int) $parent['created_at'],
                'criteria' => array_map(static fn ($c) => ['name' => $c['name'] ?? '', 'score' => $c['score'] ?? null], (array) (Support::decode((string) $parent['result'], [])['criteria'] ?? [])),
            ] : null,
        ];
    }

    /** Is $quote really in the student's text? (whitespace/quote-style tolerant) */
    private static function inText(string $quote, string $essayNorm): bool
    {
        $q = self::norm($quote);
        if (mb_strlen($q) < 3) {
            return false;
        }
        if (str_contains($essayNorm, $q)) {
            return true;
        }
        // Allow the model to have trimmed a few characters at either end.
        return mb_strlen($q) > 24 && str_contains($essayNorm, mb_substr($q, 4, -4));
    }

    private static function norm(string $s): string
    {
        $s = str_replace(['“', '”', '„', '«', '»', '‘', '’', '–', '—', '…'], ['"', '"', '"', '"', '"', "'", "'", '-', '-', '...'], $s);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    private static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Length requirements ("min. 800 ord", "ca. 1 side", "3-5 normalsider", "maks 4.800 tegn") are counted
     * exactly instead of trusting the AI. Returns null when the requirement isn't about length.
     */
    private static function lengthCheck(string $req, string $essay): ?array
    {
        $r = mb_strtolower($req);
        $numRe = '(\d{1,3}(?:\.\d{3})+|\d+(?:[.,]\d+)?)';
        if (!preg_match('/' . $numRe . '\s*(?:(?:-|–|til|og)\s*' . $numRe . ')?\s*(ord|words?|tegn|anslag|characters?|normalsider|normalside|sider|side|pages?)\b/u', $r, $m)) {
            return null;
        }
        $num = static fn (string $s) => (float) str_replace(',', '.', (string) preg_replace('/\.(?=\d{3}(?:\D|$))/', '', $s));
        $a = $num($m[1]);
        $b = ($m[2] ?? '') !== '' ? $num($m[2]) : null;
        $unit = $m[3];
        $chars = mb_strlen($essay);
        if (str_starts_with($unit, 'ord') || str_starts_with($unit, 'word')) {
            [$have, $label, $per] = [self::wordCount($essay), 'ord', 1];
        } elseif (in_array($unit, ['tegn', 'anslag'], true) || str_starts_with($unit, 'char')) {
            [$have, $label, $per] = [$chars, 'tegn', 1];
        } else {
            [$have, $label, $per] = [round($chars / 2400, 1), 'normalsider', 2400];
        }
        if ($b !== null) {
            [$min, $max] = [min($a, $b), max($a, $b)];
        } elseif (preg_match('/\b(min|mindst|minimum|at least|ikke under)\b/u', $r)) {
            [$min, $max] = [$a, null];
        } elseif (preg_match('/\b(maks|max|maksimum|maksimalt|højst|at most|ikke over)\b/u', $r)) {
            [$min, $max] = [null, $a];
        } else {
            [$min, $max] = [$a * 0.85, $a * 1.15]; // "ca. 1 side" / "800 ord": allow ±15 %
        }
        $fmt = static fn (float $v) => $label === 'normalsider' ? number_format($v, 1, ',', '.') : number_format($v, 0, ',', '.');
        $haveTxt = $label === 'normalsider' ? $fmt($have) . ' normalsider (' . number_format($chars, 0, ',', '.') . ' tegn)' : $fmt($have) . ' ' . $label;
        $missing = '';
        if ($min !== null && $have < $min) {
            $short = ($min - $have) * $per;
            $met = $have >= $min * 0.9 ? 'partly' : 'no';
            $missing = $label === 'normalsider'
                ? 'Du mangler ca. ' . number_format($short, 0, ',', '.') . ' tegn (1 normalside = 2.400 tegn med mellemrum).'
                : 'Du mangler ca. ' . number_format(ceil($short), 0, ',', '.') . ' ' . $label . '.';
        } elseif ($max !== null && $have > $max) {
            $over = ($have - $max) * $per;
            $met = $have <= $max * 1.1 ? 'partly' : 'no';
            $missing = 'Teksten er ca. ' . number_format(ceil($over), 0, ',', '.') . ' ' . ($label === 'normalsider' ? 'tegn' : $label) . ' for lang – skær ned.';
        } else {
            $met = 'yes';
        }
        return ['met' => $met, 'note' => 'Din tekst har ' . $haveTxt . '. Talt automatisk.', 'missing' => $missing, 'auto' => true];
    }

    private static function clean(array $d, string $essay, array $criteria, string $scale, array $chunkIds, int $uid, array $requirements = []): array
    {
        $essayNorm = self::norm($essay);
        $str = static fn ($v, int $max = 1200) => Support::excerpt(trim((string) (is_scalar($v) ? $v : '')), $max);
        $list = static fn ($v, int $n, int $max = 400) => array_values(array_filter(array_map(static fn ($x) => Support::excerpt(trim((string) (is_scalar($x) ? $x : '')), $max), array_slice((array) $v, 0, $n)), static fn ($x) => $x !== ''));

        // Criteria: keep the student's order and names; match the AI's entries by position or name.
        $byName = [];
        foreach ((array) ($d['criteria'] ?? []) as $i => $c) {
            if (is_array($c)) {
                $byName[self::norm((string) ($c['name'] ?? ''))] = $c;
                $byName['#' . $i] = $c;
            }
        }
        $outCriteria = [];
        foreach ($criteria as $i => $name) {
            $c = $byName[self::norm($name)] ?? $byName['#' . $i] ?? null;
            if (!$c) {
                continue;
            }
            $outCriteria[] = [
                'name' => $name,
                'score' => max(1, min(10, (int) round((float) ($c['score'] ?? 5)))),
                'assessment' => $str($c['assessment'] ?? ''),
                'evidence' => array_values(array_filter($list($c['evidence'] ?? [], 3, 300), static fn ($q) => self::inText($q, $essayNorm))),
                'improvements' => $list($c['improvements'] ?? [], 4),
            ];
        }
        if (!$outCriteria) {
            throw new HttpError(502, 'AI’en gav ikke en brugbar bedømmelse. Prøv igen.');
        }

        $avg = array_sum(array_column($outCriteria, 'score')) / count($outCriteria);
        $overall = isset($d['overall_score']) && is_numeric($d['overall_score']) ? (int) round((float) $d['overall_score']) : (int) round($avg * 10);
        // Keep the overall score consistent with the criteria (the model sometimes drifts).
        $overall = max(0, min(100, abs($overall - $avg * 10) > 20 ? (int) round($avg * 10) : $overall));

        $grade = null;
        if ($scale === '7') {
            $g = trim((string) ($d['grade'] ?? ''));
            $g = $g === '0' ? '00' : ($g === '2' ? '02' : $g);
            $grade = in_array($g, self::GRADES, true) ? $g : null;
        }

        $errors = [];
        foreach (array_slice((array) ($d['language_errors'] ?? []), 0, 20) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $q = $str($e['quote'] ?? '', 300);
            $fix = $str($e['correction'] ?? '', 300);
            if ($q === '' || $fix === '' || self::norm($q) === self::norm($fix) || !self::inText($q, $essayNorm)) {
                continue; // drop invented or no-op "errors"
            }
            $errors[] = ['quote' => $q, 'correction' => $fix, 'explanation' => $str($e['explanation'] ?? '', 300)];
        }

        $allowed = array_flip($chunkIds);
        $facts = [];
        foreach (array_slice((array) ($d['fact_checks'] ?? []), 0, 8) as $f) {
            if (!is_array($f) || !$chunkIds) {
                continue;
            }
            $verdict = in_array($f['verdict'] ?? '', ['supported', 'contradicted', 'not_in_material'], true) ? $f['verdict'] : 'not_in_material';
            $refs = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) preg_replace('/\D/', '', (string) $r), (array) ($f['refs'] ?? [])), static fn ($id) => isset($allowed[$id]))));
            $facts[] = ['claim' => $str($f['claim'] ?? '', 300), 'verdict' => $verdict, 'note' => $str($f['note'] ?? '', 400), 'refs' => $refs];
        }

        // Requirements: the student's own list first (in their order and wording), then extras from the AI.
        $aiReqs = [];
        foreach (array_slice((array) ($d['requirements'] ?? []), 0, 25) as $i => $r) {
            if (is_array($r) && trim((string) ($r['requirement'] ?? '')) !== '') {
                $aiReqs[] = [
                    'requirement' => $str($r['requirement'], 300),
                    'met' => in_array($r['met'] ?? '', ['yes', 'partly', 'no'], true) ? $r['met'] : 'partly',
                    'note' => $str($r['note'] ?? '', 400),
                    'missing' => $str($r['missing'] ?? '', 400),
                ];
            }
        }
        $reqs = [];
        $used = [];
        foreach ($requirements as $i => $req) {
            $match = null;
            foreach ($aiReqs as $j => $ar) {
                if (!isset($used[$j]) && self::norm($ar['requirement']) === self::norm($req)) {
                    $match = $j;
                    break;
                }
            }
            if ($match === null && isset($aiReqs[$i]) && !isset($used[$i])) {
                $match = $i; // same position
            }
            $entry = $match !== null ? $aiReqs[$match] : ['met' => 'partly', 'note' => 'Kunne ikke vurderes automatisk – tjek selv.', 'missing' => ''];
            if ($match !== null) {
                $used[$match] = true;
            }
            $entry['requirement'] = $req;
            $entry['given'] = true;
            if ($len = self::lengthCheck($req, $essay)) {
                $entry = array_merge($entry, $len); // counted exactly, overrides the AI
            }
            if ($entry['met'] === 'yes') {
                $entry['missing'] = '';
            }
            $reqs[] = $entry;
        }
        $extra = 0;
        foreach ($aiReqs as $j => $ar) {
            if (isset($used[$j]) || ($requirements && $extra >= 3) || count($reqs) >= 15) {
                continue;
            }
            if ($len = self::lengthCheck($ar['requirement'], $essay)) {
                $ar = array_merge($ar, $len);
            }
            if ($ar['met'] === 'yes') {
                $ar['missing'] = '';
            }
            $reqs[] = $ar;
            $extra++;
        }

        $rewrite = null;
        if (is_array($d['example_rewrite'] ?? null) && self::inText((string) ($d['example_rewrite']['original'] ?? ''), $essayNorm) && trim((string) ($d['example_rewrite']['improved'] ?? '')) !== '') {
            $rewrite = ['original' => $str($d['example_rewrite']['original'], 800), 'improved' => $str($d['example_rewrite']['improved'], 1000), 'why' => $str($d['example_rewrite']['why'] ?? '', 400)];
        }

        return [
            'summary' => $str($d['summary'] ?? ''),
            'overall_score' => $overall,
            'grade' => $grade,
            'grade_note' => $grade ? $str($d['grade_note'] ?? '', 400) : '',
            'criteria' => $outCriteria,
            'requirements' => $reqs,
            'strengths' => $list($d['strengths'] ?? [], 6),
            'next_steps' => $list($d['next_steps'] ?? [], 3),
            'language_errors' => $errors,
            'fact_checks' => $facts,
            'example_rewrite' => $rewrite,
            'stats' => ['words' => count(preg_split('/\s+/u', trim($essay)) ?: []), 'chars' => mb_strlen($essay)],
        ];
    }
}

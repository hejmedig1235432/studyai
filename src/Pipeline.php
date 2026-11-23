<?php
declare(strict_types=1);

namespace App;

use App\Extract\Office;
use App\Extract\Pdf;
use App\Extract\Web;
use App\Extract\YouTube;
use ZipArchive;

/**
 * Background processing for a subject, executed one short step at a time so it works
 * without a queue server: the browser calls jobs.run repeatedly while it is open, and
 * `php bin/worker.php` can run the same steps from the command line / cron.
 *
 * Stages: extract (each source, incl. ZIP expansion + OCR) → map (analyse chunk batches)
 *         → reduce (merge into one analysis + topic list) → topics (study guide + flashcards per topic) → done
 */
final class Pipeline
{
    public static function start(int $subjectId, int $uid, bool $full = false): array
    {
        $job = DB::one("SELECT * FROM jobs WHERE subject_id = ? AND type = 'analyze' AND status IN ('pending','running','failed') ORDER BY id DESC LIMIT 1", [$subjectId]);
        $hasQueued = (int) DB::val("SELECT COUNT(*) FROM sources WHERE subject_id = ? AND status IN ('queued','processing','ocr')", [$subjectId]) > 0;
        if ($job) {
            $data = ['status' => 'pending', 'error' => null, 'attempts' => 0, 'updated_at' => time()];
            if ($hasQueued && $job['stage'] !== 'extract') {
                $data['stage'] = 'extract';
                $data['payload'] = '{}';
            }
            DB::update('jobs', $data, 'id = ?', [$job['id']]);
        } else {
            $id = DB::insert('jobs', [
                'user_id' => $uid,
                'subject_id' => $subjectId,
                'type' => 'analyze',
                'status' => 'pending',
                'stage' => 'extract',
                'progress' => 0,
                'message' => 'I kø',
                'payload' => json_encode(['full' => $full]),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $job = ['id' => $id];
        }
        DB::update('subjects', ['status' => 'processing', 'updated_at' => time()], 'id = ?', [$subjectId]);
        return self::present(DB::one('SELECT * FROM jobs WHERE id = ?', [$job['id']]));
    }

    public static function present(?array $j): ?array
    {
        if (!$j) {
            return null;
        }
        return [
            'id' => (int) $j['id'],
            'subject_id' => (int) $j['subject_id'],
            'status' => $j['status'],
            'stage' => $j['stage'],
            'progress' => (int) $j['progress'],
            'message' => $j['message'],
            'error' => $j['error'],
        ];
    }

    /** API: run one step of a job owned by the current user. */
    public static function runApi(): array
    {
        $uid = Auth::id();
        Auth::release();
        $job = DB::one('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$job) {
            throw HttpError::notFound('Job');
        }
        if (in_array($job['status'], ['pending', 'running'], true)) {
            if ((int) Support::setting('worker_heartbeat', 0) > time() - 20) {
                // A background worker is processing it — just report progress (the browser waits between polls).
            } else {
                self::step((int) $job['id']);
            }
        }
        $job = DB::one('SELECT * FROM jobs WHERE id = ?', [$job['id']]);
        if (!$job) {
            throw HttpError::notFound('Job');
        }
        $sources = DB::all('SELECT * FROM sources WHERE subject_id = ? ORDER BY id', [$job['subject_id']]);
        return ['job' => self::present($job), 'sources' => array_map([Sources::class, 'present'], $sources)];
    }

    public static function statusApi(): array
    {
        $uid = Auth::id();
        $job = DB::one('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$job) {
            throw HttpError::notFound('Job');
        }
        return ['job' => self::present($job)];
    }

    /** CLI worker: steps the oldest runnable job. Returns false when idle. */
    public static function workerTick(): bool
    {
        $job = DB::one("SELECT id FROM jobs WHERE status IN ('pending','running') AND locked_until < ? ORDER BY updated_at LIMIT 1", [time()]);
        if (!$job) {
            return false;
        }
        self::step((int) $job['id']);
        return true;
    }

    private static function update(int $id, array $data): void
    {
        $data['updated_at'] = time();
        DB::update('jobs', $data, 'id = ?', [$id]);
    }

    public static function step(int $jobId): void
    {
        // Atomic lock so the browser and worker never run the same job concurrently.
        $got = DB::exec('UPDATE jobs SET locked_until = ?, status = ? WHERE id = ? AND locked_until < ?', [time() + 280, 'running', $jobId, time()]);
        if (!$got) {
            return;
        }
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(300); // web request; the background worker runs without a limit
        }
        ignore_user_abort(true);
        $job = DB::one('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        $payload = Support::decode($job['payload']);
        Lang::forSubjectId((int) $job['subject_id']);
        Usage::$userId = (int) $job['user_id'];
        try {
            match (in_array($job['type'], ['exam', 'prefill'], true) ? $job['type'] : $job['stage']) {
                'exam' => Quiz::examStep($job, $payload),
                'prefill' => self::stagePrefill($job, $payload),
                'extract' => self::stageExtract($job, $payload),
                'map' => self::stageMap($job, $payload),
                'reduce' => self::stageReduce($job, $payload),
                'topics' => self::stageTopics($job, $payload),
                default => self::finish($job),
            };
            DB::exec('UPDATE jobs SET locked_until = 0, attempts = 0 WHERE id = ?', [$jobId]);
        } catch (\Throwable $e) {
            if ($e instanceof HttpError && $e->code_ === 'daily_limit') {
                $reset = AI::dailyLimitResetAt() ?? time() + 3600;
                self::update($jobId, [
                    'locked_until' => $reset + 60, 'status' => 'running', 'error' => null,
                    'message' => 'Dagens gratis AI-grænse er nået – fortsætter automatisk kl. ' . date('H:i', $reset + 60),
                ]);
                return;
            }
            $attempts = (int) $job['attempts'] + 1;
            $msg = $e instanceof HttpError ? $e->getMessage() : 'Uventet fejl under behandlingen. Detaljerne står i storage/logs/app.log.';
            Support::log("Job $jobId stage {$job['stage']} failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Rate limits / busy models are temporary: keep waiting and retrying instead of giving up.
            $waiting = $e instanceof HttpError && in_array($e->code_, ['rate_limited', 'transient'], true);
            $fatal = ($waiting ? $attempts >= 30 : $attempts >= 3) || ($e instanceof HttpError && in_array($e->status, [503, 422], true) && $e->code_ === 'no_keys');
            self::update($jobId, [
                'attempts' => $attempts,
                'locked_until' => 0,
                'status' => $fatal ? 'failed' : 'running',
                'error' => $msg,
                'message' => $fatal ? 'Stoppet: ' . $msg : ($waiting ? 'Venter på at AI’en bliver ledig – prøver igen automatisk…' : 'Prøver igen efter en fejl…'),
            ]);
            if ($fatal && $job['type'] === 'analyze') {
                DB::update('subjects', ['status' => 'error', 'updated_at' => time()], 'id = ?', [$job['subject_id']]);
            } elseif ($fatal && $job['type'] === 'exam') {
                $p = Support::decode($job['payload']);
                DB::update('attempts', ['status' => 'abandoned'], 'id = ?', [(int) ($p['attempt_id'] ?? 0)]);
            }
        }
    }

    private static function finish(?array $job): void
    {
        if (!$job || !DB::val('SELECT id FROM jobs WHERE id = ?', [$job['id']])) {
            return; // subject (and its job) was deleted while processing
        }
        self::update((int) $job['id'], ['status' => 'done', 'stage' => 'done', 'progress' => 100, 'message' => 'Dit studieområde er klar', 'error' => null]);
        if ($job['type'] === 'analyze') {
            DB::update('subjects', ['status' => 'ready', 'updated_at' => time()], 'id = ?', [$job['subject_id']]);
            // While the student reads the study guide, prepare quiz questions in the background.
            Quiz::ensurePool((int) $job['subject_id'], (int) $job['user_id']);
        }
    }

    /** Background job: writes pool questions for several topics in parallel. */
    private static function stagePrefill(array $job, array $payload): void
    {
        $queue = array_map('intval', (array) ($payload['queue'] ?? []));
        $subject = DB::one('SELECT * FROM subjects WHERE id = ?', [$job['subject_id']]);
        if (!$queue || !$subject) {
            self::finish($job);
            return;
        }
        $batch = array_slice($queue, 0, self::parallelism());
        $results = self::parallel(array_map(static fn ($tid) => ['questions', $subject['id'], $tid], $batch));
        if ($results === null) {
            $batch = [$queue[0]];
            Quiz::prefillTopic($subject, $queue[0]);
        } else {
            self::failIfAllFailed($results);
        }
        $queue = array_values(array_diff($queue, $batch));
        $total = max(1, (int) ($payload['total'] ?? 1));
        $payload['queue'] = $queue;
        self::update((int) $job['id'], ['payload' => json_encode($payload), 'progress' => (int) (100 * ($total - count($queue)) / $total)]);
        if (!$queue) {
            self::finish(DB::one('SELECT * FROM jobs WHERE id = ?', [$job['id']]));
        }
    }

    // ------------------------------------------------------------------ extract

    private static function stageExtract(array $job, array $payload): void
    {
        $sid = (int) $job['subject_id'];
        $total = max(1, (int) DB::val('SELECT COUNT(*) FROM sources WHERE subject_id = ?', [$sid]));
        $src = DB::one("SELECT * FROM sources WHERE subject_id = ? AND status IN ('queued','processing','ocr') ORDER BY id LIMIT 1", [$sid]);
        if ($src) {
            self::update((int) $job['id'], ['message' => 'Læser “' . Support::excerpt($src['title'], 60) . '”']);
            try {
                self::extractSource($src);
            } catch (HttpError $e) {
                if ($e->code_ === 'no_keys') {
                    throw $e;
                }
                DB::update('sources', ['status' => 'error', 'error' => $e->getMessage(), 'updated_at' => time()], 'id = ?', [$src['id']]);
            } catch (\Throwable $e) {
                Support::log('Extraction failed for source ' . $src['id'] . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                DB::update('sources', ['status' => 'error', 'error' => 'Kilden kunne ikke læses. Den er måske beskadiget eller i et usædvanligt format.', 'updated_at' => time()], 'id = ?', [$src['id']]);
            }
            $done = (int) DB::val("SELECT COUNT(*) FROM sources WHERE subject_id = ? AND status IN ('ready','error')", [$sid]);
            self::update((int) $job['id'], ['progress' => (int) min(25, 25 * $done / $total)]);
            return;
        }
        if (!(int) DB::val('SELECT COUNT(*) FROM chunks WHERE subject_id = ?', [$sid])) {
            $err = 'Ingen af kilderne indeholdt læsbar tekst. Se fejlene på fanen Kilder.';
            self::update((int) $job['id'], ['status' => 'failed', 'error' => $err, 'message' => $err]);
            DB::update('subjects', ['status' => 'error', 'updated_at' => time()], 'id = ?', [$sid]);
            return;
        }
        $batches = Analyzer::batches($sid);
        self::update((int) $job['id'], [
            'stage' => 'map',
            'progress' => 25,
            'message' => 'Analyserer dit materiale',
            'payload' => json_encode(['batches' => $batches, 'partials' => []]),
        ]);
    }

    private static function extractSource(array $src): void
    {
        $meta = Support::decode($src['meta']);
        if ($src['status'] === 'ocr') {
            self::ocrStep($src, $meta);
            return;
        }
        DB::update('sources', ['status' => 'processing', 'updated_at' => time()], 'id = ?', [$src['id']]);
        $segments = [];
        $pendingOcr = [];
        $title = null;
        $ext = $meta['ext'] ?? '';
        if ($src['kind'] === 'youtube') {
            $r = YouTube::import((string) $src['url']);
            $segments = $r['segments'];
            $title = $r['title'];
            $meta = array_merge($meta, $r['meta']);
            if (!empty($r['meta']['auto_generated'])) {
                $meta['notice'] = 'Bruger automatisk genererede undertekster, som kan indeholde fejl.';
            }
        } elseif ($src['kind'] === 'url') {
            $r = Web::import((string) $src['url']);
            $title = $r['title'];
            if (!empty($r['pdf_path'])) {
                try {
                    $segments = Pdf::extract($r['pdf_path']);
                } finally {
                    @unlink($r['pdf_path']);
                }
            } else {
                $segments = $r['segments'];
            }
        } else {
            $path = Sources::abs($src['storage_path']);
            if (!is_file($path)) {
                throw HttpError::bad('Den uploadede fil mangler på serveren. Upload den igen.');
            }
            switch ($ext) {
                case 'zip':
                    self::expandZip($src, $path);
                    return;
                case 'pdf':
                    if (!empty($meta['client_pages']) && is_file(Sources::abs($meta['client_pages']))) {
                        $pages = json_decode((string) file_get_contents(Sources::abs($meta['client_pages'])), true) ?: [];
                        foreach ($pages as $i => $t) {
                            $segments[] = ['label' => 's. ' . ($i + 1), 'text' => (string) $t, 'order' => $i];
                        }
                    } else {
                        $segments = Pdf::extract($path);
                        foreach ($segments as $i => &$sg) {
                            $sg['order'] = $i;
                        }
                        unset($sg);
                        $chars = array_sum(array_map(static fn ($s) => mb_strlen($s['text']), $segments));
                        if ($chars < 50 * max(1, count($segments)) && empty($meta['pending_ocr'])) {
                            if ($chars < 30) {
                                throw HttpError::bad('PDF’en ser scannet ud (intet tekstlag). Upload den direkte (ikke i en ZIP-fil), så siderne kan læses med OCR.');
                            }
                            $meta['notice'] = 'Nogle sider havde kun lidt læsbar tekst. Hvis det er en scannet PDF, så upload den direkte for at få OCR.';
                        }
                    }
                    $meta['pages'] = count($segments);
                    foreach ((array) ($meta['pending_ocr'] ?? []) as $p) {
                        $pendingOcr[] = $p;
                    }
                    break;
                case 'docx':
                    $segments = Office::docx($path);
                    break;
                case 'pptx':
                    $segments = Office::pptx($path);
                    $meta['pages'] = count($segments);
                    break;
                case 'xlsx':
                    $segments = Office::xlsx($path);
                    break;
                case 'html':
                case 'htm':
                    $r = Web::readable((string) file_get_contents($path));
                    $segments = $r['segments'];
                    break;
                case 'png':
                case 'jpg':
                case 'jpeg':
                case 'webp':
                case 'gif':
                    $pendingOcr[] = ['label' => 'Billede', 'path' => $src['storage_path'], 'order' => 0];
                    break;
                default: // txt, md, csv, json
                    $segments = self::textSegments(Support::clean((string) file_get_contents($path)), $src['title']);
            }
        }
        if ($title) {
            $src['title'] = Support::excerpt($title, 180);
        }
        if ($pendingOcr) {
            $meta['segments_file'] = Sources::storeBytes((int) $src['user_id'], json_encode($segments, JSON_UNESCAPED_UNICODE), 'json');
            $meta['pending_ocr'] = array_values($pendingOcr);
            $meta['ocr_total'] = count($pendingOcr);
            $meta['ocr_done'] = [];
            DB::update('sources', ['status' => 'ocr', 'title' => $src['title'], 'meta' => json_encode($meta), 'updated_at' => time()], 'id = ?', [$src['id']]);
            return;
        }
        self::finalizeSource($src, $segments, $meta);
    }

    private static function ocrStep(array $src, array $meta): void
    {
        $pending = (array) ($meta['pending_ocr'] ?? []);
        $done = (array) ($meta['ocr_done'] ?? []);
        foreach (array_splice($pending, 0, 3) as $item) {
            $abs = Sources::abs($item['path']);
            if (!is_file($abs)) {
                continue;
            }
            [$bytes, $mime] = self::prepareImage($abs);
            $text = AI::ocr($bytes, $mime, 'Study material: ' . $src['title']);
            $order = $item['order'] ?? (preg_match('/(\d+)/', (string) $item['label'], $m) ? (int) $m[1] - 1 : 0);
            $done[] = ['label' => $item['label'], 'text' => $text, 'order' => $order, 'ocr' => true];
            if ($item['path'] !== $src['storage_path']) {
                @unlink($abs);
            }
        }
        $meta['pending_ocr'] = $pending;
        $meta['ocr_done'] = $done;
        if ($pending) {
            DB::update('sources', ['meta' => json_encode($meta), 'updated_at' => time()], 'id = ?', [$src['id']]);
            return;
        }
        $segments = [];
        if (!empty($meta['segments_file']) && is_file(Sources::abs($meta['segments_file']))) {
            $segments = json_decode((string) file_get_contents(Sources::abs($meta['segments_file'])), true) ?: [];
            @unlink(Sources::abs($meta['segments_file']));
        }
        // OCR text replaces near-empty pages with the same label.
        $byLabel = [];
        foreach ($segments as $i => $s) {
            $byLabel[$s['label']] = $i;
        }
        foreach ($done as $d) {
            if (isset($byLabel[$d['label']]) && mb_strlen(trim($segments[$byLabel[$d['label']]]['text'])) < 40) {
                $segments[$byLabel[$d['label']]]['text'] = $d['text'];
            } elseif (!isset($byLabel[$d['label']])) {
                $segments[] = $d;
            }
        }
        usort($segments, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        $meta['ocr_pages'] = count($done);
        unset($meta['pending_ocr'], $meta['ocr_done'], $meta['segments_file']);
        self::finalizeSource($src, $segments, $meta);
    }

    /** Downscales big images (when GD is available) to keep vision requests small. */
    private static function prepareImage(string $abs): array
    {
        $bytes = (string) file_get_contents($abs);
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? 'image/jpeg';
        if (!$info || !function_exists('imagecreatefromstring')) {
            return [$bytes, $mime];
        }
        [$w, $h] = $info;
        $max = 2000;
        if ($w <= $max && $h <= $max && strlen($bytes) < 3 * 1024 * 1024) {
            return [$bytes, $mime];
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return [$bytes, $mime];
        }
        $scale = min(1, $max / max($w, $h));
        $dst = imagecreatetruecolor((int) ($w * $scale), (int) ($h * $scale));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, (int) ($w * $scale), (int) ($h * $scale), $w, $h);
        ob_start();
        imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();
        return [$out, 'image/jpeg'];
    }

    private static function finalizeSource(array $src, array $segments, array $meta): void
    {
        $segments = array_values(array_filter(array_map(static fn ($s) => [
            'label' => (string) ($s['label'] ?? ''),
            'text' => Support::clean((string) ($s['text'] ?? '')),
        ], $segments), static fn ($s) => $s['text'] !== ''));
        $chars = array_sum(array_map(static fn ($s) => mb_strlen($s['text']), $segments));
        if ($chars < 20) {
            $msg = in_array($meta['ext'] ?? '', Sources::IMG_EXT, true)
                ? 'Der blev ikke fundet læsbar tekst i billedet.'
                : 'Der blev ikke fundet læsbar tekst i kilden.';
            DB::update('sources', ['status' => 'error', 'error' => $msg, 'title' => $src['title'], 'meta' => json_encode($meta), 'updated_at' => time()], 'id = ?', [$src['id']]);
            return;
        }
        Retriever::index($src, $segments);
        DB::update('sources', [
            'status' => 'ready',
            'error' => null,
            'title' => $src['title'],
            'char_count' => $chars,
            'meta' => json_encode($meta),
            'updated_at' => time(),
        ], 'id = ?', [$src['id']]);
    }

    private static function textSegments(string $text, string $title): array
    {
        // Markdown headings become section labels; otherwise ~4000 char parts.
        $segments = [];
        $parts = preg_split('/^(?=#{1,3} )/m', $text) ?: [$text];
        if (count($parts) > 1) {
            foreach ($parts as $p) {
                $label = preg_match('/^#{1,3} (.+)$/m', $p, $m) ? Support::excerpt(trim($m[1]), 80) : $title;
                $segments[] = ['label' => $label, 'text' => $p];
            }
            return $segments;
        }
        $paras = preg_split('/\n\s*\n/', $text) ?: [$text];
        $buf = '';
        $n = 1;
        foreach ($paras as $p) {
            if (mb_strlen($buf) + mb_strlen($p) > 4000 && $buf !== '') {
                $segments[] = ['label' => 'Del ' . $n++, 'text' => $buf];
                $buf = '';
            }
            $buf .= ($buf ? "\n\n" : '') . $p;
        }
        if ($buf !== '') {
            $segments[] = ['label' => $n === 1 ? '' : 'Del ' . $n, 'text' => $buf];
        }
        return $segments;
    }

    private static function expandZip(array $src, string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw HttpError::bad('ZIP-filen er beskadiget eller beskyttet med adgangskode.');
        }
        $allowed = array_diff(array_merge(Sources::DOC_EXT, Sources::IMG_EXT), ['zip']);
        $children = 0;
        $skipped = [];
        $total = 0;
        $limitTotal = Env::int('MAX_ZIP_UNCOMPRESSED_MB', 500) * 1048576;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            $base = basename($name);
            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX') || str_starts_with($base, '.') || str_starts_with($base, '~$')) {
                continue;
            }
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $skipped[] = $base;
                continue;
            }
            if ($children >= 300) {
                $skipped[] = '(flere filer – grænsen på 300 er nået)';
                break;
            }
            if ($stat['size'] > Sources::maxBytes()) {
                $skipped[] = "$base (for stor)";
                continue;
            }
            $total += (int) $stat['size'];
            if ($total > $limitTotal) {
                $zip->close();
                throw HttpError::bad('ZIP-filen fylder mere end ' . Env::int('MAX_ZIP_UNCOMPRESSED_MB', 500) . ' MB udpakket, hvilket er over grænsen.');
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false || strlen($bytes) !== (int) $stat['size']) {
                $skipped[] = "$base (kan ikke læses)";
                continue;
            }
            $rel = Sources::storeBytes((int) $src['user_id'], $bytes, $ext);
            if (!Sources::sniff(Sources::abs($rel), $ext)) {
                @unlink(Sources::abs($rel));
                $skipped[] = "$base (ugyldig fil)";
                continue;
            }
            DB::insert('sources', [
                'user_id' => $src['user_id'],
                'subject_id' => $src['subject_id'],
                'parent_id' => $src['id'],
                'kind' => 'file',
                'title' => Support::excerpt($name, 180),
                'original_name' => $base,
                'mime' => 'application/octet-stream',
                'size_bytes' => strlen($bytes),
                'storage_path' => $rel,
                'status' => 'queued',
                'meta' => json_encode(['ext' => $ext]),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $children++;
        }
        $zip->close();
        $meta = Support::decode($src['meta']);
        $meta['children'] = $children;
        if ($skipped) {
            $meta['notice'] = 'Sprunget over: ' . Support::excerpt(implode(', ', $skipped), 300);
        }
        DB::update('sources', [
            'status' => $children ? 'ready' : 'error',
            'error' => $children ? null : 'ZIP-filen indeholder ingen understøttede filer.',
            'meta' => json_encode($meta),
            'updated_at' => time(),
        ], 'id = ?', [$src['id']]);
    }

    // --------------------------------------------------------------- analysis

    /** How many AI tasks run at the same time (separate PHP processes). */
    private static function parallelism(): int
    {
        return max(1, min(6, Env::int('AI_PARALLEL', 2)));
    }

    /**
     * Runs `php bin/task.php ...` commands in parallel processes and returns their decoded JSON output,
     * or null when processes can't be started (the caller then works sequentially).
     * @param array<int, string[]> $tasks
     */
    private static function parallel(array $tasks): ?array
    {
        if (!function_exists('proc_open') || $tasks === []) {
            return null;
        }
        $php = PHP_BINARY;
        if (!$php || !is_file($php) || stripos(basename($php), 'php') === false) {
            return null;
        }
        $procs = [];
        foreach ($tasks as $i => $args) {
            $cmd = array_merge([$php, ROOT . '/bin/task.php'], array_map('strval', $args));
            $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
            if (!is_resource($p)) {
                return null;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$i] = ['p' => $p, 'pipes' => $pipes, 'out' => ''];
        }
        $deadline = time() + 270;
        $results = [];
        while ($procs && time() < $deadline) {
            foreach (array_keys($procs) as $i) {
                $procs[$i]['out'] .= (string) stream_get_contents($procs[$i]['pipes'][1]);
                stream_get_contents($procs[$i]['pipes'][2]);
                $st = proc_get_status($procs[$i]['p']);
                if (!$st['running']) {
                    $procs[$i]['out'] .= (string) stream_get_contents($procs[$i]['pipes'][1]);
                    fclose($procs[$i]['pipes'][1]);
                    fclose($procs[$i]['pipes'][2]);
                    proc_close($procs[$i]['p']);
                    $results[$i] = json_decode(trim($procs[$i]['out']), true);
                    unset($procs[$i]);
                }
            }
            usleep(200000);
        }
        foreach ($procs as $i => $pr) { // timed out
            proc_terminate($pr['p']);
            $results[$i] = ['ok' => false, 'error' => 'AI’en var for længe om det. Det bliver prøvet igen.', 'status' => 503];
        }
        ksort($results);
        return $results;
    }

    /** Throws the most relevant error when every parallel task failed (so the job step is retried). */
    private static function failIfAllFailed(array $results): void
    {
        foreach ($results as $r) {
            if (is_array($r) && !empty($r['ok'])) {
                return;
            }
        }
        $first = array_values($results)[0] ?? null;
        throw new HttpError((int) ($first['status'] ?? 503), (string) ($first['error'] ?? 'AI-opgaverne mislykkedes.'), $first['code'] ?? 'transient');
    }

    private static function stageMap(array $job, array $payload): void
    {
        $batches = (array) ($payload['batches'] ?? []);
        $partials = (array) ($payload['partials'] ?? []);
        $i = count($partials);
        if ($i < count($batches)) {
            $todo = array_slice(array_keys($batches), $i, self::parallelism());
            self::update((int) $job['id'], ['message' => 'Analyserer materiale (del ' . ($i + 1) . (count($todo) > 1 ? '–' . ($i + count($todo)) : '') . ' af ' . count($batches) . ')']);
            $results = count($todo) > 1 ? self::parallel(array_map(static fn ($b) => ['map', $job['id'], $b], $todo)) : null;
            if ($results === null) {
                $partials[] = Analyzer::mapBatch((int) $job['user_id'], (array) $batches[$i]);
            } else {
                self::failIfAllFailed($results);
                foreach ($results as $r) {
                    // Keep batch order: stop at the first failure; it is retried on the next step.
                    if (!is_array($r) || empty($r['ok'])) {
                        break;
                    }
                    $partials[] = $r['result'];
                }
            }
            $payload['partials'] = $partials;
            self::update((int) $job['id'], [
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'progress' => (int) (25 + 40 * count($partials) / max(1, count($batches))),
            ]);
            return;
        }
        self::update((int) $job['id'], ['stage' => 'reduce', 'message' => 'Organiserer emner og nøglepunkter']);
    }

    private static function stageReduce(array $job, array $payload): void
    {
        $subject = DB::one('SELECT * FROM subjects WHERE id = ?', [$job['subject_id']]);
        $topicIds = Analyzer::reduce($subject, (array) ($payload['partials'] ?? []));
        self::update((int) $job['id'], [
            'stage' => 'topics',
            'progress' => 70,
            'message' => 'Skriver din studieguide',
            'payload' => json_encode(['queue' => $topicIds, 'total' => count($topicIds)]),
        ]);
    }

    private static function stageTopics(array $job, array $payload): void
    {
        $queue = array_map('intval', (array) ($payload['queue'] ?? []));
        $subject = DB::one('SELECT * FROM subjects WHERE id = ?', [$job['subject_id']]);
        if (!$queue || !$subject) {
            self::finish($job);
            return;
        }
        // Several topics at once; each topic's guide and flashcards are written by separate processes.
        $batch = array_slice($queue, 0, self::parallelism());
        $names = DB::col('SELECT name FROM topics WHERE id IN (' . DB::in($batch) . ')', $batch);
        self::update((int) $job['id'], ['message' => 'Studieguide og flashcards: ' . Support::excerpt(implode(', ', $names), 80)]);
        $tasks = [];
        foreach ($batch as $tid) {
            $tasks[] = ['guide', $subject['id'], $tid];
            $tasks[] = ['cards', $subject['id'], $tid];
        }
        $results = self::parallel($tasks);
        if ($results === null) {
            $batch = [$queue[0]];
            $topic = DB::one('SELECT * FROM topics WHERE id = ?', [$batch[0]]);
            if ($topic) {
                Analyzer::topicMaterials($subject, $topic);
            }
        } else {
            self::failIfAllFailed($results);
        }
        $queue = array_values(array_diff($queue, $batch));
        $total = max(1, (int) ($payload['total'] ?? 1));
        $payload['queue'] = $queue;
        self::update((int) $job['id'], [
            'payload' => json_encode($payload),
            'progress' => (int) (70 + 30 * ($total - count($queue)) / $total),
        ]);
        if (!$queue) {
            self::finish($job);
        }
    }
}

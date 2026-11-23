<?php
declare(strict_types=1);

namespace App;

use App\Extract\YouTube;
use App\Extract\Web;

/** Adding, listing and deleting learning material. Extraction happens in Pipeline. */
final class Sources
{
    public const DOC_EXT = ['pdf', 'docx', 'pptx', 'xlsx', 'zip', 'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json'];
    public const IMG_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public static function maxBytes(): int
    {
        return Env::int('MAX_UPLOAD_MB', 50) * 1024 * 1024;
    }

    public static function limits(): array
    {
        $iniBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $php = min($iniBytes((string) ini_get('upload_max_filesize')), $iniBytes((string) ini_get('post_max_size')));
        return [
            'max_bytes' => min(self::maxBytes(), $php > 0 ? $php : PHP_INT_MAX),
            'extensions' => array_merge(self::DOC_EXT, self::IMG_EXT),
        ];
    }

    private static function userDir(int $uid): string
    {
        $dir = ROOT . '/storage/uploads/' . $uid;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function storeBytes(int $uid, string $bytes, string $ext): string
    {
        $rel = 'uploads/' . $uid . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        self::userDir($uid);
        file_put_contents(ROOT . '/storage/' . $rel, $bytes);
        return $rel;
    }

    public static function abs(?string $rel): string
    {
        return ROOT . '/storage/' . ltrim((string) $rel, '/');
    }

    /** Checks magic bytes so a renamed executable can't pass as a PDF etc. */
    public static function sniff(string $path, string $ext): bool
    {
        $fh = fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 16) : '';
        if ($fh) {
            fclose($fh);
        }
        return match ($ext) {
            'pdf' => str_contains(substr($head, 0, 16), '%PDF') || str_contains((string) file_get_contents($path, false, null, 0, 1024), '%PDF'),
            'docx', 'pptx', 'xlsx', 'zip' => str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06"),
            'png' => str_starts_with($head, "\x89PNG"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($head, 'GIF8'),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            // Text formats: reject obvious binaries (NUL bytes outside UTF-16).
            default => !str_contains(substr((string) file_get_contents($path, false, null, 0, 4096), 0, 4096), "\0")
                || str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF"),
        };
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Filen er større end serveren tillader (' . round(self::limits()['max_bytes'] / 1048576) . ' MB). Del filen op, eller hæv grænsen på serveren.',
            UPLOAD_ERR_PARTIAL => 'Uploaden blev afbrudt. Prøv igen.',
            UPLOAD_ERR_NO_FILE => 'Der blev ikke modtaget nogen fil.',
            default => 'Serveren kunne ikke gemme uploaden (fejl ' . $code . ').',
        };
    }

    // ------------------------------------------------------------------ actions

    public static function upload(): array
    {
        $uid = Auth::id();
        $subject = Subjects::owned(Http::int('subject_id'), $uid);
        if (empty($_FILES['file'])) {
            if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)) {
                throw HttpError::bad('Uploaden er større end serveren tillader.');
            }
            throw HttpError::bad('Der blev ikke modtaget nogen fil.');
        }
        $f = $_FILES['file'];
        if (is_array($f['error'])) {
            throw HttpError::bad('Upload én fil ad gangen.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw HttpError::bad(self::uploadError((int) $f['error']));
        }
        $name = Support::excerpt(basename(str_replace('\\', '/', (string) $f['name'])), 180);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['doc', 'ppt', 'xls', 'odt', 'odp', 'ods', 'pages', 'key', 'rtf'], true)) {
            throw HttpError::bad("“$name”: gamle formater understøttes ikke. Gem den som .docx, .pptx, .xlsx eller PDF og upload igen.");
        }
        if (!in_array($ext, array_merge(self::DOC_EXT, self::IMG_EXT), true)) {
            throw HttpError::bad("“$name”: filtypen understøttes ikke. Understøttet: PDF, Word, PowerPoint, Excel, ZIP, TXT, Markdown, CSV, HTML og billeder.");
        }
        if ($f['size'] > self::maxBytes()) {
            throw HttpError::bad("“$name” er for stor (maks. " . Env::int('MAX_UPLOAD_MB', 50) . ' MB).');
        }
        if ($f['size'] === 0) {
            throw HttpError::bad("“$name” er tom.");
        }
        if (!self::sniff($f['tmp_name'], $ext)) {
            throw HttpError::bad("“$name” passer ikke til sin filtype (den er måske beskadiget eller omdøbt).");
        }
        $quota = Env::int('MAX_STORAGE_MB_PER_USER', 2000) * 1048576;
        $used = (int) DB::val('SELECT COALESCE(SUM(size_bytes),0) FROM sources WHERE user_id = ?', [$uid]);
        if ($used + $f['size'] > $quota) {
            throw HttpError::bad('Du har nået din lagergrænse. Slet nogle kilder først.');
        }
        $rel = 'uploads/' . $uid . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        self::userDir($uid);
        if (!move_uploaded_file($f['tmp_name'], self::abs($rel))) {
            throw new HttpError(500, 'Serveren kunne ikke gemme filen. Tjek at mappen storage/ er skrivbar.');
        }
        $meta = ['ext' => $ext];

        // PDFs: the browser extracts text with pdf.js and renders scanned pages to images.
        if ($ext === 'pdf') {
            $pages = json_decode((string) ($_POST['pdf_pages'] ?? ''), true);
            if (is_array($pages)) {
                $pages = array_map(static fn ($p) => Support::clean(mb_substr((string) $p, 0, 200000)), array_slice($pages, 0, 3000));
                $meta['client_pages'] = self::storeBytes($uid, json_encode($pages, JSON_UNESCAPED_UNICODE), 'json');
            }
            $ocr = [];
            if (!empty($_FILES['ocr_pages']) && is_array($_FILES['ocr_pages']['name'])) {
                $labels = json_decode((string) ($_POST['ocr_labels'] ?? '[]'), true) ?: [];
                foreach ($_FILES['ocr_pages']['tmp_name'] as $i => $tmp) {
                    if ($_FILES['ocr_pages']['error'][$i] !== UPLOAD_ERR_OK || $i >= 200 || !self::sniff($tmp, 'jpg')) {
                        continue;
                    }
                    $ocr[] = ['label' => (string) ($labels[$i] ?? ('p. ' . ($i + 1))), 'path' => self::storeBytes($uid, (string) file_get_contents($tmp), 'jpg')];
                }
            }
            if ($ocr) {
                $meta['pending_ocr'] = $ocr;
            }
        }

        $id = DB::insert('sources', [
            'user_id' => $uid,
            'subject_id' => $subject['id'],
            'kind' => 'file',
            'title' => $name,
            'original_name' => $name,
            'mime' => (string) ($f['type'] ?: 'application/octet-stream'),
            'size_bytes' => (int) $f['size'],
            'storage_path' => $rel,
            'status' => 'queued',
            'meta' => json_encode($meta),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Subjects::touch((int) $subject['id'], 'outdated');
        return ['source' => self::present(DB::one('SELECT * FROM sources WHERE id = ?', [$id]))];
    }

    public static function addUrl(): array
    {
        $uid = Auth::id();
        $subject = Subjects::owned(Http::int('subject_id'), $uid);
        $url = Http::str('url', '', 2000);
        if ($url === '') {
            throw HttpError::bad('Indsæt et link.');
        }
        if (YouTube::isYouTube($url)) {
            $vid = YouTube::videoId($url);
            if (!$vid) {
                throw HttpError::bad('Det ligner ikke et link til en enkelt YouTube-video.');
            }
            $kind = 'youtube';
            $url = 'https://www.youtube.com/watch?v=' . $vid;
            $title = 'YouTube-video';
        } else {
            $v = Web::validateUrl($url);
            $kind = 'url';
            $url = $v['url'];
            $title = $v['host'];
        }
        if (DB::val('SELECT id FROM sources WHERE subject_id = ? AND url = ?', [$subject['id'], $url])) {
            throw HttpError::bad('Det link er allerede i faget.');
        }
        $id = DB::insert('sources', [
            'user_id' => $uid,
            'subject_id' => $subject['id'],
            'kind' => $kind,
            'title' => $title,
            'url' => $url,
            'status' => 'queued',
            'meta' => '{}',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Subjects::touch((int) $subject['id'], 'outdated');
        return ['source' => self::present(DB::one('SELECT * FROM sources WHERE id = ?', [$id]))];
    }

    public static function addText(): array
    {
        $uid = Auth::id();
        $subject = Subjects::owned(Http::int('subject_id'), $uid);
        $text = Support::clean((string) (Http::input()['text'] ?? ''));
        $title = Http::str('title', '', 150) ?: Support::excerpt($text, 60);
        if (mb_strlen($text) < 20) {
            throw HttpError::bad('Indsæt mindst et par sætninger.');
        }
        Security::inspect([$title, $text], 'Indsat tekst', true);
        if (strlen($text) > 5 * 1024 * 1024) {
            throw HttpError::bad('Teksten er for lang (maks. 5 MB). Del den op.');
        }
        $rel = self::storeBytes($uid, $text, 'txt');
        $id = DB::insert('sources', [
            'user_id' => $uid,
            'subject_id' => $subject['id'],
            'kind' => 'text',
            'title' => $title ?: 'Indsat tekst',
            'size_bytes' => strlen($text),
            'storage_path' => $rel,
            'status' => 'queued',
            'meta' => json_encode(['ext' => 'txt']),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Subjects::touch((int) $subject['id'], 'outdated');
        return ['source' => self::present(DB::one('SELECT * FROM sources WHERE id = ?', [$id]))];
    }

    public static function list(): array
    {
        $uid = Auth::id();
        $subject = Subjects::owned(Http::int('subject_id'), $uid);
        $rows = DB::all('SELECT * FROM sources WHERE subject_id = ? ORDER BY id', [$subject['id']]);
        return ['sources' => array_map([self::class, 'present'], $rows)];
    }

    public static function get(): array
    {
        $uid = Auth::id();
        $src = DB::one('SELECT * FROM sources WHERE id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$src) {
            throw HttpError::notFound('Source');
        }
        $chunks = DB::all('SELECT id, label, content, position FROM chunks WHERE source_id = ? ORDER BY position', [$src['id']]);
        return ['source' => self::present($src), 'chunks' => $chunks];
    }

    public static function rename(): array
    {
        $uid = Auth::id();
        $title = Http::str('title', '', 180);
        if ($title === '') {
            throw HttpError::bad('Titlen må ikke være tom.');
        }
        $n = DB::update('sources', ['title' => $title, 'updated_at' => time()], 'id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$n) {
            throw HttpError::notFound('Source');
        }
        return ['ok' => true];
    }

    public static function retry(): array
    {
        $uid = Auth::id();
        $src = DB::one('SELECT * FROM sources WHERE id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$src) {
            throw HttpError::notFound('Source');
        }
        DB::update('sources', ['status' => 'queued', 'error' => null, 'updated_at' => time()], 'id = ?', [$src['id']]);
        return ['ok' => true];
    }

    public static function delete(): array
    {
        $uid = Auth::id();
        $src = DB::one('SELECT * FROM sources WHERE id = ? AND user_id = ?', [Http::int('id'), $uid]);
        if (!$src) {
            throw HttpError::notFound('Source');
        }
        $ids = array_merge([(int) $src['id']], array_map('intval', DB::col('SELECT id FROM sources WHERE parent_id = ?', [$src['id']])));
        foreach ($ids as $id) {
            self::destroy($id);
        }
        $remaining = (int) DB::val('SELECT COUNT(*) FROM sources WHERE subject_id = ?', [$src['subject_id']]);
        Subjects::touch((int) $src['subject_id'], $remaining ? 'outdated' : 'empty');
        return ['ok' => true];
    }

    /** Removes a source, its chunks and its files (no ownership check — callers do that). */
    public static function destroy(int $id): void
    {
        $src = DB::one('SELECT * FROM sources WHERE id = ?', [$id]);
        if (!$src) {
            return;
        }
        $meta = Support::decode($src['meta']);
        $files = array_filter([$src['storage_path'], $meta['client_pages'] ?? null, $meta['segments_file'] ?? null]);
        foreach ((array) ($meta['pending_ocr'] ?? []) as $p) {
            $files[] = $p['path'] ?? null;
        }
        foreach (array_filter($files) as $rel) {
            $abs = self::abs($rel);
            if (is_file($abs) && str_starts_with(realpath($abs) ?: '', realpath(ROOT . '/storage') ?: '/nonexistent')) {
                @unlink($abs);
            }
        }
        DB::exec('DELETE FROM chunks WHERE source_id = ?', [$id]);
        DB::exec('DELETE FROM sources WHERE id = ?', [$id]);
    }

    public static function present(?array $s): ?array
    {
        if (!$s) {
            return null;
        }
        $meta = Support::decode($s['meta']);
        return [
            'id' => (int) $s['id'],
            'subject_id' => (int) $s['subject_id'],
            'parent_id' => $s['parent_id'] ? (int) $s['parent_id'] : null,
            'kind' => $s['kind'],
            'title' => $s['title'],
            'ext' => $meta['ext'] ?? null,
            'url' => $s['url'],
            'size_bytes' => (int) $s['size_bytes'],
            'status' => $s['status'],
            'error' => $s['error'],
            'char_count' => (int) $s['char_count'],
            'chunk_count' => (int) DB::val('SELECT COUNT(*) FROM chunks WHERE source_id = ?', [$s['id']]),
            'meta' => (object) array_intersect_key((array) $meta, array_flip(['pages', 'children', 'author', 'auto_generated', 'language', 'ocr_pages', 'video_id', 'notice'])),
            'created_at' => (int) $s['created_at'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Extract;

use App\AI;
use App\HttpError;
use App\Support;

/** Fetches publicly available captions/transcripts for a YouTube video. */
final class YouTube
{
    public static function videoId(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) {
            return $url;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function isYouTube(string $url): bool
    {
        return (bool) preg_match('~(youtube\.com|youtu\.be|youtube-nocookie\.com)~i', $url);
    }

    private static function http(string $url, ?array $json = null): array
    {
        $ch = curl_init($url);
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
            'Cookie: CONSENT=YES+cb; SOCS=CAI',
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        AI::caBundle($ch);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    /** @return array{title:string, segments:array, meta:array} */
    public static function import(string $url): array
    {
        $id = self::videoId($url);
        if (!$id) {
            throw HttpError::bad('Det ligner ikke et link til en YouTube-video.');
        }
        $title = 'YouTube-video ' . $id;
        $author = '';
        $o = self::http('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id));
        if ($o['status'] === 200 && ($oj = json_decode($o['body'], true))) {
            $title = (string) ($oj['title'] ?? $title);
            $author = (string) ($oj['author_name'] ?? '');
        } elseif ($o['status'] === 404 || $o['status'] === 401 || $o['status'] === 403) {
            throw HttpError::bad('Videoen er privat, utilgængelig eller findes ikke.');
        }

        $tracks = self::tracks($id);
        if (!$tracks) {
            throw new HttpError(422, 'Videoen har ingen transskription eller undertekster, så den kan ikke læses. Indsæt i stedet en transskription som tekst (f.eks. fra videobeskrivelsen eller YouTubes “Vis transskription”).', 'no_transcript');
        }
        // Prefer manually written captions, then English, then anything.
        usort($tracks, static function ($a, $b) {
            $sa = (($a['kind'] ?? '') === 'asr' ? 0 : 2) + (str_starts_with((string) ($a['languageCode'] ?? ''), 'en') ? 1 : 0);
            $sb = (($b['kind'] ?? '') === 'asr' ? 0 : 2) + (str_starts_with((string) ($b['languageCode'] ?? ''), 'en') ? 1 : 0);
            return $sb <=> $sa;
        });
        $lines = [];
        foreach (array_slice($tracks, 0, 3) as $track) {
            $lines = self::fetchTrack((string) $track['baseUrl']);
            if ($lines) {
                $chosen = $track;
                break;
            }
        }
        if (!$lines) {
            throw new HttpError(422, 'YouTube viser undertekster til videoen, men sendte dem ikke. Prøv igen senere, eller indsæt transskriptionen som tekst.', 'no_transcript');
        }
        // Group into ~90 second segments labelled with timestamps.
        $segments = [];
        $buf = '';
        $segStart = 0.0;
        foreach ($lines as [$start, $text]) {
            if ($buf !== '' && $start - $segStart >= 90) {
                $segments[] = ['label' => self::ts($segStart), 'text' => trim($buf)];
                $buf = '';
                $segStart = $start;
            }
            if ($buf === '') {
                $segStart = $start;
            }
            $buf .= ' ' . $text;
        }
        if (trim($buf) !== '') {
            $segments[] = ['label' => self::ts($segStart), 'text' => trim($buf)];
        }
        return [
            'title' => $title,
            'segments' => $segments,
            'meta' => [
                'video_id' => $id,
                'author' => $author,
                'language' => $chosen['languageCode'] ?? null,
                'auto_generated' => ($chosen['kind'] ?? '') === 'asr',
            ],
        ];
    }

    private static function ts(float $sec): string
    {
        $s = (int) $sec;
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    private static function tracks(string $id): array
    {
        $page = self::http('https://www.youtube.com/watch?v=' . $id . '&hl=en');
        $apiKey = preg_match('/"INNERTUBE_API_KEY":\s*"([^"]+)"/', $page['body'], $m) ? $m[1] : null;
        // 1) Innertube player API with the Android client (returns caption URLs that work without a browser token).
        if ($apiKey) {
            $r = self::http('https://www.youtube.com/youtubei/v1/player?key=' . $apiKey, [
                'context' => ['client' => ['clientName' => 'ANDROID', 'clientVersion' => '20.10.38', 'hl' => 'en']],
                'videoId' => $id,
            ]);
            $j = json_decode($r['body'], true);
            $t = $j['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? [];
            if ($t) {
                return $t;
            }
        }
        // 2) Captions embedded in the watch page.
        if (preg_match('/"captionTracks":(\[.*?\])\s*,\s*"audioTracks"/s', $page['body'], $m)
            || preg_match('/"captionTracks":(\[.*?\])/s', $page['body'], $m)) {
            $t = json_decode($m[1], true);
            if (is_array($t)) {
                return $t;
            }
        }
        return [];
    }

    /** @return array<int, array{0: float, 1: string}> */
    private static function fetchTrack(string $baseUrl): array
    {
        $baseUrl = preg_replace('/&fmt=[^&]*/', '', str_replace('&', '&', $baseUrl)) ?? $baseUrl;
        $r = self::http($baseUrl);
        if ($r['status'] !== 200 || trim($r['body']) === '') {
            return [];
        }
        $lines = [];
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$xml) {
            return [];
        }
        // Format 1: <transcript><text start="..">..</text>
        foreach ($xml->xpath('//text') ?: [] as $t) {
            $txt = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = [(float) $t['start'], trim(preg_replace('/\s+/u', ' ', $txt) ?? '')];
        }
        // Format 3 (srv3): <timedtext><body><p t="ms"><s>..</s></p>
        if (!$lines) {
            foreach ($xml->xpath('//p') ?: [] as $p) {
                $txt = trim(preg_replace('/\s+/u', ' ', strip_tags($p->asXML() ?: '')) ?? '');
                $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($txt !== '') {
                    $lines[] = [((float) $p['t']) / 1000, $txt];
                }
            }
        }
        return array_values(array_filter($lines, static fn ($l) => $l[1] !== '' && !preg_match('/^\[(music|applause|muziek)\]$/i', $l[1])));
    }

    public static function cleanTitle(string $t): string
    {
        return Support::excerpt($t, 150);
    }
}

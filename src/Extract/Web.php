<?php
declare(strict_types=1);

namespace App\Extract;

use App\AI;
use App\Env;
use App\HttpError;
use App\Support;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Fetches public web pages safely (no private network access, robots.txt respected,
 * no paywall/login bypass) and extracts the readable article text.
 */
final class Web
{
    public const UA = 'Studiepilot/1.0 (+educational content reader)';
    private const MAX_BYTES = 8 * 1024 * 1024;

    public static function validateUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw HttpError::bad('Skriv en gyldig webadresse (http:// eller https://).');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw HttpError::bad('Links med brugernavn eller adgangskode er ikke tilladt.');
        }
        $host = strtolower($parts['host']);
        if (!Env::bool('ALLOW_PRIVATE_URLS', false)) {
            $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : (gethostbynamel($host) ?: []);
            if (!$ips) {
                throw HttpError::bad("Kunne ikke finde websiden \"$host\". Tjek adressen og din internetforbindelse.");
            }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw HttpError::bad('Links til lokale eller private netværksadresser er ikke tilladt.');
                }
            }
        }
        return ['url' => $url, 'host' => $host, 'parts' => $parts];
    }

    /** GET with manual redirects (each hop re-validated). */
    public static function fetch(string $url, array $headers = [], int $maxBytes = self::MAX_BYTES): array
    {
        for ($hop = 0; $hop < 6; $hop++) {
            $v = self::validateUrl($url);
            $url = $v['url'];
            $ch = curl_init($url);
            $body = '';
            $tooBig = false;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . self::UA, 'Accept-Language: en,nl;q=0.8,*;q=0.5'], $headers),
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                    $body .= $chunk;
                    if (strlen($body) > $maxBytes) {
                        $tooBig = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ]);
            AI::caBundle($ch);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err = curl_error($ch);
            curl_close($ch);
            if ($tooBig) {
                throw HttpError::bad('Siden eller filen er for stor til at blive hentet (grænse ' . intdiv($maxBytes, 1024 * 1024) . ' MB).');
            }
            if ($status === 0) {
                throw HttpError::bad("Kunne ikke nå den adresse ($err).");
            }
            if ($status >= 300 && $status < 400 && $redirect) {
                $url = $redirect;
                continue;
            }
            return ['status' => $status, 'type' => $type, 'body' => $body, 'url' => $url];
        }
        throw HttpError::bad('Linket viderestiller for mange gange.');
    }

    /** Very small robots.txt check for our user agent and "*". */
    public static function allowedByRobots(array $parts): bool
    {
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        try {
            $r = self::fetch($base . '/robots.txt', [], 512 * 1024);
        } catch (\Throwable) {
            return true;
        }
        if ($r['status'] !== 200) {
            return true;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r?\n/', $r['body']) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($v);
            } elseif ($k === 'disallow' || $k === 'allow') {
                $inRules = true;
                foreach ($agents as $a) {
                    $groups[$a][] = [$k, $v];
                }
            }
        }
        $rules = $groups['studyai'] ?? ($groups['*'] ?? []);
        $best = ['allow', ''];
        foreach ($rules as [$type, $pattern]) {
            if ($pattern === '') {
                continue;
            }
            $re = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '~')) . '~';
            if (preg_match($re, $path) && strlen($pattern) >= strlen($best[1])) {
                $best = [$type, $pattern];
            }
        }
        return $best[0] === 'allow';
    }

    /** @return array{title:string, segments:array, pdf_path?:string} */
    public static function import(string $url): array
    {
        $v = self::validateUrl($url);
        if (!self::allowedByRobots($v['parts'])) {
            throw HttpError::bad("Websiden tillader ikke automatisk adgang til siden (robots.txt). Kopiér teksten og indsæt den i stedet.");
        }
        $r = self::fetch($v['url']);
        if ($r['status'] === 401 || $r['status'] === 403) {
            throw HttpError::bad('Siden kræver login eller blokerer automatisk adgang. Adgangsbegrænsninger bliver ikke omgået – kopiér den tekst, du har adgang til, og indsæt den i stedet.');
        }
        if ($r['status'] === 402) {
            throw HttpError::bad('Siden er bag en betalingsmur. Indsæt i stedet den tekst, du har adgang til.');
        }
        if ($r['status'] === 404) {
            throw HttpError::bad('Siden blev ikke fundet (404). Tjek linket.');
        }
        if ($r['status'] >= 400) {
            throw HttpError::bad('Websiden svarede med en fejl (HTTP ' . $r['status'] . ').');
        }
        if (stripos($r['type'], 'application/pdf') !== false || str_starts_with($r['body'], '%PDF')) {
            $tmp = ROOT . '/storage/tmp/' . bin2hex(random_bytes(8)) . '.pdf';
            file_put_contents($tmp, $r['body']);
            return ['title' => basename(parse_url($r['url'], PHP_URL_PATH) ?: 'document.pdf'), 'segments' => [], 'pdf_path' => $tmp];
        }
        if (stripos($r['type'], 'text/plain') !== false) {
            return ['title' => $v['host'], 'segments' => [['label' => $v['host'], 'text' => Support::clean($r['body'])]]];
        }
        if ($r['type'] !== '' && stripos($r['type'], 'html') === false && stripos($r['type'], 'xml') === false) {
            throw HttpError::bad('Linket peger på en filtype, der ikke kan læses fra nettet (' . $r['type'] . '). Download filen og upload den i stedet.');
        }
        $res = self::readable($r['body'], $r['url']);
        if (mb_strlen(implode(' ', array_column($res['segments'], 'text'))) < 200) {
            throw HttpError::bad("Kunne ikke finde læsbar artikeltekst på siden. Den kræver måske login, er bag en betalingsmur eller indlæser indholdet med JavaScript. Kopiér teksten og indsæt den i stedet.");
        }
        return $res;
    }

    /** Readability-style extraction: picks the main content node and keeps headings as segment labels. */
    public static function readable(string $html, string $url = ''): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $title = trim((string) ($xp->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue
            ?? $xp->query('//title')->item(0)?->textContent ?? ''));
        $title = $title !== '' ? Support::excerpt($title, 150) : (parse_url($url, PHP_URL_HOST) ?: 'Webside');

        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'aside', 'form', 'iframe', 'svg', 'button', 'header', 'template', 'select'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        $junk = '/(^|[\s_-])(comment|comments|sidebar|footer|nav|navbar|menu|cookie|consent|banner|advert|ads?|promo|share|social|related|newsletter|subscribe|popup|modal|breadcrumb)([\s_-]|$)/i';
        $bodyEl = $doc->getElementsByTagName('body')->item(0);
        $totalText = max(1, mb_strlen((string) $bodyEl?->textContent));
        foreach (iterator_to_array($xp->query('//*[@class or @id or @role]')) as $n) {
            if (!$n instanceof DOMElement || !$n->parentNode || !$n->parentNode->parentNode) {
                continue; // detached already, or the root <html>
            }
            if (in_array(strtolower($n->nodeName), ['html', 'body', 'article', 'main'], true)) {
                continue;
            }
            $sig = $n->getAttribute('class') . ' ' . $n->getAttribute('id');
            $role = $n->getAttribute('role');
            $isJunk = in_array($role, ['navigation', 'banner', 'complementary', 'contentinfo', 'dialog'], true) || preg_match($junk, $sig);
            // Never drop a wrapper that holds most of the page's text (class names like "has-menu" on content containers).
            if ($isJunk && mb_strlen($n->textContent) < 0.35 * $totalText) {
                $n->parentNode->removeChild($n);
            }
        }
        $root = $xp->query('//article')->item(0) ?? $xp->query('//main')->item(0) ?? $xp->query('//*[@role="main"]')->item(0);
        if (!$root) {
            // Pick the element whose direct <p> children hold the most text.
            $best = null;
            $bestScore = 0;
            foreach ($xp->query('//div|//section|//td') as $cand) {
                $score = 0;
                foreach ($xp->query('./p', $cand) as $p) {
                    $score += mb_strlen(trim($p->textContent));
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $cand;
                }
            }
            $root = $best ?? $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        }
        $segments = [];
        $label = $title;
        $buf = [];
        $flush = static function () use (&$segments, &$buf, &$label) {
            $t = trim(implode("\n", $buf));
            if ($t !== '') {
                $segments[] = ['label' => Support::excerpt($label, 80), 'text' => $t];
            }
            $buf = [];
        };
        $walk = static function (\DOMNode $node) use (&$walk, &$buf, &$label, $flush): void {
            foreach ($node->childNodes as $c) {
                if (!$c instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($c->nodeName);
                $text = trim(preg_replace('/\s+/u', ' ', $c->textContent) ?? '');
                if (preg_match('/^h([1-6])$/', $tag, $m)) {
                    if ($text !== '') {
                        if ((int) $m[1] <= 3) {
                            $flush();
                            $label = $text;
                        }
                        $buf[] = str_repeat('#', (int) $m[1]) . ' ' . $text;
                    }
                } elseif (in_array($tag, ['p', 'blockquote', 'pre', 'figcaption', 'dt', 'dd'], true)) {
                    if ($text !== '') {
                        $buf[] = $text;
                    }
                } elseif ($tag === 'li') {
                    if ($text !== '') {
                        $buf[] = '- ' . $text;
                    }
                } elseif ($tag === 'tr') {
                    $cells = [];
                    foreach ($c->childNodes as $td) {
                        if ($td instanceof DOMElement) {
                            $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? '');
                        }
                    }
                    if (array_filter($cells)) {
                        $buf[] = '| ' . implode(' | ', $cells) . ' |';
                    }
                } else {
                    $walk($c);
                }
            }
        };
        if ($root) {
            $walk($root);
        }
        $flush();
        return ['title' => $title, 'segments' => $segments];
    }
}

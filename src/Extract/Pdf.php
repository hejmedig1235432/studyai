<?php
declare(strict_types=1);

namespace App\Extract;

use App\HttpError;

/**
 * Dependency-free PDF text extractor. Handles compressed streams, object streams,
 * page trees with inherited resources, ToUnicode CMaps (incl. Identity-H CID fonts)
 * and form XObjects. Direct uploads are extracted in the browser with pdf.js (more
 * robust); this is used for PDFs inside ZIP files and web links to PDFs.
 */
final class Pdf
{
    private string $data;
    /** @var array<int,string> object number => raw body */
    private array $objects = [];
    private array $cmapCache = [];

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /** @return array<int, array{label:string,text:string}> one segment per page */
    public static function extract(string $path): array
    {
        $data = (string) file_get_contents($path);
        if (!str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF')) {
            throw HttpError::bad('Filen er ikke en gyldig PDF.');
        }
        $p = new self($data);
        $p->parseObjects();
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $data)) {
            throw HttpError::bad('PDF’en er krypteret, så teksten kan ikke læses på serveren. Upload PDF’en direkte (ikke i en ZIP-fil), eller fjern beskyttelsen.');
        }
        return $p->pages();
    }

    // ------------------------------------------------------------------ objects

    private function parseObjects(): void
    {
        preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE);
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $num = (int) $m[1][$i][0];
            $start = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end = strpos($this->data, 'endobj', $start);
            if ($end === false) {
                $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($this->data);
            }
            // Later definitions (incremental updates) override earlier ones.
            $this->objects[$num] = substr($this->data, $start, $end - $start);
        }
        // Expand object streams.
        foreach ($this->objects as $body) {
            if (!preg_match('/\/Type\s*\/ObjStm/', $this->dictPart($body))) {
                continue;
            }
            $dict = $this->dictPart($body);
            $n = preg_match('/\/N\s+(\d+)/', $dict, $mm) ? (int) $mm[1] : 0;
            $first = preg_match('/\/First\s+(\d+)/', $dict, $mm) ? (int) $mm[1] : 0;
            $content = $this->streamOf($body);
            if ($content === null || !$n) {
                continue;
            }
            $header = preg_split('/\s+/', trim(substr($content, 0, $first))) ?: [];
            $pairs = [];
            for ($j = 0; $j + 1 < count($header) && count($pairs) < $n; $j += 2) {
                $pairs[] = [(int) $header[$j], (int) $header[$j + 1]];
            }
            foreach ($pairs as $k => [$onum, $off]) {
                $next = isset($pairs[$k + 1]) ? $pairs[$k + 1][1] : strlen($content) - $first;
                if (!isset($this->objects[$onum])) {
                    $this->objects[$onum] = substr($content, $first + $off, $next - $off);
                }
            }
        }
    }

    private function dictPart(string $body): string
    {
        $pos = strpos($body, 'stream');
        return $pos === false ? $body : substr($body, 0, $pos);
    }

    private function obj(int $num): string
    {
        return $this->objects[$num] ?? '';
    }

    /** Resolves "12 0 R" to the referenced body, or returns the value itself. */
    private function resolve(string $value): string
    {
        $value = trim($value);
        $guard = 0;
        while (preg_match('/^(\d+)\s+\d+\s+R$/', $value, $m) && $guard++ < 10) {
            $value = trim($this->obj((int) $m[1]));
        }
        return $value;
    }

    /** Reads a dictionary entry value (dict, array, ref, name or number) from a dict string. */
    private function entry(string $dict, string $key): ?string
    {
        if (!preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*/', $dict, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $pos = $m[0][1] + strlen($m[0][0]);
        $rest = substr($dict, $pos);
        if (str_starts_with($rest, '<<')) {
            return $this->balanced($rest, '<<', '>>');
        }
        if (str_starts_with($rest, '[')) {
            return $this->balanced($rest, '[', ']');
        }
        if (preg_match('/^(\d+\s+\d+\s+R)/', $rest, $mm)) {
            return $mm[1];
        }
        if (preg_match('/^(\/[^\s\/\[\]<>()]+|[-+]?[\d.]+|true|false|null)/', $rest, $mm)) {
            return $mm[1];
        }
        return null;
    }

    private function balanced(string $s, string $open, string $close): string
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if (substr($s, $i, strlen($open)) === $open) {
                $depth++;
                $i += strlen($open) - 1;
            } elseif (substr($s, $i, strlen($close)) === $close) {
                $depth--;
                $i += strlen($close) - 1;
                if ($depth === 0) {
                    return substr($s, 0, $i + 1);
                }
            }
        }
        return $s;
    }

    private function streamOf(string $body): ?string
    {
        $pos = strpos($body, 'stream');
        if ($pos === false) {
            return null;
        }
        $dict = substr($body, 0, $pos);
        $start = $pos + 6;
        if (($body[$start] ?? '') === "\r") {
            $start++;
        }
        if (($body[$start] ?? '') === "\n") {
            $start++;
        }
        $end = strrpos($body, 'endstream');
        $raw = substr($body, $start, ($end === false ? strlen($body) : $end) - $start);
        $lenVal = $this->entry($dict, 'Length');
        if ($lenVal !== null) {
            $len = (int) $this->resolve($lenVal);
            if ($len > 0 && $len <= strlen($raw)) {
                $raw = substr($raw, 0, $len);
            }
        }
        $filters = [];
        $f = $this->entry($dict, 'Filter');
        if ($f !== null) {
            preg_match_all('/\/(\w+)/', $this->resolve($f), $fm);
            $filters = $fm[1];
        }
        foreach ($filters as $filter) {
            $raw = match ($filter) {
                'FlateDecode', 'Fl' => $this->inflate($raw),
                'ASCIIHexDecode', 'AHx' => (string) hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', rtrim($raw, '>')) . (strlen(preg_replace('/[^0-9A-Fa-f]/', '', rtrim($raw, '>'))) % 2 ? '0' : '')),
                'ASCII85Decode', 'A85' => $this->ascii85($raw),
                default => null,
            };
            if ($raw === null) {
                return null;
            }
        }
        return $raw;
    }

    private function inflate(string $raw): ?string
    {
        $out = @gzuncompress($raw);
        if ($out === false) {
            $out = @gzinflate(substr($raw, 2));
        }
        if ($out === false) {
            $out = @gzinflate($raw);
        }
        return $out === false ? null : $out;
    }

    private function ascii85(string $s): string
    {
        $s = preg_replace('/\s/', '', $s) ?? '';
        if (str_starts_with($s, '<~')) {
            $s = substr($s, 2);
        }
        $s = explode('~>', $s)[0];
        $out = '';
        $tuple = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === 'z' && !$tuple) {
                $out .= "\0\0\0\0";
                continue;
            }
            $tuple[] = ord($s[$i]) - 33;
            if (count($tuple) === 5) {
                $n = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85 + $tuple[4]);
                $out .= pack('N', $n);
                $tuple = [];
            }
        }
        if ($tuple) {
            $c = count($tuple);
            while (count($tuple) < 5) {
                $tuple[] = 84;
            }
            $n = (((($tuple[0] * 85 + $tuple[1]) * 85 + $tuple[2]) * 85 + $tuple[3]) * 85 + $tuple[4]);
            $out .= substr(pack('N', $n), 0, $c - 1);
        }
        return $out;
    }

    // -------------------------------------------------------------------- pages

    private function pages(): array
    {
        $root = null;
        if (preg_match_all('/\/Root\s+(\d+)\s+\d+\s+R/', $this->data, $m)) {
            $root = (int) end($m[1]);
        }
        $pagesRef = null;
        if ($root && ($pr = $this->entry($this->dictPart($this->obj($root)), 'Pages'))) {
            $pagesRef = $pr;
        }
        $pageList = [];
        if ($pagesRef) {
            $this->walk($pagesRef, null, $pageList, 0);
        }
        if (!$pageList) {
            // Fallback: every /Type /Page object in file order.
            foreach ($this->objects as $body) {
                $d = $this->dictPart($body);
                if (preg_match('/\/Type\s*\/Page(?![s\w])/', $d)) {
                    $pageList[] = ['dict' => $d, 'resources' => $this->entry($d, 'Resources')];
                }
            }
        }
        $segments = [];
        foreach (array_slice($pageList, 0, 1500) as $i => $page) {
            $text = '';
            try {
                $text = $this->pageText($page['dict'], $page['resources']);
            } catch (\Throwable) {
            }
            $segments[] = ['label' => 's. ' . ($i + 1), 'text' => $text];
        }
        return $segments;
    }

    private function walk(string $ref, ?string $inheritedRes, array &$out, int $depth): void
    {
        if ($depth > 30) {
            return;
        }
        $dict = $this->dictPart($this->resolve($ref));
        $res = $this->entry($dict, 'Resources') ?? $inheritedRes;
        $kids = $this->entry($dict, 'Kids');
        if ($kids !== null) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $this->resolve($kids), $km);
            foreach ($km[0] as $kid) {
                $this->walk($kid, $res, $out, $depth + 1);
            }
        } elseif (preg_match('/\/Type\s*\/Page(?![s\w])/', $dict) || $this->entry($dict, 'Contents') !== null) {
            $out[] = ['dict' => $dict, 'resources' => $res];
        }
    }

    private function pageText(string $pageDict, ?string $resources): string
    {
        $contents = $this->entry($pageDict, 'Contents');
        if ($contents === null) {
            return '';
        }
        $stream = '';
        $resolved = $this->resolve($contents);
        if (str_starts_with($resolved, '[')) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $resolved, $m);
            foreach ($m[1] as $n) {
                $stream .= ($this->streamOf($this->obj((int) $n)) ?? '') . "\n";
            }
        } else {
            $stream = $this->streamOf($resolved) ?? '';
        }
        return $this->clean($this->runContent($stream, $resources ? $this->resolve($resources) : '', 0));
    }

    private function fontMaps(string $resDict): array
    {
        $fonts = [];
        $fontDict = $this->entry($resDict, 'Font');
        if ($fontDict === null) {
            return $fonts;
        }
        $fontDict = $this->resolve($fontDict);
        preg_match_all('/\/([^\s\/\[\]<>()]+)\s+(\d+\s+\d+\s+R)/', $fontDict, $m, PREG_SET_ORDER);
        foreach ($m as $f) {
            $fonts[$f[1]] = $this->cmapFor($f[2]);
        }
        return $fonts;
    }

    /** @return array{map:array<string,string>,bytes:int} */
    private function cmapFor(string $fontRef): array
    {
        if (isset($this->cmapCache[$fontRef])) {
            return $this->cmapCache[$fontRef];
        }
        $dict = $this->dictPart($this->resolve($fontRef));
        $bytes = preg_match('/\/Encoding\s*\/Identity-[HV]/', $dict) || preg_match('/\/Subtype\s*\/Type0/', $dict) ? 2 : 1;
        $map = [];
        $tu = $this->entry($dict, 'ToUnicode');
        if ($tu !== null && preg_match('/(\d+)\s+\d+\s+R/', $tu, $m)) {
            $cmap = $this->streamOf($this->obj((int) $m[1])) ?? '';
            if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $cs)) {
                $bytes = max(1, intdiv(strlen($cs[1]), 2));
            }
            preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks);
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $map[strtoupper($p[1])] = $this->utf16($p[2]);
                }
            }
            preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks);
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]);
                    $hi = hexdec($r[2]);
                    $w = strlen($r[1]);
                    if ($hi - $lo > 5000) {
                        continue;
                    }
                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $dsts);
                        foreach ($dsts[1] as $k => $dst) {
                            $map[strtoupper(str_pad(dechex($lo + $k), $w, '0', STR_PAD_LEFT))] = $this->utf16($dst);
                        }
                    } else {
                        $base = trim($r[3], '<>');
                        $baseVal = hexdec(substr($base, -4));
                        $prefix = substr($base, 0, -4);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $dst = $prefix . str_pad(dechex($baseVal + ($c - $lo)), 4, '0', STR_PAD_LEFT);
                            $map[strtoupper(str_pad(dechex($c), $w, '0', STR_PAD_LEFT))] = $this->utf16($dst);
                        }
                    }
                }
            }
        }
        return $this->cmapCache[$fontRef] = ['map' => $map, 'bytes' => $bytes];
    }

    private function utf16(string $hex): string
    {
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 4 !== 0) {
            $hex = str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT);
        }
        return (string) mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }

    private function decodeString(string $bytes, ?array $font): string
    {
        if ($font && $font['map']) {
            $out = '';
            $hex = strtoupper(bin2hex($bytes));
            $step = $font['bytes'] * 2;
            for ($i = 0; $i < strlen($hex); $i += $step) {
                $code = substr($hex, $i, $step);
                $out .= $font['map'][$code] ?? ($font['map'][ltrim($code, '0') ?: '0'] ?? '');
            }
            return $out;
        }
        if ($font && $font['bytes'] === 2) {
            return ''; // CID font without ToUnicode: glyph ids are meaningless as text.
        }
        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private function literal(string $tok): string
    {
        $s = substr($tok, 1, -1);
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            $n = $s[++$i] ?? '';
            switch ($n) {
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\x0C"; break;
                case "\r": if (($s[$i + 1] ?? '') === "\n") { $i++; } break;
                case "\n": break;
                default:
                    if (ctype_digit($n) && $n < '8') {
                        $oct = $n;
                        for ($k = 0; $k < 2 && isset($s[$i + 1]) && $s[$i + 1] >= '0' && $s[$i + 1] < '8'; $k++) {
                            $oct .= $s[++$i];
                        }
                        $out .= chr(octdec($oct) & 0xFF);
                    } else {
                        $out .= $n;
                    }
            }
        }
        return $out;
    }

    private function runContent(string $stream, string $resDict, int $depth): string
    {
        if ($stream === '' || $depth > 3) {
            return '';
        }
        $fonts = $resDict !== '' ? $this->fontMaps($resDict) : [];
        $stream = preg_replace('/\bBI\b.*?\bID\b.*?\bEI\b/s', ' ', $stream) ?? $stream;
        preg_match_all('/\((?:\\\\.|[^\\\\()]|\((?:\\\\.|[^\\\\()])*\))*\)|<<|>>|<[0-9A-Fa-f\s]*>|\[|\]|\/[^\s\/\[\]()<>{}%]+|[-+]?(?:\d+\.?\d*|\.\d+)|[A-Za-z\'"*]+[0-9]?/s', $stream, $m);
        $out = '';
        $ops = [];
        $arr = null;
        $font = null;
        $lastY = null;
        foreach ($m[0] as $tok) {
            if ($tok === '[') {
                $arr = [];
                continue;
            }
            if ($tok === ']') {
                $ops[] = $arr ?? [];
                $arr = null;
                continue;
            }
            $c = $tok[0];
            $isOperand = $c === '(' || ($c === '<' && $tok !== '<<') || $c === '/' || is_numeric($tok);
            if ($isOperand) {
                if ($arr !== null) {
                    $arr[] = $tok;
                } else {
                    $ops[] = $tok;
                }
                continue;
            }
            if ($tok === '<<' || $tok === '>>') {
                continue;
            }
            switch ($tok) {
                case 'Tf':
                    $name = ltrim((string) ($ops[0] ?? ''), '/');
                    $font = $fonts[$name] ?? null;
                    break;
                case 'Tj':
                    $out .= $this->str((string) end($ops), $font);
                    break;
                case "'":
                case '"':
                    $out .= "\n" . $this->str((string) end($ops), $font);
                    break;
                case 'TJ':
                    $a = end($ops);
                    foreach (is_array($a) ? $a : [] as $part) {
                        if (is_numeric($part)) {
                            if ((float) $part < -180) {
                                $out .= ' ';
                            }
                        } else {
                            $out .= $this->str($part, $font);
                        }
                    }
                    break;
                case 'Td':
                case 'TD':
                    $ty = (float) ($ops[1] ?? 0);
                    $out .= abs($ty) > 0.01 ? "\n" : ' ';
                    break;
                case 'Tm':
                    $y = (float) ($ops[5] ?? 0);
                    if ($lastY !== null && abs($y - $lastY) > 0.5) {
                        $out .= "\n";
                    } elseif ($lastY !== null) {
                        $out .= ' ';
                    }
                    $lastY = $y;
                    break;
                case 'T*':
                    $out .= "\n";
                    break;
                case 'ET':
                    $out .= "\n";
                    break;
                case 'Do':
                    $name = ltrim((string) ($ops[0] ?? ''), '/');
                    $xobjs = $this->entry($resDict, 'XObject');
                    if ($xobjs !== null && preg_match('/\/' . preg_quote($name, '/') . '\s+(\d+)\s+\d+\s+R/', $this->resolve($xobjs), $xm)) {
                        $body = $this->obj((int) $xm[1]);
                        if (preg_match('/\/Subtype\s*\/Form/', $this->dictPart($body))) {
                            $res = $this->entry($this->dictPart($body), 'Resources');
                            $out .= "\n" . $this->runContent($this->streamOf($body) ?? '', $res ? $this->resolve($res) : $resDict, $depth + 1);
                        }
                    }
                    break;
            }
            $ops = [];
        }
        return $out;
    }

    private function str(string $tok, ?array $font): string
    {
        if ($tok === '') {
            return '';
        }
        if ($tok[0] === '(') {
            return $this->decodeString($this->literal($tok), $font);
        }
        if ($tok[0] === '<') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $tok) ?? '';
            if (strlen($hex) % 2) {
                $hex .= '0';
            }
            return $this->decodeString((string) hex2bin($hex), $font);
        }
        return '';
    }

    private function clean(string $s): string
    {
        $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $s) ?? $s;
        // Re-join words hyphenated across lines.
        $s = preg_replace('/(\p{Ll})-\n(\p{Ll})/u', '$1$2', $s) ?? $s;
        return trim($s);
    }
}

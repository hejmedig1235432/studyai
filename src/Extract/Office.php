<?php
declare(strict_types=1);

namespace App\Extract;

use App\HttpError;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/** Text extraction for .docx, .pptx and .xlsx (Office Open XML) using only ZipArchive + DOM. */
final class Office
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw HttpError::bad('Filen er beskadiget eller ikke et gyldigt Office-dokument.');
        }
        return $zip;
    }

    private static function xml(ZipArchive $zip, string $name): ?DOMDocument
    {
        $raw = $zip->getFromName($name);
        if ($raw === false || $raw === '') {
            return null;
        }
        if (strlen($raw) > 80 * 1024 * 1024) {
            throw HttpError::bad('Dokumentet er for stort til at blive behandlet.');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    // ---------------------------------------------------------------------- DOCX

    public static function docx(string $path): array
    {
        $zip = self::open($path);
        $doc = self::xml($zip, 'word/document.xml');
        if (!$doc) {
            throw HttpError::bad('Indholdet i Word-dokumentet kunne ikke læses.');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', self::W);
        $body = $xp->query('//w:body')->item(0);
        $segments = [];
        $label = 'Indledning';
        $buf = [];
        $flush = static function () use (&$segments, &$buf, &$label) {
            $t = trim(implode("\n", $buf));
            if ($t !== '') {
                $segments[] = ['label' => $label, 'text' => $t];
            }
            $buf = [];
        };
        if ($body) {
            foreach ($body->childNodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                if ($node->localName === 'p') {
                    $text = self::paraText($node);
                    if ($text === '') {
                        continue;
                    }
                    $style = '';
                    $ps = $xp->query('w:pPr/w:pStyle', $node)->item(0);
                    if ($ps instanceof DOMElement) {
                        $style = strtolower($ps->getAttributeNS(self::W, 'val'));
                    }
                    if (preg_match('/^(heading|kop|titre|berschrift|title|titel)\s*(\d?)/', $style, $hm) || preg_match('/^(heading|kop)(\d)/', $style, $hm)) {
                        $level = (int) ($hm[2] ?? 1) ?: 1;
                        if ($level <= 2) {
                            $flush();
                            $label = mb_substr($text, 0, 80);
                        }
                        $buf[] = str_repeat('#', min(4, $level)) . ' ' . $text;
                    } else {
                        $isList = $xp->query('w:pPr/w:numPr', $node)->length > 0;
                        $buf[] = ($isList ? '- ' : '') . $text;
                    }
                } elseif ($node->localName === 'tbl') {
                    foreach ($xp->query('.//w:tr', $node) as $tr) {
                        $cells = [];
                        foreach ($xp->query('./w:tc', $tr) as $tc) {
                            $parts = [];
                            foreach ($xp->query('.//w:p', $tc) as $p) {
                                $parts[] = self::paraText($p);
                            }
                            $cells[] = trim(implode(' ', $parts));
                        }
                        $buf[] = '| ' . implode(' | ', $cells) . ' |';
                    }
                    $buf[] = '';
                }
            }
        }
        $flush();
        // Footnotes are often exam-relevant.
        $fn = self::xml($zip, 'word/footnotes.xml');
        if ($fn) {
            $fx = new DOMXPath($fn);
            $fx->registerNamespace('w', self::W);
            $notes = [];
            foreach ($fx->query('//w:footnote') as $f) {
                $t = trim(implode(' ', array_map(fn ($p) => self::paraText($p), iterator_to_array($fx->query('.//w:p', $f)))));
                if ($t !== '') {
                    $notes[] = $t;
                }
            }
            if ($notes) {
                $segments[] = ['label' => 'Fodnoter', 'text' => implode("\n", $notes)];
            }
        }
        $zip->close();
        return $segments;
    }

    private static function paraText(DOMElement $p): string
    {
        $out = '';
        $walker = static function (\DOMNode $n) use (&$walker, &$out): void {
            foreach ($n->childNodes as $c) {
                if (!$c instanceof DOMElement) {
                    continue;
                }
                switch ($c->localName) {
                    case 't':
                        $out .= $c->textContent;
                        break;
                    case 'delText':   // tracked deletions
                    case 'instrText': // field codes
                        break;
                    case 'tab':
                        $out .= "\t";
                        break;
                    case 'br':
                    case 'cr':
                        $out .= "\n";
                        break;
                    default:
                        $walker($c);
                }
            }
        };
        $walker($p);
        return trim(preg_replace('/[ \t]+/', ' ', $out) ?? $out);
    }

    // ---------------------------------------------------------------------- PPTX

    public static function pptx(string $path): array
    {
        $zip = self::open($path);
        $slides = [];
        // Respect presentation order when available.
        $pres = self::xml($zip, 'ppt/presentation.xml');
        $rels = self::xml($zip, 'ppt/_rels/presentation.xml.rels');
        if ($pres && $rels) {
            $relMap = [];
            foreach ($rels->getElementsByTagName('Relationship') as $r) {
                $relMap[$r->getAttribute('Id')] = 'ppt/' . ltrim(str_replace('../', '', $r->getAttribute('Target')), '/');
            }
            foreach ($pres->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId') as $s) {
                $rid = $s->getAttributeNS(self::R, 'id');
                if (isset($relMap[$rid])) {
                    $slides[] = $relMap[$rid];
                }
            }
        }
        if (!$slides) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $n, $m)) {
                    $slides[(int) $m[1]] = $n;
                }
            }
            ksort($slides);
            $slides = array_values($slides);
        }
        $segments = [];
        foreach ($slides as $idx => $slidePath) {
            $doc = self::xml($zip, $slidePath);
            if (!$doc) {
                continue;
            }
            $text = self::drawingText($doc);
            // Speaker notes via the slide's relationships.
            $relPath = preg_replace('~slides/(slide\d+\.xml)$~', 'slides/_rels/$1.rels', $slidePath);
            $srels = self::xml($zip, (string) $relPath);
            if ($srels) {
                foreach ($srels->getElementsByTagName('Relationship') as $r) {
                    if (str_contains($r->getAttribute('Type'), 'notesSlide')) {
                        $np = 'ppt/' . str_replace('../', '', $r->getAttribute('Target'));
                        $ndoc = self::xml($zip, $np);
                        if ($ndoc) {
                            $notes = trim(preg_replace('/^\d+$/m', '', self::drawingText($ndoc)) ?? '');
                            if ($notes !== '') {
                                $text .= "\n\nNoter til oplægsholder:\n" . $notes;
                            }
                        }
                    }
                }
            }
            if (trim($text) !== '') {
                $segments[] = ['label' => 'Slide ' . ($idx + 1), 'text' => trim($text)];
            }
        }
        $zip->close();
        return $segments;
    }

    private static function drawingText(DOMDocument $doc): string
    {
        $lines = [];
        foreach ($doc->getElementsByTagNameNS(self::A, 'p') as $p) {
            $t = '';
            foreach ($p->getElementsByTagNameNS(self::A, 't') as $tn) {
                $t .= $tn->textContent;
            }
            $t = trim($t);
            if ($t !== '') {
                $lines[] = $t;
            }
        }
        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------- XLSX

    public static function xlsx(string $path): array
    {
        $zip = self::open($path);
        $shared = [];
        $ss = self::xml($zip, 'xl/sharedStrings.xml');
        if ($ss) {
            foreach ($ss->getElementsByTagNameNS(self::S, 'si') as $si) {
                $t = '';
                foreach ($si->getElementsByTagNameNS(self::S, 't') as $tn) {
                    $t .= $tn->textContent;
                }
                $shared[] = $t;
            }
        }
        $sheets = [];
        $wb = self::xml($zip, 'xl/workbook.xml');
        $wbRels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $relMap = [];
        if ($wbRels) {
            foreach ($wbRels->getElementsByTagName('Relationship') as $r) {
                $t = $r->getAttribute('Target');
                $relMap[$r->getAttribute('Id')] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
            }
        }
        if ($wb) {
            foreach ($wb->getElementsByTagNameNS(self::S, 'sheet') as $s) {
                $rid = $s->getAttributeNS(self::R, 'id');
                if (isset($relMap[$rid])) {
                    $sheets[] = ['name' => $s->getAttribute('name'), 'path' => $relMap[$rid]];
                }
            }
        }
        $segments = [];
        $totalChars = 0;
        foreach ($sheets as $sheet) {
            $doc = self::xml($zip, $sheet['path']);
            if (!$doc) {
                continue;
            }
            $rows = [];
            foreach ($doc->getElementsByTagNameNS(self::S, 'row') as $ri => $row) {
                if ($ri >= 5000 || $totalChars > 400000) {
                    $rows[] = '… (arket er afkortet)';
                    break;
                }
                $cells = [];
                foreach ($row->getElementsByTagNameNS(self::S, 'c') as $c) {
                    $type = $c->getAttribute('t');
                    $v = $c->getElementsByTagNameNS(self::S, 'v')->item(0)?->textContent ?? '';
                    if ($type === 's') {
                        $v = $shared[(int) $v] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $v = $c->getElementsByTagNameNS(self::S, 't')->item(0)?->textContent ?? '';
                    } elseif ($type === 'b') {
                        $v = $v === '1' ? 'TRUE' : 'FALSE';
                    }
                    $cells[] = trim((string) $v);
                }
                while ($cells && end($cells) === '') {
                    array_pop($cells);
                }
                if ($cells) {
                    $line = implode(' | ', $cells);
                    $totalChars += strlen($line);
                    $rows[] = $line;
                }
            }
            // ~40 rows per segment keeps table context together.
            foreach (array_chunk($rows, 40) as $k => $part) {
                $segments[] = ['label' => 'Ark "' . $sheet['name'] . '"' . ($k ? ' (fortsat)' : ''), 'text' => implode("\n", $part)];
            }
        }
        $zip->close();
        return $segments;
    }
}

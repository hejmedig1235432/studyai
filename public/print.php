<?php
declare(strict_types=1);

// Printable study guide. The browser's "Save as PDF" turns it into a PDF.
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\DB;
use App\Retriever;

Auth::startSession();
$user = Auth::user();
if (!$user) {
    header('Location: ./#/login');
    exit;
}
$subject = DB::one('SELECT * FROM subjects WHERE id = ? AND user_id = ?', [(int) ($_GET['subject_id'] ?? 0), $user['id']]);
if (!$subject) {
    http_response_code(404);
    exit('Faget blev ikke fundet.');
}
$sections = DB::all('SELECT g.* FROM guide_sections g LEFT JOIN topics t ON t.id = g.topic_id
    WHERE g.subject_id = ? AND (g.topic_id IS NULL OR t.active = 1) ORDER BY g.position, g.id', [$subject['id']]);
$ids = [];
foreach ($sections as $s) {
    $ids = array_merge($ids, Retriever::citedIds($s['content']));
}
$refs = Retriever::refMeta($ids, (int) $user['id']);
// Number citations in order of first appearance, like footnotes.
$numbers = [];
foreach (array_unique($ids) as $id) {
    if (isset($refs[$id])) {
        $numbers[$id] = count($numbers) + 1;
    }
}
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
header('X-Frame-Options: DENY');
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($subject['name']) ?> – Studieguide</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<style>
  :root { color-scheme: light; }
  body { font: 11.5pt/1.6 Inter, system-ui, sans-serif; color: #111827; max-width: 760px; margin: 0 auto; padding: 32px 24px 64px; background: #fff; }
  header { border-bottom: 2px solid #111827; padding-bottom: 12px; margin-bottom: 24px; }
  header h1 { margin: 0; font-size: 24pt; letter-spacing: -.02em; }
  header p { margin: 4px 0 0; color: #6b7280; font-size: 10pt; }
  h2.sec { font-size: 15pt; margin: 28px 0 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; break-after: avoid; }
  h3 { font-size: 10pt; text-transform: uppercase; letter-spacing: .06em; color: #4b5563; margin: 16px 0 6px; break-after: avoid; }
  ul, ol { padding-left: 20px; margin: 6px 0 10px; }
  li { margin: 2px 0; }
  p { margin: 6px 0 10px; }
  blockquote { border-left: 3px solid #f59e0b; background: #fffbeb; margin: 8px 0; padding: 6px 12px; }
  mark { background: #fef08a; padding: 0 2px; }
  table { border-collapse: collapse; width: 100%; font-size: 10pt; }
  th, td { border: 1px solid #d1d5db; padding: 4px 8px; text-align: left; }
  sup.ref { color: #4f46e5; font-size: 7.5pt; font-weight: 600; }
  .sources { margin-top: 36px; border-top: 2px solid #111827; padding-top: 10px; font-size: 9.5pt; color: #374151; }
  .sources li { margin: 3px 0; }
  .bar { position: sticky; top: 0; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 10px 14px; margin-bottom: 24px; display: flex; gap: 10px; align-items: center; font-size: 10pt; }
  .bar button { font: inherit; padding: 6px 14px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; cursor: pointer; font-weight: 600; }
  @media print { .bar { display: none; } body { padding: 0; } @page { margin: 18mm 16mm; } }
</style>
</head>
<body>
<div class="bar"><span style="flex:1">Vælg <b>Gem som PDF</b> som printer for at eksportere guiden.</span><button onclick="window.print()">Udskriv / Gem som PDF</button></div>
<header>
  <h1><?= $e($subject['name']) ?></h1>
  <p>Studieguide · lavet ud fra dine kilder · <?= $e(date('j/n/Y')) ?></p>
</header>
<main id="content"></main>
<?php if ($numbers): ?>
<section class="sources">
  <b>Kilder</b>
  <ol>
  <?php foreach ($numbers as $id => $n): $r = $refs[$id]; ?>
    <li value="<?= $n ?>"><?= $e($r['source_title']) ?><?= $r['label'] ? ' — ' . $e($r['label']) : '' ?></li>
  <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
<script>
  const sections = <?= json_encode(array_map(static fn ($s) => ['title' => $s['title'], 'content' => $s['content']], $sections), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  const numbers = <?= json_encode((object) $numbers) ?>;
  const out = document.getElementById('content');
  for (const s of sections) {
    const h = document.createElement('h2');
    h.className = 'sec';
    h.textContent = s.title;
    out.appendChild(h);
    let src = s.content.replace(/==([^=\n][^\n]*?)==/g, '<mark>$1</mark>');
    let html = DOMPurify.sanitize(marked.parse(src), { ADD_TAGS: ['mark', 'details', 'summary'] });
    html = html.replace(/\[C(\d+)\]/g, (_, id) => numbers[id] ? `<sup class="ref">[${numbers[id]}]</sup>` : '');
    const div = document.createElement('div');
    div.innerHTML = html;
    out.appendChild(div);
  }
  if (!sections.length) out.textContent = 'Studieguiden er tom.';
  setTimeout(() => window.print(), 600);
</script>
</body>
</html>

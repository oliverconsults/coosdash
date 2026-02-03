<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$pdo = db();
require_once __DIR__ . '/../scripts/migrate_project_reports.php';

// Very small HTML sanitizer for LLM output (allow basic formatting, remove scripts/styles/on* attrs).
function report_sanitize_html(string $html): string {
  $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
  $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
  $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
  $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $html);
  return (string)$html;
}

$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
if ($reportId <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'missing report_id';
  exit;
}

$st = $pdo->prepare('SELECT * FROM project_reports WHERE id=?');
$st->execute([$reportId]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'report not found';
  exit;
}

if ((string)($r['status'] ?? '') !== 'done') {
  http_response_code(409);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'report not ready';
  exit;
}

$htmlFile = (string)($r['html_file'] ?? '');
if ($htmlFile === '') {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'missing html_file';
  exit;
}

$src = '/var/www/coosdash/shared/reports/' . basename($htmlFile);
$html = '';
if (is_file($src)) $html = (string)@file_get_contents($src);
if ($html === '') $html = '<div class="meta">(leer)</div>';
$html = report_sanitize_html($html);

// Filename requirement: use document.title so "Save as PDF" picks it up.
$ts = (string)($r['generated_at'] ?? $r['created_at'] ?? '');
$dt = $ts ? (DateTime::createFromFormat('Y-m-d H:i:s', $ts) ?: new DateTime($ts)) : new DateTime();
$tsName = $dt->format('Y-m-d H:i:s');

$title = (string)($r['project_title'] ?? 'coos report');
$slug = (string)($r['slug'] ?? '');
$docTitle = trim($title . ($slug !== '' ? (' / ' . $slug) : '') . ' ' . $tsName);

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($docTitle); ?></title>
  <base href="/">
  <style>
    @media print {
      .no-print { display:none !important; }
      body { margin: 0; }
    }
    body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-size:13px; line-height:1.45; padding:24px; }
    .toolbar { position: sticky; top: 0; background: #fff; padding: 10px 0; margin-bottom: 12px; border-bottom: 1px solid #eee; }
    .meta { color:#667085; font-size:12px; }
    table { border-collapse: collapse; }
    td,th { border:1px solid #ddd; padding:6px 8px; }
    a { color: #2563eb; }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <div style="display:flex; gap:10px; align-items:center;">
      <button class="btn" onclick="window.print()">PDF / Drucken</button>
      <a class="btn" href="/report.php?report_id=<?php echo (int)$reportId; ?>">Zurück</a>
      <span class="meta">Tipp: Im Druck-Dialog "Als PDF speichern". Dateiname = Dokumenttitel (inkl. Timestamp).</span>
    </div>
  </div>

  <?php echo $html; ?>

  <script>
    // Auto-open print dialog for one-click PDF export
    window.addEventListener('load', () => {
      setTimeout(() => { try { window.print(); } catch(e) {} }, 350);
    });
  </script>
</body>
</html>

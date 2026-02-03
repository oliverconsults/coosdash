<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$pdo = db();
require_once __DIR__ . '/../scripts/migrate_project_reports.php';

$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
if ($reportId <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "missing report_id";
  exit;
}

$st = $pdo->prepare('SELECT * FROM project_reports WHERE id=?');
$st->execute([$reportId]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "report not found";
  exit;
}

if ((string)($r['status'] ?? '') !== 'done') {
  http_response_code(409);
  header('Content-Type: text/plain; charset=utf-8');
  echo "report not ready";
  exit;
}

$htmlFile = (string)($r['html_file'] ?? '');
if ($htmlFile === '') {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "missing html_file";
  exit;
}

$src = '/var/www/coosdash/shared/reports/' . basename($htmlFile);
if (!is_file($src)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "html file missing";
  exit;
}

// Determine timestamp for filename
$ts = (string)($r['generated_at'] ?? $r['created_at'] ?? '');
$dt = $ts ? (DateTime::createFromFormat('Y-m-d H:i:s', $ts) ?: new DateTime($ts)) : new DateTime();
$tsName = $dt->format('Y-m-d H:i:s'); // requested format

$projectTitle = (string)($r['project_title'] ?? 'report');
$projectTitle = preg_replace('/[^A-Za-z0-9 _.-]+/u', '_', $projectTitle);
$projectTitle = trim((string)$projectTitle);
if ($projectTitle === '') $projectTitle = 'report';

$downloadName = $projectTitle . ' ' . $tsName . '.pdf';

// Build printable HTML wrapper (base href fixes relative links like /att/...)
$html = (string)@file_get_contents($src);
$host = (string)($_SERVER['HTTP_HOST'] ?? 'admin.coos.eu');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$baseHref = $scheme . '://' . $host . '/';

$wrapped = "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n";
$wrapped .= "<base href=\"" . htmlspecialchars($baseHref, ENT_QUOTES) . "\">\n";
$wrapped .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
$wrapped .= "<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;line-height:1.45;margin:24px;} h1,h2,h3{margin:0 0 12px 0;} .meta{color:#667085;font-size:12px;} table{border-collapse:collapse;} td,th{border:1px solid #ddd;padding:6px 8px;}</style>\n";
$wrapped .= "</head><body>\n";
$wrapped .= $html;
$wrapped .= "\n</body></html>\n";

$tmpDir = '/var/www/coosdash/shared/reports/tmp';
@mkdir($tmpDir, 0775, true);
$tmpBase = $tmpDir . '/report_' . (int)$reportId . '_' . bin2hex(random_bytes(6));
$tmpHtml = $tmpBase . '.html';
$tmpPdf = $tmpBase . '.pdf';
@file_put_contents($tmpHtml, $wrapped);

$chromium = '/snap/bin/chromium';
if (!is_file($chromium)) {
  @unlink($tmpHtml);
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "chromium not found";
  exit;
}

$cmd = escapeshellarg($chromium)
  . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
  . ' --print-to-pdf=' . escapeshellarg($tmpPdf)
  . ' ' . escapeshellarg($tmpHtml);

$o = [];
$code = 0;
exec($cmd . ' 2>&1', $o, $code);

if ($code !== 0 || !is_file($tmpPdf)) {
  @unlink($tmpHtml);
  @unlink($tmpPdf);
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "pdf generation failed\n" . implode("\n", array_slice($o, -25));
  exit;
}

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
header('Content-Length: ' . (string)filesize($tmpPdf));

readfile($tmpPdf);

@unlink($tmpHtml);
@unlink($tmpPdf);

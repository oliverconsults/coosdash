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

function fmt_km(int $n): string {
  $abs = abs($n);
  if ($abs >= 1_000_000) {
    $v = round($n / 1_000_000, 1);
    $s = rtrim(rtrim((string)$v, '0'), '.');
    return $s . 'M';
  }
  if ($abs >= 1_000) {
    $v = round($n / 1_000, 1);
    $s = rtrim(rtrim((string)$v, '0'), '.');
    return $s . 'K';
  }
  return (string)$n;
}

// Best-effort token formatting in rendered report HTML.
// We don't rely on the LLM to format numbers consistently.
function report_format_tokens_km(string $html): string {
  // 1) Generic: token_in/out/all: 123 / 456 / 789
  $html = preg_replace_callback('/(token(?:_in|_out|_all)?[^0-9]{0,40})(\d{4,})(?:\s*\/\s*(\d{4,}))?(?:\s*\/\s*(\d{4,}))?/i', function($m) {
    $prefix = (string)($m[1] ?? '');
    $a = fmt_km((int)($m[2] ?? 0));
    $b = isset($m[3]) && $m[3] !== '' ? fmt_km((int)$m[3]) : '';
    $c = isset($m[4]) && $m[4] !== '' ? fmt_km((int)$m[4]) : '';
    $tail = '';
    if ($b !== '') $tail .= ' / ' . $b;
    if ($c !== '') $tail .= ' / ' . $c;
    return $prefix . $a . $tail;
  }, $html);

  // 2) Tokens table: numbers inside <code>996706 / 56616 / 1053322</code>
  $html = preg_replace_callback('/<code>(\s*\d{4,}(?:\s*\/\s*\d{4,}){1,}\s*)<\/code>/i', function($m) {
    $s = (string)($m[1] ?? '');
    $parts = preg_split('/\s*\/\s*/', trim($s));
    $parts = array_map(function($x) {
      $x = preg_replace('/[^0-9\-]/', '', $x);
      if ($x === '' || !preg_match('/^-?\d+$/', $x)) return $x;
      return fmt_km((int)$x);
    }, $parts);
    return '<code>' . implode(' / ', $parts) . '</code>';
  }, $html);

  return $html;
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (@scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir . '/' . $f;
    if (is_dir($p) && !is_link($p)) rrmdir($p);
    else @unlink($p);
  }
  @rmdir($dir);
}

function ensureWkhtmltopdf(): string {
  $binDir = '/var/www/coosdash/shared/bin';
  @mkdir($binDir, 0775, true);
  $bin = $binDir . '/wkhtmltopdf';
  if (is_file($bin) && is_executable($bin)) return $bin;

  // Download a prebuilt wkhtmltopdf. (Best-effort; no LLM involved.)
  $url = 'https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox_0.12.6.1-2.jammy_amd64.deb';
  $tmpBase = sys_get_temp_dir() . '/wkhtml_' . bin2hex(random_bytes(6));
  $deb = $tmpBase . '.deb';
  $dir = $tmpBase . '_dir';
  @mkdir($dir, 0775, true);

  $cmdDl = '/usr/bin/curl -L --fail --silent --show-error -o ' . escapeshellarg($deb) . ' ' . escapeshellarg($url);
  $o=[]; $c=0; exec($cmdDl . ' 2>&1', $o, $c);
  if ($c !== 0 || !is_file($deb)) {
    throw new RuntimeException('wkhtmltopdf download failed');
  }

  // Extract deb (ar + tar)
  $cmd1 = '/usr/bin/ar x ' . escapeshellarg($deb);
  $o=[]; $c=0; exec('cd ' . escapeshellarg(dirname($deb)) . ' && ' . $cmd1 . ' 2>&1', $o, $c);
  if ($c !== 0) throw new RuntimeException('wkhtmltopdf deb extract failed');

  $dataTar = '';
  foreach (['data.tar.xz','data.tar.zst','data.tar.gz'] as $cand) {
    $p = dirname($deb) . '/' . $cand;
    if (is_file($p)) { $dataTar = $p; break; }
  }
  if ($dataTar === '') throw new RuntimeException('wkhtmltopdf deb missing data.tar');

  // Try tar extraction (xz/gz). zst might fail; but jammy package typically xz.
  $cmdTar = '/usr/bin/tar -xf ' . escapeshellarg($dataTar) . ' -C ' . escapeshellarg($dir);
  $o=[]; $c=0; exec($cmdTar . ' 2>&1', $o, $c);
  if ($c !== 0) throw new RuntimeException('wkhtmltopdf data.tar extract failed');

  $src = $dir . '/usr/local/bin/wkhtmltopdf';
  if (!is_file($src)) {
    // some packages use /usr/bin
    $src = $dir . '/usr/bin/wkhtmltopdf';
  }
  if (!is_file($src)) throw new RuntimeException('wkhtmltopdf binary not found in package');

  if (!@copy($src, $bin)) throw new RuntimeException('wkhtmltopdf install copy failed');
  @chmod($bin, 0775);

  // cleanup
  @unlink($deb);
  rrmdir($dir);
  foreach (['control.tar.xz','control.tar.zst','control.tar.gz','data.tar.xz','data.tar.zst','data.tar.gz','debian-binary'] as $f) {
    $p = dirname($deb) . '/' . $f;
    if (is_file($p)) @unlink($p);
  }

  if (!is_file($bin) || !is_executable($bin)) {
    throw new RuntimeException('wkhtmltopdf install failed');
  }
  return $bin;
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
if (!is_file($src)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'html file missing';
  exit;
}

// Timestamp for filename
$ts = (string)($r['generated_at'] ?? $r['created_at'] ?? '');
$dt = $ts ? (DateTime::createFromFormat('Y-m-d H:i:s', $ts) ?: new DateTime($ts)) : new DateTime();
$tsName = $dt->format('Y-m-d H:i:s');

$projectTitle = (string)($r['project_title'] ?? 'report');
$projectTitleSafe = preg_replace('/[^A-Za-z0-9 _.-]+/u', '_', $projectTitle);
$projectTitleSafe = trim((string)$projectTitleSafe);
if ($projectTitleSafe === '') $projectTitleSafe = 'report';

$downloadName = $projectTitleSafe . ' ' . $tsName . '.pdf';

// Cache pdf per report id.
// IMPORTANT: include template version in cache key so style changes take effect immediately.
$pdfDir = '/var/www/coosdash/shared/reports/pdf';
@mkdir($pdfDir, 0775, true);
$tplVer = (int)@filemtime(__FILE__);
$cachePdf = $pdfDir . '/report_' . (int)$reportId . '_v' . $tplVer . '.pdf';
if (is_file($cachePdf) && filemtime($cachePdf) >= filemtime($src)) {
  header('Content-Type: application/pdf');
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
  header('Content-Length: ' . (string)filesize($cachePdf));
  readfile($cachePdf);
  exit;
}

$html = (string)@file_get_contents($src);
$html = report_sanitize_html($html);
$html = report_format_tokens_km($html);

// Build printable HTML wrapper (white background, COOS styling)
$host = (string)($_SERVER['HTTP_HOST'] ?? 'admin.coos.eu');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$baseHref = $scheme . '://' . $host . '/';

$slug = (string)($r['slug'] ?? '');
$subtitle = trim(($slug !== '' ? ($slug . ' · ') : '') . $tsName . ' · Report #' . (int)$reportId);

$logoUrl = $scheme . '://' . $host . '/assets/coos_logo_gold.png';
$gold = '#c7a24a';

$wrapped = "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n";
$wrapped .= "<base href=\"" . htmlspecialchars($baseHref, ENT_QUOTES) . "\">\n";
$wrapped .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
$wrapped .= "<style>\n";
$wrapped .= "@page { size: A4; margin: 14mm 12mm 18mm 12mm; }\n";
$wrapped .= "*{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }\n";
$wrapped .= "body{ background:#fff; color:#0b1220; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; font-size: 12.5px; line-height: 1.5; }\n";
$wrapped .= ".header{ padding-bottom:10px; border-bottom:1px solid #e6e8ee; }\n";
// wkhtmltopdf's flexbox support is spotty → use table layout for stable branding row.
$wrapped .= ".brandTbl{ width:100%; border-collapse:collapse; }\n";
$wrapped .= ".brandTbl td{ border:0; padding:0; vertical-align:middle; }\n";
$wrapped .= ".logoCell{ width:1%; white-space:nowrap; }\n";
$wrapped .= ".spacerCell{ width:35px; }\n";
$wrapped .= ".logoCell img{ height:36px; width:auto; }\n";
$wrapped .= ".wordmark{ font-weight:800; font-size:19px; letter-spacing:.2px; line-height:1; }\n";
$wrapped .= ".wordmark .eu{ color:" . $gold . "; }\n";
$wrapped .= ".title{ font-size:18px; font-weight:700; margin:0; }\n";
$wrapped .= ".subtitle{ font-size:11px; color:#667085; margin-top:0; }\n";
$wrapped .= ".content{ padding-top:14px; }\n";
$wrapped .= "h1,h2,h3{ color:#0b1220; }\n";
$wrapped .= "h2{ margin:16px 0 8px; font-size:14px; }\n";
$wrapped .= ".meta{ color:#667085; font-size:11px; }\n";
$wrapped .= "table{ border-collapse: collapse; width:100%; }\n";
$wrapped .= "td,th{ border:1px solid #e6e8ee; padding:6px 8px; vertical-align:top; }\n";
$wrapped .= "th{ background:#f8fafc; font-weight:600; }\n";
$wrapped .= "a{ color:#1d4ed8; text-decoration:none; }\n";
$wrapped .= "code,pre{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; }\n";
$wrapped .= "</style>\n";
$wrapped .= "</head><body>\n";
$wrapped .= "<div class=\"header\">\n";
$wrapped .= "  <table class=\"brandTbl\">\n";
$wrapped .= "    <tr>\n";
$wrapped .= "      <td class=\"logoCell\"><img src=\"" . htmlspecialchars($logoUrl, ENT_QUOTES) . "\" alt=\"COOS\"></td>\n";
$wrapped .= "      <td class=\"spacerCell\"></td>\n";
$wrapped .= "      <td>\n";
$wrapped .= "        <div class=\"wordmark\">COOS<span class=\"eu\">.eu</span></div>\n";
$wrapped .= "        <div class=\"subtitle\">" . htmlspecialchars($subtitle, ENT_QUOTES) . "</div>\n";
$wrapped .= "      </td>\n";
$wrapped .= "    </tr>\n";
$wrapped .= "  </table>\n";
$wrapped .= "</div>\n";
$wrapped .= "<div class=\"content\">\n";
$wrapped .= "<div class=\"title\">" . htmlspecialchars($projectTitle, ENT_QUOTES) . "</div>\n";
$wrapped .= $html;
$wrapped .= "</div>\n";
$wrapped .= "</body></html>\n";

$tmpDir = '/var/www/coosdash/shared/reports/tmp';
@mkdir($tmpDir, 0775, true);
$tmpBase = $tmpDir . '/report_' . (int)$reportId . '_' . bin2hex(random_bytes(6));
$tmpHtml = $tmpBase . '.html';
$tmpPdf = $tmpBase . '.pdf';
@file_put_contents($tmpHtml, $wrapped);

try {
  $wk = ensureWkhtmltopdf();
} catch (Throwable $e) {
  @unlink($tmpHtml);
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'PDF engine install failed';
  exit;
}

$footerRight = 'Seite [page]/[topage]';
$cmd = escapeshellarg($wk)
  . ' --quiet'
  . ' --encoding utf-8'
  . ' --page-size A4'
  . ' --margin-top 14mm --margin-right 12mm --margin-bottom 18mm --margin-left 12mm'
  . ' --print-media-type'
  . ' --enable-local-file-access'
  . ' --footer-right ' . escapeshellarg($footerRight)
  . ' --footer-font-size 9'
  . ' --footer-spacing 6'
  . ' ' . escapeshellarg($tmpHtml)
  . ' ' . escapeshellarg($tmpPdf);

$o=[]; $code=0; exec($cmd . ' 2>&1', $o, $code);

@unlink($tmpHtml);

if ($code !== 0 || !is_file($tmpPdf)) {
  @unlink($tmpPdf);
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "pdf generation failed\n" . implode("\n", array_slice($o, -25));
  exit;
}

// Atomically update cache
@rename($tmpPdf, $cachePdf);
@chmod($cachePdf, 0664);

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
header('Content-Length: ' . (string)filesize($cachePdf));
readfile($cachePdf);

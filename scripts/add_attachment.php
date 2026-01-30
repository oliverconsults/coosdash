<?php
// James helper: attach a local file to a COOS CRM node.
// Copies the file into the public shared attachment area with a random token URL,
// then registers it in cooscrm.node_attachments.
//
// Usage:
//   php add_attachment.php <node_id> /abs/path/to/file [display_name]
//
// Output:
//   URL: https://coos.eu/att/<token>/<stored_name>

require_once __DIR__ . '/../public/functions.inc.php';

$nodeId = (int)($argv[1] ?? 0);
$src = (string)($argv[2] ?? '');
$displayName = (string)($argv[3] ?? '');

$isUrl = (bool)preg_match('~^https?://~i', $src);

if ($nodeId <= 0 || $src === '' || (!$isUrl && !is_file($src))) {
  fwrite(STDERR, "Usage: php add_attachment.php <node_id> /abs/path/to/file|https://url [display_name]\n");
  exit(1);
}

$pdo = db();
$st = $pdo->prepare('SELECT id FROM nodes WHERE id=?');
$st->execute([$nodeId]);
if (!$st->fetch()) {
  fwrite(STDERR, "Node not found: {$nodeId}\n");
  exit(1);
}

$orig = ($displayName !== '' ? $displayName : ($isUrl ? $src : basename($src)));

// Allowed extensions (preferred: url, doc, img, pdf, excel)
// NOTE: We allow code-like types too (php/sql/json/etc.) because Oliver wants the raw artifacts.
$allowedExt = [
  // preferred
  'url','pdf','png','jpg','jpeg','webp','gif','doc','docx','xls','xlsx',
  // common business artifacts
  'csv','ppt','pptx'
];

// sanitize filename
$stored = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
$stored = trim($stored, '._');
if ($stored === '') $stored = ($isUrl ? 'link.url' : 'file');

$ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
if ($isUrl) {
  // For URL attachments we publish a small HTML redirect under a token URL.
  // UI label stays .url (orig_name), stored file becomes .html.
  if ($ext !== 'url') $stored .= '.url';
  $stored = preg_replace('/\.url$/i', '.html', $stored);
  $ext = 'html';
} else {
  if ($ext !== '' && !in_array($ext, $allowedExt, true)) {
    fwrite(STDERR, "Disallowed file type: .{$ext}\nAllowed: " . implode(',', $allowedExt) . "\n");
    exit(1);
  }
}

$token = bin2hex(random_bytes(16));
$baseDir = '/var/www/coosdash/shared/att';
$destDir = $baseDir . '/' . $token;
if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
  fwrite(STDERR, "Failed to create dest dir: {$destDir}\n");
  exit(1);
}

$dest = $destDir . '/' . $stored;

if ($isUrl) {
  $targetUrl = $src;
  $html = "<!doctype html><meta charset=\"utf-8\"><title>Redirect</title>\n" .
          "<meta http-equiv=\"refresh\" content=\"0; url=" . htmlspecialchars($targetUrl, ENT_QUOTES) . "\">\n" .
          "<p>Redirecting to <a href=\"" . htmlspecialchars($targetUrl, ENT_QUOTES) . "\">" . htmlspecialchars($targetUrl) . "</a></p>\n";
  if (file_put_contents($dest, $html) === false) {
    fwrite(STDERR, "Write failed: {$dest}\n");
    exit(1);
  }
  @chmod($dest, 0664);
  $mime = 'text/html';
  $size = @filesize($dest);
  if ($size === false) $size = null;
} else {
  if (!copy($src, $dest)) {
    fwrite(STDERR, "Copy failed: {$src} -> {$dest}\n");
    exit(1);
  }
  @chmod($dest, 0664);

  $mime = null;
  if (function_exists('mime_content_type')) {
    $m = @mime_content_type($dest);
    if (is_string($m) && $m !== '') $mime = $m;
  }
  $size = @filesize($dest);
  if ($size === false) $size = null;
}

// If URL attachment: display as *.url in UI
$origForDb = $orig;
if ($isUrl && !preg_match('/\.url$/i', $origForDb)) $origForDb = $origForDb . '.url';

$pdo->prepare('INSERT INTO node_attachments (node_id, token, orig_name, stored_name, mime, size_bytes, created_by) VALUES (?,?,?,?,?,?,\'james\')')
    ->execute([$nodeId, $token, $origForDb, $stored, $mime, $size]);

// also prepend a short line into description (optional but nice)
$ts = date('d.m.Y H:i');
$line = "[james] {$ts} Attachment: {$orig} (/att/{$token}/{$stored})\n\n";
$pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);

$url = '/att/' . $token . '/' . $stored;
echo "OK\n";
echo "URL: {$url}\n";

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

if ($nodeId <= 0 || $src === '' || !is_file($src)) {
  fwrite(STDERR, "Usage: php add_attachment.php <node_id> /abs/path/to/file [display_name]\n");
  exit(1);
}

$pdo = db();
$st = $pdo->prepare('SELECT id FROM nodes WHERE id=?');
$st->execute([$nodeId]);
if (!$st->fetch()) {
  fwrite(STDERR, "Node not found: {$nodeId}\n");
  exit(1);
}

$orig = ($displayName !== '' ? $displayName : basename($src));
// sanitize filename
$stored = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
$stored = trim($stored, '._');
if ($stored === '') $stored = 'file';

$token = bin2hex(random_bytes(16));
$baseDir = '/var/www/coosdash/shared/att';
$destDir = $baseDir . '/' . $token;
if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
  fwrite(STDERR, "Failed to create dest dir: {$destDir}\n");
  exit(1);
}

$dest = $destDir . '/' . $stored;
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

$pdo->prepare('INSERT INTO node_attachments (node_id, token, orig_name, stored_name, mime, size_bytes, created_by) VALUES (?,?,?,?,?,?,\'james\')')
    ->execute([$nodeId, $token, $orig, $stored, $mime, $size]);

// also prepend a short line into description (optional but nice)
$ts = date('d.m.Y H:i');
$line = "[james] {$ts} Attachment: {$orig} (/att/{$token}/{$stored})\n\n";
$pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);

$url = '/att/' . $token . '/' . $stored;
echo "OK\n";
echo "URL: {$url}\n";

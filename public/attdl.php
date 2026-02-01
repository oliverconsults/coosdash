<?php
// Tokenized attachment download handler.
// Serves files from /var/www/coosdash/shared/att/<token>/<stored_name>
// while forcing download (prevents accidental execution of .php etc.).

require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? ''));
$file = (string)($_GET['f'] ?? '');

if ($token === '' || strlen($token) !== 32) {
  http_response_code(400);
  echo 'bad token';
  exit;
}

// Basic filename sanitation
$file = basename($file);
if ($file === '' || strlen($file) > 255) {
  http_response_code(400);
  echo 'bad file';
  exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT node_id, orig_name, stored_name, mime, size_bytes FROM node_attachments WHERE token=? AND stored_name=? LIMIT 1');
$st->execute([$token, $file]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  echo 'not found';
  exit;
}

$nodeId = (int)($row['node_id'] ?? 0);
$path = '';

// Try project-local artifacts first
try {
  $slug = project_slug_for_node($pdo, $nodeId);
  if (is_string($slug) && $slug !== '') {
    $p1 = '/var/www/t/' . $slug . '/shared/artifacts/att/' . $token . '/' . $file;
    if (is_file($p1)) $path = $p1;
  }
} catch (Throwable $e) {
  // ignore
}

// Fallback to legacy central storage
if ($path === '') {
  $p2 = '/var/www/coosdash/shared/att/' . $token . '/' . $file;
  if (is_file($p2)) $path = $p2;
}

if ($path === '') {
  http_response_code(404);
  echo 'missing file';
  exit;
}

$orig = (string)($row['orig_name'] ?? $file);
$mime = (string)($row['mime'] ?? 'application/octet-stream');

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"','', $orig) . '"');
header('Content-Length: ' . (string)filesize($path));

readfile($path);

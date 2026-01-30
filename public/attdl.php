<?php
// Tokenized attachment download handler.
// Serves files from /var/www/coosdash/shared/att/<token>/<stored_name>
// while forcing download (prevents accidental execution of .php etc.).

require_once __DIR__ . '/functions.inc.php';
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
$st = $pdo->prepare('SELECT orig_name, stored_name, mime, size_bytes FROM node_attachments WHERE token=? AND stored_name=? LIMIT 1');
$st->execute([$token, $file]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  echo 'not found';
  exit;
}

$path = '/var/www/coosdash/shared/att/' . $token . '/' . $file;
if (!is_file($path)) {
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

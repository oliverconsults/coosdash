<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Only allow Oliver/James
$u = strtolower((string)($_SESSION['username'] ?? ''));
if (!in_array($u, ['oliver','james'], true)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$ok = true;
if (function_exists('opcache_reset')) {
  $ok = @opcache_reset();
}
clearstatcache(true);

header('Content-Type: text/plain; charset=utf-8');
echo $ok ? "OK opcache_reset\n" : "ERR opcache_reset\n";

<?php
// CLI wrapper for worker_api.php (no HTTP needed)
// Usage:
//   php worker_api_cli.php action=ping
//   php worker_api_cli.php action=prepend_update node_id=123 headline="..." body="..."

foreach (array_slice($argv, 1) as $arg) {
  if (!str_contains($arg, '=')) continue;
  [$k,$v] = explode('=', $arg, 2);
  $_REQUEST[$k] = $v;
}

require __DIR__ . '/../public/worker_api.php';

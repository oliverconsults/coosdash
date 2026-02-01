<?php
/**
 * COOS Worker main loop (no LLM by itself).
 *
 * Goal: replace multiple small cron entries with a single orchestrator.
 *
 * Behavior:
 * - 5 min max runtime
 * - flock lock (prevents parallel runs)
 * - respects toggle: /var/www/coosdash/shared/data/james_state.json (enabled=1)
 * - when OFF: exits quickly (no LLM, no DB work beyond file read)
 * - when ON: runs existing worker scripts sequentially as subprocesses
 *
 * Notes:
 * - We intentionally run the existing scripts via `php <script>` to avoid
 *   invasive refactors (they may call exit()).
 */

declare(strict_types=1);

set_time_limit(300);

$lockPath = '/var/www/coosdash/shared/tmp/worker_main.lock';
$logPath  = '/var/www/coosdash/shared/logs/worker_main.cron.log';
$statePath = '/var/www/coosdash/shared/data/james_state.json';

function logline(string $msg) : void {
  global $logPath;
  @mkdir(dirname($logPath), 0775, true);
  @file_put_contents($logPath, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
}

function isEnabled(string $statePath): bool {
  if (!is_file($statePath)) return false;
  $raw = @file_get_contents($statePath);
  if ($raw === false || trim($raw)==='') return false;
  $j = json_decode($raw, true);
  if (!is_array($j)) return false;
  return !empty($j['enabled']);
}

function runScript(string $path, array $env = []): int {
  $cmd = '/usr/bin/php ' . escapeshellarg($path);

  // Build env prefix (KEY=VALUE ... cmd)
  $prefix = '';
  foreach ($env as $k => $v) {
    if (!preg_match('/^[A-Z0-9_]+$/', (string)$k)) continue;
    $prefix .= $k . '=' . escapeshellarg((string)$v) . ' ';
  }

  $full = $prefix . $cmd . ' 2>&1';
  $out = [];
  $code = 0;
  exec($full, $out, $code);

  $tail = '';
  if (!empty($out)) {
    $tailLines = array_slice($out, -8);
    $tail = ' | ' . str_replace(["\n","\r"], ' ', implode(' / ', $tailLines));
  }
  logline("run " . basename($path) . " exit={$code}" . $tail);

  return $code;
}

@mkdir(dirname($lockPath), 0775, true);
$lock = @fopen($lockPath, 'c');
if (!$lock) {
  logline('ERROR cannot open lock: ' . $lockPath);
  exit(2);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
  logline('SKIP already running (lock busy)');
  exit(0);
}

$enabled = isEnabled($statePath);
if (!$enabled) {
  logline('OFF (james_state disabled)');
  exit(0);
}

$base = '/home/deploy/projects/coos/scripts';

// 1) Produce queue (no LLM)
runScript($base . '/worker_queue_produce.php');

// 2) Queue consumer: intentionally NOT started from here yet.
//    (Oliver is at LLM usage limit; keeping consumer out avoids surprise LLM calls.)
//    Once ready: we can add a guard that checks for open jobs and then triggers consumer.
logline('SKIP consumer (disabled in worker_main)');

// 3) Queue maintenance
runScript($base . '/worker_queue_maintenance.php');

// 4) Worker maintenance (auto-close/unblock)
runScript($base . '/worker_maintenance.php');

// 5) Summary+Cleanup producer
runScript($base . '/worker_summary_produce.php');

logline('OK worker_main done');

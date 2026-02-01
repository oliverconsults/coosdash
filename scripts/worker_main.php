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

// Must be > agent --timeout (300) plus overhead, otherwise PHP can time out mid-run and leave jobs stuck in 'claimed'.
set_time_limit(420);

$lockPath = '/var/www/coosdash/shared/tmp/worker_main.lock';
$logPath  = '/var/www/coosdash/shared/logs/worker_main.cron.log';
$statePath = '/var/www/coosdash/shared/data/james_state.json';

$cfgPath = '/var/www/coosdash/shared/config.local.php';
$consumerLockPath = '/var/www/coosdash/shared/tmp/worker_consumer.lock';
$consumerLastRunPath = '/var/www/coosdash/shared/tmp/worker_consumer_last_run.txt';
$consumerMinIntervalSec = 0; // no throttle (Oliver requested). Locking still prevents parallel LLM runs.

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

function getDbConfig(string $cfgPath): ?array {
  if (!is_file($cfgPath)) return null;
  $cfg = require $cfgPath;
  if (!is_array($cfg) || empty($cfg['db']) || !is_array($cfg['db'])) return null;
  $db = $cfg['db'];
  foreach (['host','port','name','user','pass'] as $k) {
    if (!isset($db[$k]) || $db[$k]==='') return null;
  }
  return $db;
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

// 2) Queue consumer (LLM): claim exactly one job and process it.
// Guardrails:
// - only when enabled
// - throttled via a timestamp file
// - lock to prevent parallel consumer runs

@mkdir(dirname($consumerLockPath), 0775, true);
$cLock = @fopen($consumerLockPath, 'c');
if ($cLock && flock($cLock, LOCK_EX | LOCK_NB)) {
  $last = @file_get_contents($consumerLastRunPath);
  $lastTs = $last ? (int)trim($last) : 0;
  if ($lastTs > 0 && (time() - $lastTs) < $consumerMinIntervalSec) {
    logline('SKIP consumer (throttled)');
  } else {
    // Claim next job (DB write: claim). If none, noop.
    $claimOut = [];
    $claimCode = 0;
    $claimCmd = '/usr/bin/php ' . escapeshellarg($base . '/worker_api_cli.php') .
      ' action=job_claim_next claimed_by=' . escapeshellarg('worker_main');
    exec($claimCmd . ' 2>&1', $claimOut, $claimCode);
    $claimRaw = trim(implode("\n", $claimOut));

    $job = null;
    if ($claimCode === 0 && $claimRaw !== '') {
      $j = json_decode($claimRaw, true);
      if (is_array($j) && !empty($j['job']) && is_array($j['job'])) $job = $j['job'];
    }

    if (!$job) {
      logline('consumer: no job');
    } else {
      // Write last-run timestamp before starting (prevents rapid re-entry)
      @file_put_contents($consumerLastRunPath, (string)time());

      $jobId = (int)($job['id'] ?? 0);
      $nodeId = (int)($job['node_id'] ?? 0);
      $promptText = (string)($job['prompt_text'] ?? '');

      // Minimal, explicit prompt so the agent only does one job and then marks it done/fail.
      $defaultTpl = "# James Queue Consumer (worker_main)\n\n"
        . "You are James. Execute exactly ONE queued job that is already claimed.\n\n"
        . "Job: id={JOB_ID} node_id={NODE_ID}\n\n"
        . "Instructions (job.prompt_text):\n{JOB_PROMPT}\n\n"
        . "Rules:\n"
        . "- cooscrm DB: KEINE direkten SQL-Writes. Alle Änderungen an cooscrm ausschließlich via: php /home/deploy/projects/coos/scripts/worker_api_cli.php ...\n"
        . "- Eigene Projekt-DB (z.B. *_rv/*_test): direkte SQL-Writes sind erlaubt, wenn nötig (vorsichtig, nachvollziehbar).\n"
        . "- If you need Oliver to decide/confirm something: (1) prepend_update explaining the question + options, (2) set_status to todo_oliver, THEN (3) mark the job done.\n"
        . "  Example:\n"
        . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=prepend_update node_id={NODE_ID} headline=\"Frage an Oliver\" body=\"...\"\n"
        . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=set_status node_id={NODE_ID} worker_status=todo_oliver\n"
        . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_done job_id={JOB_ID} node_id={NODE_ID}\n"
        . "- On success: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_done job_id={JOB_ID} node_id={NODE_ID}\n"
        . "- On failure: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_fail job_id={JOB_ID} node_id={NODE_ID} reason=\"...\"\n"
        . "- Always end by calling job_done or job_fail (never exit without closing the job).\n"
        . "- Keep it concise. Prefer verification before marking done.\n";

      $tpl = prompt_get('wrapper_prompt_template', $defaultTpl);
      $msg = str_replace(
        ['{JOB_ID}','{NODE_ID}','{JOB_PROMPT}'],
        [(string)$jobId, (string)$nodeId, (string)$promptText],
        $tpl
      );

      $agentCmd = 'clawdbot agent --session-id ' . escapeshellarg('coos-worker-queue') .
        ' --message ' . escapeshellarg($msg) .
        ' --timeout 300 --thinking low';

      $agentOut = [];
      $agentCode = 0;
      exec($agentCmd . ' 2>&1', $agentOut, $agentCode);
      $tail = implode(' / ', array_slice($agentOut, -6));
      logline('consumer: agent exit=' . $agentCode . ($tail ? (' | ' . $tail) : ''));

      // Safety net: if the agent forgot to close the job, fail it so the queue doesn't stall.
      try {
        $dbEnv = getDbConfig($cfgPath);
        if ($dbEnv) {
          $dsn = 'mysql:host=' . $dbEnv['host'] . ';port=' . (int)$dbEnv['port'] . ';dbname=' . $dbEnv['name'] . ';charset=utf8mb4';
          $pdo = new PDO($dsn, $dbEnv['user'], $dbEnv['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
          $st = $pdo->prepare('SELECT status FROM worker_queue WHERE id=?');
          $st->execute([$jobId]);
          $stt = (string)($st->fetchColumn() ?: '');
          if ($stt === 'claimed' || $stt === 'open') {
            $reason = 'agent did not call job_done/job_fail';
            $failCmd = '/usr/bin/php ' . escapeshellarg($base . '/worker_api_cli.php') .
              ' action=job_fail job_id=' . escapeshellarg((string)$jobId) .
              ' node_id=' . escapeshellarg((string)$nodeId) .
              ' reason=' . escapeshellarg($reason);
            $fo = [];
            $fc = 0;
            exec($failCmd . ' 2>&1', $fo, $fc);
            logline('consumer: auto-job_fail (safety net) exit=' . $fc . ' reason=' . $reason);
          }
        }
      } catch (Throwable $e) {
        // ignore
      }
    }
  }
} else {
  logline('SKIP consumer (lock busy)');
}

// 3) Queue maintenance
runScript($base . '/worker_queue_maintenance.php');

// 4) Worker maintenance (auto-close/unblock)
runScript($base . '/worker_maintenance.php');

// 5) Summary+Cleanup producer
runScript($base . '/worker_summary_produce.php');

logline('OK worker_main done');

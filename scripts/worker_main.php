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

require_once __DIR__ . '/../public/functions_v3.inc.php';

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

      // For project setup jobs: send ONLY the stored prompt_text to the LLM (no wrapper),
      // then let worker_main apply the JSON result (create subtasks + QC subpoints) and close the job.
      $isProjectSetup = false;
      $reqId = '';
      $meta = json_decode((string)($job['selector_meta'] ?? ''), true);
      if (is_array($meta) && ($meta['type'] ?? '') === 'project_setup') {
        $isProjectSetup = true;
        $reqId = (string)($meta['req_id'] ?? '');
      }

      $msg = '';
      if ($isProjectSetup) {
        $msg = $promptText;
      } else {
        // Normal jobs: wrap prompt_text with the existing wrapper template
        $tpl = prompt_require('wrapper_prompt_template');
        $msg = str_replace(
          ['{JOB_ID}','{NODE_ID}','{JOB_PROMPT}'],
          [(string)$jobId, (string)$nodeId, (string)$promptText],
          $tpl
        );
      }

      // --- LLM prompt/response logging (effective prompt sent to agent + raw CLI output)
      $llmDir = '/var/www/coosdash/shared/llm';
      $llmLog = '/var/www/coosdash/shared/logs/worker_llm.log';
      @mkdir($llmDir, 0775, true);
      @mkdir(dirname($llmLog), 0775, true);

      $promptPath = $llmDir . '/job_' . $jobId . '_node_' . $nodeId . '_prompt.txt';
      $respPath = $llmDir . '/job_' . $jobId . '_node_' . $nodeId . '_response.txt';
      @file_put_contents($promptPath, $msg);

      $session = $isProjectSetup ? 'coos-project-setup' : 'coos-worker-queue';
      $agentCmd = 'clawdbot agent --session-id ' . escapeshellarg($session) .
        ' --message ' . escapeshellarg($msg) .
        ' --timeout 300 --thinking low';

      $agentOut = [];
      $agentCode = 0;
      exec($agentCmd . ' 2>&1', $agentOut, $agentCode);

      $respRaw = implode("\n", $agentOut);
      @file_put_contents($respPath, $respRaw);

      // If this is a project-setup job, also write to the project_setup_* files so the UI can show it.
      if ($isProjectSetup && $reqId !== '') {
        $projResp = $llmDir . '/' . $reqId . '_response.txt';
        @file_put_contents($projResp, $respRaw);
      }

      // Project setup application (server-side): parse JSON and create subtasks under the project.
      if ($isProjectSetup) {
        $jsonText = trim($respRaw);
        if (preg_match('/\{.*\}/s', $respRaw, $m)) {
          $jsonText = $m[0];
        }
        $spec = json_decode($jsonText, true);

        $fail = function(string $reason) use ($base,$jobId,$nodeId,$tsLine,$tsHuman) {
          @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$nodeId}  [auto] {$tsHuman} Project-Setup fail: {$reason}\n", FILE_APPEND);
          $cmd = '/usr/bin/php ' . escapeshellarg($base . '/worker_api_cli.php') .
            ' action=job_fail job_id=' . escapeshellarg((string)$jobId) .
            ' node_id=' . escapeshellarg((string)$nodeId) .
            ' reason=' . escapeshellarg($reason) .
            ' session_id=' . escapeshellarg('coos-project-setup');
          $o=[]; $c=0; exec($cmd . ' 2>&1', $o, $c);
        };

        if (!is_array($spec)) {
          $fail('invalid json');
        } else {
          $children = is_array($spec['children'] ?? null) ? $spec['children'] : [];
          $qualityTitle = is_string($spec['quality_title'] ?? null) ? trim((string)$spec['quality_title']) : 'Qualitätskontrolle';
          $qc = is_array($spec['quality_children'] ?? null) ? $spec['quality_children'] : [];

          // normalize children (4-6, last=qualityTitle)
          $norm = [];
          foreach ($children as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t === '') continue;
            if (mb_strlen($t) > 40) $t = mb_substr($t, 0, 40);
            $norm[] = $t;
          }
          // dedup
          $seen=[]; $tmp=[];
          foreach ($norm as $t) {
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;
            $seen[$k]=true;
            $tmp[]=$t;
          }
          $norm = array_values(array_filter($tmp, fn($t)=>mb_strtolower($t)!==mb_strtolower($qualityTitle)));
          if (count($norm) > 5) $norm = array_slice($norm, 0, 5);
          while (count($norm) < 4) $norm[] = 'Implementierung (MVP)';
          $norm[] = $qualityTitle;

          // create children via API (chunks of 6)
          $add = function(int $parent, array $titles) use ($base,$jobId): array {
            $titles = array_values(array_filter($titles, fn($t)=>is_string($t) && trim($t) !== ''));
            if (!$titles) return [];
            $payload = implode("\n", $titles);
            $cmd = '/usr/bin/php ' . escapeshellarg($base . '/worker_api_cli.php') .
              ' action=add_children node_id=' . escapeshellarg((string)$parent) .
              ' titles=' . escapeshellarg($payload);
            $o=[]; $c=0; exec($cmd . ' 2>&1', $o, $c);
            $raw = trim(implode("\n", $o));
            $j = json_decode($raw, true);
            return (is_array($j) && !empty($j['new_ids']) && is_array($j['new_ids'])) ? $j['new_ids'] : (is_array($j['data']['new_ids'] ?? null) ? $j['data']['new_ids'] : []);
          };

          // Ensure we don't duplicate if children already exist
          try {
            $dbEnv = getDbConfig($cfgPath);
            if ($dbEnv) {
              $dsn = 'mysql:host=' . $dbEnv['host'] . ';port=' . (int)$dbEnv['port'] . ';dbname=' . $dbEnv['name'] . ';charset=utf8mb4';
              $pdo2 = new PDO($dsn, $dbEnv['user'], $dbEnv['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
              $st = $pdo2->prepare('SELECT COUNT(*) FROM nodes WHERE parent_id=?');
              $st->execute([$nodeId]);
              $hasKids = (int)$st->fetchColumn();
              if ($hasKids === 0) {
                $newIds = $add($nodeId, $norm);
                // find quality node id by title
                $qualityId = 0;
                $st = $pdo2->prepare('SELECT id FROM nodes WHERE parent_id=? AND title=? ORDER BY id DESC LIMIT 1');
                $st->execute([$nodeId, $qualityTitle]);
                $qualityId = (int)($st->fetchColumn() ?: 0);

                // quality children: 5-10, chunked
                $qnorm=[];
                foreach ($qc as $t) {
                  if (!is_string($t)) continue;
                  $t=trim($t);
                  if ($t==='') continue;
                  if (mb_strlen($t)>40) $t=mb_substr($t,0,40);
                  $qnorm[]=$t;
                }
                // dedup + clamp
                $seen=[]; $tmp=[];
                foreach ($qnorm as $t){$k=mb_strtolower($t); if(isset($seen[$k])) continue; $seen[$k]=true; $tmp[]=$t;}
                $qnorm=$tmp;
                if (count($qnorm)>10) $qnorm=array_slice($qnorm,0,10);
                while (count($qnorm)<5) $qnorm[]='Smoke-Test (kritische Pfade)';

                if ($qualityId>0) {
                  for ($i=0; $i<count($qnorm); $i+=6) {
                    $add($qualityId, array_slice($qnorm, $i, 6));
                  }
                }

                @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$nodeId}  [auto] {$tsHuman} Project-Setup applied (children=" . count($norm) . ", qc=" . count($qnorm) . ")\n", FILE_APPEND);
              }
            }
          } catch (Throwable $e) {
            // ignore
          }

          // close job
          $doneCmd = '/usr/bin/php ' . escapeshellarg($base . '/worker_api_cli.php') .
            ' action=job_done job_id=' . escapeshellarg((string)$jobId) .
            ' node_id=' . escapeshellarg((string)$nodeId) .
            ' session_id=' . escapeshellarg('coos-project-setup');
          $o=[]; $c=0; exec($doneCmd . ' 2>&1', $o, $c);
        }
      }

      $tail = implode(' / ', array_slice($agentOut, -6));
      logline('consumer: agent exit=' . $agentCode . ($tail ? (' | ' . $tail) : ''));

      // Human-readable line in worker logs (with clickable links via llm_file.php)
      $tsLine = date('Y-m-d H:i:s');
      $pBase = basename($promptPath);
      $rBase = basename($respPath);
      $pUrl = '/llm_file.php?f=' . rawurlencode($pBase);
      $rUrl = '/llm_file.php?f=' . rawurlencode($rBase);
      @file_put_contents($llmLog, $tsLine . "  job_id={$jobId} node_id={$nodeId} exit={$agentCode} prompt={$pUrl} response={$rUrl}\n", FILE_APPEND);
      @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$nodeId}  [llm] job_id={$jobId} exit={$agentCode} (details: worker_llm.log)\n", FILE_APPEND);

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

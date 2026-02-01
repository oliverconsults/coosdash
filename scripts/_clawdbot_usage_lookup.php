<?php
// Helper: find token usage for a given job_done/job_fail call by scanning Clawdbot session jsonl logs.
// This is a best-effort heuristic to avoid bloating the worker payload.

function clawdbot_find_usage_for_job(int $jobId, string $action = 'job_done', string $sessionId='coos-worker-queue'): ?array {
  if ($jobId <= 0) return null;

  $needleEnd = "php /home/deploy/projects/coos/scripts/worker_api_cli.php action={$action} job_id={$jobId}";
  $needleStartA = "JOB_ID={$jobId}";
  $needleStartB = "Job: id={$jobId}";

  // Prefer explicit session file (this is where `clawdbot agent --session-id coos-worker-queue` writes).
  $sessionFiles = [];
  foreach (['/home/deploy/.clawdbot/agents/main/sessions', '/home/deploy/.clawdbot/agents/api/sessions'] as $dir) {
    if (!is_dir($dir)) continue;
    $p = $dir . '/' . basename($sessionId) . '.jsonl';
    if (is_file($p)) $sessionFiles[] = $p;
  }

  // Fallback: scan recent jsonl files in both dirs.
  if (!$sessionFiles) {
    foreach (['/home/deploy/.clawdbot/agents/main/sessions', '/home/deploy/.clawdbot/agents/api/sessions'] as $dir) {
      if (!is_dir($dir)) continue;
      foreach (glob($dir . '/*.jsonl') ?: [] as $f) $sessionFiles[] = $f;
    }
    if (!$sessionFiles) return null;
    usort($sessionFiles, fn($a,$b) => filemtime($b) <=> filemtime($a));
    $sessionFiles = array_slice($sessionFiles, 0, 80);
  }

  foreach ($sessionFiles as $path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) continue;

    $inJob = false;
    $calls = 0;
    $tokIn = 0;
    $tokOut = 0;

    while (($line = fgets($fh)) !== false) {
      // start marker
      if (!$inJob && (strpos($line, $needleStartA) !== false || strpos($line, $needleStartB) !== false)) {
        $inJob = true;
      }

      if ($inJob) {
        $obj = json_decode($line, true);
        if (is_array($obj)) {
          $usage = $obj['usage'] ?? ($obj['message']['usage'] ?? null);
          if (is_array($usage)) {
            $calls += 1;
            $tokIn += (int)($usage['input'] ?? 0);
            $tokOut += (int)($usage['output'] ?? 0);
          }
        }

        // end marker: the *executed* CLI call that marks the job done/failed.
        // The prompt itself contains the command string, so we only stop when we see it
        // inside an assistant toolCall.
        if (
          strpos($line, $needleEnd) !== false &&
          strpos($line, '"role":"assistant"') !== false &&
          strpos($line, '"toolCall"') !== false
        ) {
          if ($calls > 0 || $tokIn > 0 || $tokOut > 0) {
            fclose($fh);
            return ['input'=>$tokIn, 'output'=>$tokOut, 'calls'=>$calls, 'session_file'=>$path];
          }
          break;
        }
      }
    }

    fclose($fh);
  }

  return null;
}

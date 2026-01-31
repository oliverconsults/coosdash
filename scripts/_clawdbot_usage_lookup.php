<?php
// Helper: find token usage for a given job_done/job_fail call by scanning Clawdbot session jsonl logs.
// This is a best-effort heuristic to avoid bloating the worker payload.

function clawdbot_find_usage_for_job(int $jobId, string $action = 'job_done'): ?array {
  if ($jobId <= 0) return null;
  $needle = "php /home/deploy/projects/coos/scripts/worker_api_cli.php action={$action} job_id={$jobId}";

  $dir = '/home/deploy/.clawdbot/agents/api/sessions';
  if (!is_dir($dir)) return null;

  $files = glob($dir . '/*.jsonl');
  if (!$files) return null;

  // Newest first
  usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));

  // Limit scan for perf
  $files = array_slice($files, 0, 60);

  foreach ($files as $path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) continue;

    $foundUsage = null;

    while (($line = fgets($fh)) !== false) {
      if (strpos($line, $needle) === false) continue;

      $obj = json_decode($line, true);
      if (!is_array($obj)) continue;

      // usage may be at top-level or inside message
      $usage = $obj['usage'] ?? ($obj['message']['usage'] ?? null);
      if (is_array($usage)) {
        $in = (int)($usage['input'] ?? 0);
        $out = (int)($usage['output'] ?? 0);
        if ($in > 0 || $out > 0) {
          $foundUsage = ['input' => $in, 'output' => $out];
        }
      }
    }

    fclose($fh);

    if ($foundUsage) return $foundUsage;
  }

  return null;
}

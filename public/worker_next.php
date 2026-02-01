<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$pdo = db();

$job = null;
try {
  $st = $pdo->query("SELECT id,node_id,status,prompt_text,created_at,updated_at FROM worker_queue WHERE status IN ('open','claimed') ORDER BY id ASC LIMIT 1");
  $job = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
} catch (Throwable $e) {
  $job = null;
}

$effective = '';
if ($job && !empty($job['prompt_text'])) {
  $tpl = '';
  try {
    $tpl = prompt_require('wrapper_prompt_template');
  } catch (Throwable $e) {
    $tpl = '';
  }

  if ($tpl !== '') {
    $effective = str_replace(
      ['{JOB_ID}','{NODE_ID}','{JOB_PROMPT}'],
      [(string)(int)$job['id'], (string)(int)$job['node_id'], (string)$job['prompt_text']],
      $tpl
    );
  }
}

// Last delivered prompt (from /shared/llm job_*_prompt.txt)
$lastDelivered = null;
try {
  $llmDir = '/var/www/coosdash/shared/llm';
  $files = glob($llmDir . '/job_*_node_*_prompt.txt');
  if (is_array($files) && $files) {
    usort($files, fn($a,$b) => @filemtime($b) <=> @filemtime($a));
    $f = $files[0];
    $base = basename($f);
    $raw = @file_get_contents($f);
    if (!is_string($raw)) $raw = '';

    $jobId = 0; $nodeId = 0;
    if (preg_match('/^job_(\d+)_node_(\d+)_prompt\.txt$/', $base, $m)) {
      $jobId = (int)$m[1];
      $nodeId = (int)$m[2];
    }
    $lastDelivered = [
      'file' => $base,
      'job_id' => $jobId,
      'node_id' => $nodeId,
      'ts' => @date('Y-m-d H:i:s', (int)@filemtime($f)),
      'text' => $raw,
    ];
  }
} catch (Throwable $e) {
  $lastDelivered = null;
}

renderHeader('Check next effective Prompt');
?>

<div class="card">
  <h2>Next effective LLM Prompt</h2>
  <div class="meta">Zeigt den nächsten Job aus <code>worker_queue</code> (status=open/claimed) und den exakt daraus gerenderten LLM-Prompt (Wrapper + Job Prompt).</div>

  <?php if (!$job): ?>
    <div class="meta">Kein Job in worker_queue mit status=open/claimed gefunden.</div>
  <?php else: ?>
    <div class="meta">
      job_id=<?php echo (int)$job['id']; ?> · node_id=<a href="/?id=<?php echo (int)$job['node_id']; ?>">#<?php echo (int)$job['node_id']; ?></a>
      · status=<?php echo h((string)$job['status']); ?>
      · created_at=<?php echo h((string)$job['created_at']); ?>
    </div>

    <div style="height:10px"></div>

    <label>Effective Prompt (Wrapper + Job Prompt)</label>
    <?php if ($effective !== ''): ?>
      <textarea readonly style="min-height:360px; opacity:0.95; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px;"><?php echo h($effective); ?></textarea>
    <?php else: ?>
      <div class="meta">Kann nicht gerendert werden (Wrapper oder job.prompt_text fehlt).</div>
    <?php endif; ?>

    <div style="height:14px"></div>
    <label>Raw job.prompt_text (as stored)</label>
    <textarea readonly style="min-height:260px; opacity:0.9; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px;"><?php echo h((string)$job['prompt_text']); ?></textarea>

  <?php endif; ?>

  <div style="height:18px"></div>

  <h3>Last delivered prompt (aus LLM-Log)</h3>
  <div class="meta">Zeigt den zuletzt an die Agent-Session gesendeten Effective Prompt (auch wenn die Queue schon wieder leer ist).</div>

  <?php if (!$lastDelivered): ?>
    <div class="meta">Kein job_*_prompt.txt gefunden.</div>
  <?php else: ?>
    <div class="meta">
      <?php echo h((string)($lastDelivered['ts'] ?? '')); ?>
      · job_id=<?php echo (int)($lastDelivered['job_id'] ?? 0); ?>
      · node_id=<a href="/?id=<?php echo (int)($lastDelivered['node_id'] ?? 0); ?>">#<?php echo (int)($lastDelivered['node_id'] ?? 0); ?></a>
      · file=<a href="/llm_file.php?f=<?php echo urlencode((string)$lastDelivered['file']); ?>"><?php echo h((string)$lastDelivered['file']); ?></a>
    </div>
    <textarea readonly style="min-height:360px; opacity:0.95; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px;"><?php echo h((string)$lastDelivered['text']); ?></textarea>
  <?php endif; ?>

  <div class="row" style="margin-top:12px;">
    <a class="btn" href="/worker_next.php">Reload</a>
    <a class="btn" href="/workerlog.php">Worker Log</a>
    <a class="btn" href="/setup.php">Setup</a>
    <a class="btn" href="/">Dashboard</a>
  </div>
</div>

<?php renderFooter();

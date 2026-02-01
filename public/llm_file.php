<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$baseDir = '/var/www/coosdash/shared/llm';
$f = (string)($_GET['f'] ?? '');
$f = basename($f);

// allow only our generated files
if ($f === '' || !preg_match('/^(job_\d+_node_\d+_(prompt|response)|project_setup_\d{8}_\d{6}_[a-z0-9\-]+_(prompt|response))\.txt$/', $f)) {
  http_response_code(400);
  echo 'bad file';
  exit;
}

$path = $baseDir . '/' . $f;
if (!is_file($path)) {
  http_response_code(404);
  echo 'not found';
  exit;
}

$raw = @file_get_contents($path);
if (!is_string($raw)) $raw = '';

renderHeader('LLM file');
?>

<div class="card">
  <h2><?php echo h($f); ?></h2>
  <div class="meta">Quelle: <?php echo h($path); ?></div>
  <div style="height:10px"></div>
  <textarea readonly style="min-height:520px; width:100%; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; opacity:0.95;"><?php echo h($raw); ?></textarea>
  <div class="row" style="margin-top:12px;">
    <a class="btn" href="/workerlog.php?f=worker_llm.log">Zurück zum worker_llm.log</a>
    <a class="btn" href="/">Dashboard</a>
  </div>
</div>

<?php renderFooter();

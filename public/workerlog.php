<?php
require_once __DIR__ . '/functions_v2.inc.php';
requireLogin();

renderHeader('Worker Log');

$logPath = '/var/www/coosdash/shared/logs/worker.log';
$maxLines = 400;

$lines = [];
if (is_file($logPath)) {
  $raw = @file($logPath, FILE_IGNORE_NEW_LINES);
  if (is_array($raw)) {
    $lines = array_slice($raw, -$maxLines);
  }
}

// Make "#123" clickable
$renderLine = function(string $line): string {
  $safe = h($line);
  $safe = preg_replace('/#(\d+)/', '<a href="/?id=$1">#$1</a>', $safe);
  return $safe;
};
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Worker Log</h2>
    <div class="row">
      <a class="btn btn-md" href="/workerlog.php">Reload</a>
      <a class="btn btn-md" href="/">Dashboard</a>
    </div>
  </div>

  <div class="meta" style="margin-top:6px;">Quelle: <?php echo h($logPath); ?> (letzte <?php echo (int)$maxLines; ?> Zeilen)</div>
  <div style="height:10px"></div>

  <div class="note" style="white-space:pre-wrap; font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; line-height:1.4;">
<?php if (empty($lines)): ?>
—
<?php else: ?>
<?php foreach (array_reverse($lines) as $ln): ?>
<?php echo $renderLine($ln) . "\n"; ?>
<?php endforeach; ?>
<?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>

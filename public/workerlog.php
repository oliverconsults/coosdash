<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

renderHeader('Worker Log');

$logsDir = '/var/www/coosdash/shared/logs';
$maxLines = 400;

// Build list of viewable logs (safe allowlist: files in logsDir ending with .log)
$available = [];
if (is_dir($logsDir)) {
  foreach (@scandir($logsDir) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    if (str_ends_with($f, '.lock')) continue;
    if (!str_ends_with($f, '.log')) continue;
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $f)) continue;
    $p = $logsDir . '/' . $f;
    if (!is_file($p)) continue;
    $available[$f] = $p;
  }
}

// Default
$selected = (string)($_GET['f'] ?? 'worker.log');
if (!isset($available[$selected])) {
  $selected = isset($available['worker.log']) ? 'worker.log' : (array_key_first($available) ?: '');
}
$logPath = $selected !== '' ? $available[$selected] : '';

$lines = [];
if ($logPath !== '' && is_file($logPath)) {
  $raw = @file($logPath, FILE_IGNORE_NEW_LINES);
  if (is_array($raw)) {
    $lines = array_slice($raw, -$maxLines);
  }
}

// Make "#123" clickable + link llm_file.php URLs in logs
$renderLine = function(string $line): string {
  $safe = h($line);

  // Node links
  // Important: avoid linking queue job references like "job #123" to a node.
  // Always deep-link into kanban view=work so details are immediately visible.
  $safe = preg_replace('/(?<!job )#(\d+)/', '<a href="/kanban.php?id=$1&view=work">#$1</a>', $safe);

  // Link local llm file viewer URLs
  $safe = preg_replace(
    '#(/llm_file\.php\?f=[A-Za-z0-9._%\-]+)#',
    '<a href="$1" target="_blank" rel="noopener">$1</a>',
    $safe
  );

  // Link http(s) URLs (fallback)
  $safe = preg_replace(
    '#(https?://[^\s<]+)#',
    '<a href="$1" target="_blank" rel="noopener">$1</a>',
    $safe
  );

  return $safe;
};
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Worker Log</h2>
    <div class="row">
      <form method="get" action="/workerlog.php" style="margin:0;">
        <select name="f" onchange="this.form.submit()" style="width:auto; min-width:260px;">
          <?php foreach ($available as $fname => $path): ?>
            <option value="<?php echo h($fname); ?>" <?php echo $fname===$selected?'selected':''; ?>><?php echo h($fname); ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a class="btn btn-md" href="/workerlog.php?f=<?php echo urlencode($selected); ?>">Reload</a>
      <a class="btn btn-md" href="/">Dashboard</a>
    </div>
  </div>

  <div class="meta" style="margin-top:6px;">Quelle: <?php echo h($logPath !== '' ? $logPath : '(none)'); ?> (letzte <?php echo (int)$maxLines; ?> Zeilen)</div>
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

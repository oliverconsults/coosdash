<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Only allow Oliver/James to view
$u = strtolower((string)($_SESSION['username'] ?? ''));
if (!in_array($u, ['oliver','james'], true)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$path = '/var/www/coosdash/shared/logs/login.log';
$lines = [];
$max = 400;
if (is_file($path)) {
  // read last N lines efficiently-ish
  $fp = fopen($path, 'rb');
  if ($fp) {
    $pos = -1;
    $buf = '';
    $lineCount = 0;
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    while ($size + $pos >= 0 && $lineCount < $max) {
      fseek($fp, $pos, SEEK_END);
      $ch = fgetc($fp);
      if ($ch === "\n") {
        $lineCount++;
        if ($buf !== '') {
          $lines[] = strrev($buf);
          $buf = '';
        }
      } else {
        $buf .= $ch;
      }
      $pos--;
    }
    if ($buf !== '') $lines[] = strrev($buf);
    fclose($fp);
    $lines = array_reverse($lines);
  }
}

renderHeader('Login Log');
?>

<div class="card">
  <h2 style="margin:0 0 10px 0;">Login Log</h2>
  <div class="meta" style="margin-bottom:10px;">Quelle: <?php echo h($path); ?> (letzte <?php echo (int)$max; ?> Zeilen)</div>

  <?php if (!$lines): ?>
    <div class="note"><div class="head">Info</div>Keine Einträge gefunden.</div>
  <?php else: ?>
    <pre style="white-space:pre-wrap; word-break:break-word; margin:0; padding:12px; border:1px solid rgba(212,175,55,.14); border-radius:12px; background:rgba(5,7,11,.35); font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; line-height:1.45;"><?php echo h(implode("\n", array_map('trim', $lines))); ?></pre>
  <?php endif; ?>
</div>

<?php renderFooter(); ?>

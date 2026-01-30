<?php
// Append one James worker status line to the shared worker log.
// Usage: php workerlog_append.php <node_id> "[james] dd.mm.yyyy HH:MM Update: ..."

$nodeId = (int)($argv[1] ?? 0);
$statusLine = (string)($argv[2] ?? '');

if ($nodeId <= 0 || trim($statusLine) === '') {
  fwrite(STDERR, "Usage: php workerlog_append.php <node_id> \"[james] ...\"\n");
  exit(1);
}

$logPath = '/var/www/coosdash/shared/logs/worker.log';
$ts = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
$line = $ts . '  #' . $nodeId . '  ' . trim($statusLine) . "\n";

$dir = dirname($logPath);
if (!is_dir($dir)) @mkdir($dir, 0775, true);

file_put_contents($logPath, $line, FILE_APPEND);

echo "OK\n";

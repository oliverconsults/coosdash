<?php
// Project provisioner (runs as deploy via cron)
// Reads setup queue and provisions /var/www/t/<slug> + DB + nginx route + env.md.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$queuePath = '/var/www/coosdash/shared/data/project_setup_queue.jsonl';
$statePath = '/var/www/coosdash/shared/data/project_setup_queue_state.json';
$logPath   = '/var/www/coosdash/shared/logs/project_setup_provisioner.log';

function logline(string $msg): void {
  global $logPath;
  @mkdir(dirname($logPath), 0775, true);
  @file_put_contents($logPath, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
}

function loadState(string $path): array {
  if (!is_file($path)) return ['done'=>[]];
  $raw = @file_get_contents($path);
  $j = $raw ? json_decode($raw, true) : null;
  if (!is_array($j)) return ['done'=>[]];
  if (!isset($j['done']) || !is_array($j['done'])) $j['done'] = [];
  return $j;
}

function saveState(string $path, array $state): void {
  @mkdir(dirname($path), 0775, true);
  @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function randPass(int $len=24): string {
  $bytes = random_bytes($len);
  return rtrim(strtr(base64_encode($bytes), '+/', 'Aa'), '=');
}

$state = loadState($statePath);
$done = array_flip(array_map('strval', $state['done']));

if (!is_file($queuePath)) {
  logline('OK no queue file');
  exit(0);
}

$lines = @file($queuePath, FILE_IGNORE_NEW_LINES);
if (!is_array($lines) || !$lines) {
  logline('OK empty queue');
  exit(0);
}

$maxPerRun = 3;
$processed = 0;

foreach ($lines as $line) {
  if ($processed >= $maxPerRun) break;
  $j = json_decode($line, true);
  if (!is_array($j)) continue;

  $slug = (string)($j['slug'] ?? '');
  $title = (string)($j['title'] ?? '');
  $nodeId = (int)($j['node_id'] ?? 0);
  $ts = (string)($j['ts'] ?? '');
  $id = sha1($ts . '|' . $slug . '|' . $nodeId);

  if (isset($done[$id])) continue;
  if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{1,40}$/', $slug)) {
    logline('SKIP invalid slug');
    $state['done'][] = $id;
    $done[$id] = true;
    continue;
  }

  logline("start slug={$slug} node_id={$nodeId}");

  $root = '/var/www/t/' . $slug;
  $docroot = $root . '/current/public';
  $shared = $root . '/shared';
  $sharedLogs = $shared . '/logs';
  $sharedArtifacts = $shared . '/artifacts';
  $sharedArtifactsAtt = $sharedArtifacts . '/att';

  @mkdir($docroot, 0775, true);
  @mkdir($sharedLogs, 0775, true);
  @mkdir($sharedArtifactsAtt, 0775, true);

  // DB + user
  $dbName = 'coos_' . str_replace('-', '_', $slug);
  $dbUser = 'coos_' . str_replace('-', '_', $slug) . '_app';
  $dbPass = randPass(18);

  // Create DB + user via mysql client (uses deploy ~/.my.cnf)
  $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
  $sql .= "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}';\n";
  $sql .= "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost';\n";
  $sql .= "FLUSH PRIVILEGES;\n";

  $tmpSql = $shared . '/provision.sql';
  @file_put_contents($tmpSql, $sql);
  $out = [];
  $rc = 0;
  exec('mysql < ' . escapeshellarg($tmpSql) . ' 2>&1', $out, $rc);
  if ($rc !== 0) {
    logline('ERROR mysql provision failed: ' . implode(' / ', array_slice($out, -6)));
    continue; // keep unprocessed, will retry
  }

  // Write project config.local.php
  $cfgPath = $shared . '/config.local.php';
  $cfg = "<?php\nreturn [\n  'db' => [\n    'host' => '127.0.0.1',\n    'port' => 3306,\n    'name' => '" . addslashes($dbName) . "',\n    'user' => '" . addslashes($dbUser) . "',\n    'pass' => '" . addslashes($dbPass) . "',\n    'charset' => 'utf8mb4',\n  ],\n];\n";
  @file_put_contents($cfgPath, $cfg);

  // Write env.md (no description)
  $envPath = $shared . '/env.md';
  $env = "# COOS Project Env (v1)\n\n";
  $env .= "title: {$title}\n";
  $env .= "slug: {$slug}\n\n";
  $env .= "url: https://t.coos.eu/{$slug}/\n";
  $env .= "docroot: {$docroot}\n";
  $env .= "repo: /home/deploy/projects/t/{$slug}\n\n";
  $env .= "php_fpm_sock: /run/php/php8.3-fpm.sock\n\n";
  $env .= "db_host: 127.0.0.1\n";
  $env .= "db_port: 3306\n";
  $env .= "db_name: {$dbName}\n";
  $env .= "db_user: {$dbUser}\n";
  $env .= "db_pass: (stored in {$cfgPath})\n\n";
  $env .= "paths:\n";
  $env .= "- shared: {$shared}\n";
  $env .= "- logs: {$sharedLogs}\n";
  $env .= "- artifacts: {$sharedArtifacts}\n\n";
  $env .= "rules:\n";
  $env .= "- cooscrm writes: only via worker_api_cli\n";
  $env .= "- project db writes: allowed\n";
  $env .= "- outputs: attach files, keep text short\n";
  @file_put_contents($envPath, $env);

  // Ensure a placeholder index.html so nginx doesn't 403
  $idx = $docroot . '/index.html';
  if (!is_file($idx)) {
    @file_put_contents($idx, "<!doctype html><meta charset=\"utf-8\"><title>{$title}</title><pre>OK {$slug}</pre>");
  }

  // Note: nginx routing is currently manual in /etc/nginx/sites-available/t.coos.eu
  // We'll add automated routing in the next iteration once the location block format is finalized.

  $state['done'][] = $id;
  $done[$id] = true;
  $processed++;
  logline("OK provisioned slug={$slug} db={$dbName} user={$dbUser}");
}

saveState($statePath, $state);

echo "OK processed={$processed}\n";

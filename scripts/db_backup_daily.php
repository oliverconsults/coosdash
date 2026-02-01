<?php
/**
 * Daily DB backup for COOS (no LLM). Keeps last 14 days.
 *
 * Output: /var/www/coosdash/shared/backups/db/cooscrm_YYYY-MM-DD.sql.gz
 */

declare(strict_types=1);

// Allow overrides via environment (useful for multiple apps/DBs).
$cfgPath = getenv('CONFIG_PATH') ?: '/var/www/coosdash/shared/config.local.php';
$backupDir = getenv('BACKUP_DIR') ?: '/var/www/coosdash/shared/backups/db';
$retainDays = (int)(getenv('RETAIN_DAYS') ?: 14);
if ($retainDays <= 0) $retainDays = 14;

function fail(string $msg, int $code=1): void {
  fwrite(STDERR, '['.date('c').'] ERROR '.$msg."\n");
  exit($code);
}

if (!is_file($cfgPath)) fail("Missing config: $cfgPath");
$cfg = require $cfgPath;
if (!is_array($cfg) || empty($cfg['db']) || !is_array($cfg['db'])) fail('Invalid config.local.php');
$db = $cfg['db'];
foreach (['host','port','name','user','pass'] as $k) {
  if (!isset($db[$k]) || $db[$k]==='') fail("Missing db.$k in config.local.php");
}

@mkdir($backupDir, 0770, true);
if (!is_dir($backupDir)) fail("Cannot create backup dir: $backupDir");

// Build a temporary mysql client config to avoid password in process list.
$tmpCnf = tempnam(sys_get_temp_dir(), 'cooscrm-mysql-');
if ($tmpCnf === false) fail('tempnam failed');
$iniEsc = function(string $v): string {
  // Minimal escape for MySQL option files: wrap in single quotes.
  $v = str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
  return "'".$v."'";
};

$cnf = "[client]\n".
  "host={$db['host']}\n".
  "port={$db['port']}\n".
  "user={$db['user']}\n".
  "password=".$iniEsc((string)$db['pass'])."\n";
file_put_contents($tmpCnf, $cnf);
@chmod($tmpCnf, 0600);

$day = date('Y-m-d');
$outFile = rtrim($backupDir,'/')."/{$db['name']}_{$day}.sql.gz";
$tmpOut = $outFile.'.tmp';

$dumpCmd = [
  'mysqldump',
  "--defaults-extra-file=$tmpCnf",
  '--single-transaction',
  '--quick',
  '--routines',
  '--triggers',
  '--events',
  '--hex-blob',
  '--skip-comments',
  '--databases', $db['name'],
];

$gzipCmd = ['gzip','-c'];

$dump = proc_open($dumpCmd, [
  0 => ['pipe', 'r'],
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
], $dumpPipes);
if (!is_resource($dump)) fail('proc_open mysqldump failed');

$gz = proc_open($gzipCmd, [
  0 => $dumpPipes[1], // stdin from mysqldump stdout
  1 => ['file', $tmpOut, 'w'],
  2 => ['pipe', 'w'],
], $gzPipes);
if (!is_resource($gz)) {
  fclose($dumpPipes[1]);
  fail('proc_open gzip failed');
}

// Close unused ends
fclose($dumpPipes[0]);
fclose($dumpPipes[1]);

$dumpErr = stream_get_contents($dumpPipes[2]);
$gzErr = stream_get_contents($gzPipes[2]);

$dumpCode = proc_close($dump);
$gzCode = proc_close($gz);

@unlink($tmpCnf);

if ($dumpCode !== 0) {
  @unlink($tmpOut);
  fail('mysqldump failed: '.trim($dumpErr), 2);
}
if ($gzCode !== 0) {
  @unlink($tmpOut);
  fail('gzip failed: '.trim($gzErr), 3);
}

rename($tmpOut, $outFile);

// Update "latest" symlink
$latest = rtrim($backupDir,'/')."/{$db['name']}_latest.sql.gz";
@unlink($latest);
@symlink(basename($outFile), $latest);

// Retention cleanup
$cutoff = time() - ($retainDays * 86400);
$glob = glob(rtrim($backupDir,'/')."/{$db['name']}_*.sql.gz") ?: [];
foreach ($glob as $p) {
  if (str_ends_with($p, '_latest.sql.gz')) continue;
  $mt = @filemtime($p);
  if ($mt !== false && $mt < $cutoff) {
    @unlink($p);
  }
}

echo '['.date('c')."] OK wrote ".basename($outFile)."\n";

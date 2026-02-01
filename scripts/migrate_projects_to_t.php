<?php
// Migrate existing COOS CRM project roots (under "Projekte") to the new t.coos.eu project layout.
// - Creates /var/www/t/<slug>/{current/public,shared/logs}
// - Creates DB + user (coos_<slug>, coos_<slug>_app) + config.local.php
// - Creates shared/env.md (tech env only)
// - Inserts mapping into cooscrm.projects table
// Intended to run as Linux user: deploy
//
// Usage:
//   php migrate_projects_to_t.php [--dry-run]

require_once __DIR__ . '/../public/functions_v3.inc.php';

$dryRun = in_array('--dry-run', $argv, true);

$logPath = '/var/www/coosdash/shared/logs/migrate_projects_to_t.log';
@mkdir(dirname($logPath), 0775, true);

function logline(string $msg): void {
  global $logPath;
  @file_put_contents($logPath, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
}

function slugify(string $s): string {
  $s = trim(mb_strtolower($s));
  $map = [
    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
    'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','å'=>'a',
    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o',
    'ú'=>'u','ù'=>'u','û'=>'u',
    'ñ'=>'n','ç'=>'c',
  ];
  $s = strtr($s, $map);
  // non-alnum -> '-'
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  // keep within 40
  if (strlen($s) > 40) $s = substr($s, 0, 40);
  $s = trim($s, '-');
  return $s;
}

function randPass(int $len=18): string {
  $bytes = random_bytes($len);
  return rtrim(strtr(base64_encode($bytes), '+/', 'Aa'), '=');
}

// Ensure base directory exists (requires one-time root setup if /var/www is root-owned)
$baseDir = '/var/www/t';
if (!is_dir($baseDir)) {
  @mkdir($baseDir, 0775, true);
}
if (!is_dir($baseDir)) {
  fwrite(STDERR, "Missing base dir {$baseDir}. Create it with proper permissions (root): mkdir -p {$baseDir} && chown deploy:www-data {$baseDir} && chmod 2775 {$baseDir}\n");
  logline("ERROR missing base dir {$baseDir}");
  exit(3);
}

$pdo = db();
projects_migrate($pdo);

$projectsRootId = projects_root_id($pdo);
if ($projectsRootId <= 0) {
  fwrite(STDERR, "Missing root 'Projekte'\n");
  exit(2);
}

$st = $pdo->prepare('SELECT id,title,description FROM nodes WHERE parent_id=? ORDER BY id');
$st->execute([$projectsRootId]);
$roots = $st->fetchAll(PDO::FETCH_ASSOC);

logline('--- migrate start dryRun=' . ($dryRun ? '1' : '0') . ' roots=' . count($roots));

action:
$usedSlugs = [];
$existing = $pdo->query('SELECT slug FROM projects')->fetchAll(PDO::FETCH_COLUMN);
foreach ($existing as $s) $usedSlugs[(string)$s] = true;

$ok = 0; $skip = 0; $err = 0;

foreach ($roots as $r) {
  $nodeId = (int)$r['id'];
  $title = (string)$r['title'];
  $desc = (string)($r['description'] ?? '');

  // slug: existing marker? otherwise slugify(title)
  $slug = '';
  if ($desc !== '' && preg_match('/\bslug=([^\s\n\r]+)/', $desc, $m)) {
    $slug = trim((string)$m[1]);
  }
  if ($slug === '') $slug = slugify($title);
  if ($slug === '') $slug = 'proj-' . $nodeId;

  // ensure unique
  $base = $slug;
  $i = 2;
  while (isset($usedSlugs[$slug])) {
    $slug = substr($base, 0, 36) . '-' . $i;
    $i++;
  }

  $envPath = '/var/www/t/' . $slug . '/shared/env.md';
  $rootDir = '/var/www/t/' . $slug;
  $docroot = $rootDir . '/current/public';
  $shared = $rootDir . '/shared';
  $sharedLogs = $shared . '/logs';
  $sharedArtifacts = $shared . '/artifacts';
  $sharedArtifactsAtt = $sharedArtifacts . '/att';
  $cfgPath = $shared . '/config.local.php';

  // if already migrated (env exists + projects row exists), skip
  $stChk = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE node_id=?');
  $stChk->execute([$nodeId]);
  $hasRow = ((int)$stChk->fetchColumn() > 0);

  if ($hasRow && is_file($envPath)) {
    logline("SKIP node_id={$nodeId} already migrated slug={$slug}");
    $skip++;
    continue;
  }

  $dbSlug = str_replace('-', '_', $slug);
  $dbName = 'coos_' . $dbSlug;
  $dbUser = 'coos_' . $dbSlug . '_app';
  $dbPass = randPass(18);

  logline("MIGRATE node_id={$nodeId} title=\"{$title}\" slug={$slug} db={$dbName}");

  if (!$dryRun) {
    @mkdir($docroot, 0775, true);
    @mkdir($sharedLogs, 0775, true);
    @mkdir($sharedArtifactsAtt, 0775, true);

    // Create DB + user (idempotent)
    $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $sql .= "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}';\n";
    $sql .= "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost';\n";
    $sql .= "FLUSH PRIVILEGES;\n";

    $tmpSql = $shared . '/migrate_provision.sql';
    @file_put_contents($tmpSql, $sql);
    $out = []; $rc = 0;
    exec('mysql < ' . escapeshellarg($tmpSql) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
      logline('ERROR mysql provision failed node_id=' . $nodeId . ' out=' . implode(' / ', array_slice($out, -6)));
      $err++;
      continue;
    }

    // Write config.local.php (store pass here)
    $cfg = "<?php\nreturn [\n  'db' => [\n    'host' => '127.0.0.1',\n    'port' => 3306,\n    'name' => '" . addslashes($dbName) . "',\n    'user' => '" . addslashes($dbUser) . "',\n    'pass' => '" . addslashes($dbPass) . "',\n    'charset' => 'utf8mb4',\n  ],\n];\n";
    @file_put_contents($cfgPath, $cfg);

    // Write env.md (tech only)
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

    // Placeholder index
    $idx = $docroot . '/index.html';
    if (!is_file($idx)) {
      @file_put_contents($idx, "<!doctype html><meta charset=\"utf-8\"><title>{$title}</title><pre>OK {$slug}</pre>");
    }

    // Insert mapping
    $pdo->prepare('INSERT INTO projects (node_id, slug, env_path) VALUES (?,?,?) ON DUPLICATE KEY UPDATE slug=VALUES(slug), env_path=VALUES(env_path)')
        ->execute([$nodeId, $slug, $envPath]);

    // Move existing attachments into project artifacts (best effort)
    // Note: token dir is unique per upload, so safe to move.
    try {
      $allNodeIds = [$nodeId];
      $stack = [$nodeId];
      while ($stack) {
        $cur = array_pop($stack);
        $stKids = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
        $stKids->execute([$cur]);
        foreach ($stKids->fetchAll(PDO::FETCH_ASSOC) as $kid) {
          $kidId = (int)$kid['id'];
          if ($kidId <= 0) continue;
          $allNodeIds[] = $kidId;
          $stack[] = $kidId;
        }
      }
      $allNodeIds = array_values(array_unique($allNodeIds));
      if ($allNodeIds) {
        $in = implode(',', array_fill(0, count($allNodeIds), '?'));
        $stAtt = $pdo->prepare("SELECT DISTINCT token FROM node_attachments WHERE node_id IN ($in)");
        $stAtt->execute($allNodeIds);
        $tokens = $stAtt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tokens as $t) {
          $t = (string)$t;
          if (!preg_match('/^[a-f0-9]{32}$/', $t)) continue;
          $src = '/var/www/coosdash/shared/att/' . $t;
          $dst = $sharedArtifactsAtt . '/' . $t;
          if (is_dir($dst)) continue;
          if (is_dir($src)) {
            if (@rename($src, $dst)) {
              logline("MOVED att token={$t} -> {$dst}");
            } else {
              // fallback copy if rename fails
              @mkdir($dst, 0775, true);
              $files = @scandir($src) ?: [];
              foreach ($files as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                @copy($src . '/' . $fn, $dst . '/' . $fn);
              }
              logline("COPIED att token={$t} -> {$dst}");
            }
          }
        }
      }
    } catch (Throwable $e) {
      logline('WARN attachment move failed node_id=' . $nodeId);
    }

    // Ensure project root has setup markers (prepend, keep history)
    $ts = date('d.m.Y H:i');
    $marker = "[setup] {$ts} migrated\n";
    $marker .= "[setup] slug={$slug}\n";
    $marker .= "[setup] url=https://t.coos.eu/{$slug}/\n";
    $marker .= "[setup] env={$envPath}\n\n";
    if (strpos($desc, 'env=') === false) {
      $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
          ->execute([$marker, $nodeId]);
    }
  }

  $usedSlugs[$slug] = true;
  $ok++;
}

logline("--- migrate done ok={$ok} skip={$skip} err={$err}");
echo "OK migrate done ok={$ok} skip={$skip} err={$err} dryRun=" . ($dryRun?'1':'0') . "\n";

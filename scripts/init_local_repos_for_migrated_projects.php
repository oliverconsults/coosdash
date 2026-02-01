<?php
// Initialize local git repos for already migrated projects under /var/www/t/<slug>/...
// - creates /home/deploy/projects/t/<slug>
// - git init (if missing)
// - creates public/index.php starter (if missing)
// - switches /var/www/t/<slug>/current/public -> symlink to repo/public IF current/public is still the placeholder index.html
// Intended to run as deploy.
//
// Usage:
//   php init_local_repos_for_migrated_projects.php [--dry-run]

require_once __DIR__ . '/../public/functions_v3.inc.php';

$dryRun = in_array('--dry-run', $argv, true);

$logPath = '/var/www/coosdash/shared/logs/init_local_repos_for_migrated_projects.log';
@mkdir(dirname($logPath), 0775, true);

function logline(string $msg): void {
  global $logPath;
  @file_put_contents($logPath, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
}

$repoBase = '/home/deploy/projects/t';
if (!$dryRun) {
  @mkdir($repoBase, 0775, true);
}

$pdo = db();
projects_migrate($pdo);

$rows = $pdo->query('SELECT node_id, slug, env_path FROM projects ORDER BY node_id')->fetchAll(PDO::FETCH_ASSOC);
logline('--- start dryRun=' . ($dryRun?'1':'0') . ' rows=' . count($rows));

$ok=0; $skip=0; $err=0;

foreach ($rows as $r) {
  $slug = (string)($r['slug'] ?? '');
  $nodeId = (int)($r['node_id'] ?? 0);
  if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{1,40}$/', $slug)) {
    logline("SKIP invalid slug node_id={$nodeId}");
    $skip++; continue;
  }

  $repoPath = $repoBase . '/' . $slug;
  $repoPublic = $repoPath . '/public';

  $current = '/var/www/t/' . $slug . '/current';
  $docroot = $current . '/public';

  $title = $slug;
  try {
    $st = $pdo->prepare('SELECT title FROM nodes WHERE id=?');
    $st->execute([$nodeId]);
    $t = $st->fetchColumn();
    if (is_string($t) && $t !== '') $title = $t;
  } catch (Throwable $e) {}

  // ensure repo dir
  if (!$dryRun) {
    @mkdir($repoPath, 0775, true);
    @mkdir($repoPublic, 0775, true);
  }

  // init git if missing
  if (!is_dir($repoPath . '/.git')) {
    if ($dryRun) {
      logline("WOULD git init {$repoPath}");
    } else {
      $out=[]; $rc=0;
      exec('cd ' . escapeshellarg($repoPath) . ' && git init 2>&1', $out, $rc);
      logline("git init {$repoPath} rc={$rc}");
    }
  }

  // starter files
  if (!is_file($repoPath . '/README.md')) {
    if ($dryRun) logline("WOULD write README {$repoPath}");
    else @file_put_contents($repoPath . '/README.md', "# {$title}\n\nslug: {$slug}\n");
  }
  if (!is_file($repoPublic . '/index.php')) {
    if ($dryRun) logline("WOULD write index.php {$repoPublic}");
    else @file_put_contents($repoPublic . '/index.php', "<?php\nheader('Content-Type: text/plain; charset=utf-8');\necho 'OK {$slug}';\n");
  }

  // switch docroot to symlink if still placeholder index.html
  $canLink = false;
  if (is_link($docroot)) {
    $canLink = false;
  } elseif (is_dir($docroot)) {
    $files = array_values(array_diff(@scandir($docroot) ?: [], ['.','..']));
    if (count($files) === 1 && $files[0] === 'index.html') {
      $html = @file_get_contents($docroot . '/index.html');
      if (is_string($html) && strpos($html, "OK {$slug}") !== false) {
        $canLink = true;
      }
    } elseif (count($files) === 0) {
      $canLink = true;
    }
  }

  if ($canLink) {
    if ($dryRun) {
      logline("WOULD symlink {$docroot} -> {$repoPublic}");
    } else {
      // remove placeholder files/dir
      if (is_dir($docroot) && !is_link($docroot)) {
        $files = array_values(array_diff(@scandir($docroot) ?: [], ['.','..']));
        foreach ($files as $fn) { @unlink($docroot . '/' . $fn); }
        @rmdir($docroot);
      }
      @mkdir($current, 0775, true);
      $rc = @symlink($repoPublic, $docroot);
      logline("symlink {$docroot} -> {$repoPublic} ok=" . ($rc?'1':'0'));
    }
  } else {
    logline("SKIP link (docroot not placeholder) slug={$slug}");
    $skip++; continue;
  }

  $ok++;
}

logline("--- done ok={$ok} skip={$skip} err={$err}");
echo "OK init local repos done ok={$ok} skip={$skip} err={$err} dryRun=" . ($dryRun?'1':'0') . "\n";

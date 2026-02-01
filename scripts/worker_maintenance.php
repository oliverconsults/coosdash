<?php
// COOS CRM maintenance: auto-unblock + auto-close parents (safe)
// Intended to run via Linux cron every 30 minutes.
//
// Rules:
// - only under Root "Projekte"
// - auto-unblock: if node.blocked_by_node_id refers to a node that is done => clear blocker
// - auto-close: if a parent has children and all direct children are done => set parent to done and prepend a short summary
// - NO deletions, NO moves.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();

function rootId(PDO $pdo, string $title): int {
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title=? LIMIT 1');
  $st->execute([$title]);
  $r = $st->fetch();
  return $r ? (int)$r['id'] : 0;
}

function isUnderRoot(PDO $pdo, int $nodeId, int $rootId): bool {
  return depthUnderRoot($pdo, $nodeId, $rootId) !== null;
}

// Returns depth below root (root child = 1). null if not under root.
function depthUnderRoot(PDO $pdo, int $nodeId, int $rootId): ?int {
  $cur = $nodeId;
  for ($depth=0; $depth<80; $depth++) {
    $st = $pdo->prepare('SELECT parent_id FROM nodes WHERE id=?');
    $st->execute([$cur]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['parent_id'] === null) return null;
    $pid = (int)$row['parent_id'];
    if ($pid === $rootId) return $depth + 1;
    $cur = $pid;
  }
  return null;
}

$projectsId = rootId($pdo, 'Projekte');
if (!$projectsId) {
  fwrite(STDERR, "Missing root 'Projekte'\n");
  exit(2);
}

$tsHuman = date('d.m.Y H:i');
$tsLine = date('Y-m-d H:i:s');

$did = [
  'unblocked' => 0,
  'closed' => 0,
];

// 1) auto-unblock
$st = $pdo->query('SELECT id, blocked_by_node_id FROM nodes WHERE blocked_by_node_id IS NOT NULL');
$rows = $st->fetchAll();
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $bid = (int)$r['blocked_by_node_id'];
  if ($id <= 0 || $bid <= 0) continue;
  if (!isUnderRoot($pdo, $id, $projectsId)) continue;

  $st2 = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
  $st2->execute([$bid]);
  $b = $st2->fetch();
  if (!$b) continue;
  if ((string)$b['worker_status'] !== 'done') continue;

  $pdo->prepare('UPDATE nodes SET blocked_by_node_id=NULL WHERE id=?')->execute([$id]);
  $line = "[auto] {$tsHuman} Unblocked: blocker #{$bid} ist done\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $id]);
  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$id}  [auto] {$tsHuman} Unblocked: blocker #{$bid} ist done\n", FILE_APPEND);
  $did['unblocked']++;
}

// 2) auto-close parents (limit per run)
$MAX_CLOSE = 20;
$st = $pdo->prepare('SELECT id, title, worker_status FROM nodes WHERE worker_status <> "done"');
$st->execute();
$all = $st->fetchAll();

foreach ($all as $p) {
  if ($did['closed'] >= $MAX_CLOSE) break;

  $pid = (int)$p['id'];
  if ($pid <= 0) continue;
  if (!isUnderRoot($pdo, $pid, $projectsId)) continue;

  // has children?
  $stC = $pdo->prepare('SELECT id, title, worker_status FROM nodes WHERE parent_id=? ORDER BY id');
  $stC->execute([$pid]);
  $kids = $stC->fetchAll();
  if (!$kids) continue;

  $allDone = true;
  foreach ($kids as $k) {
    if ((string)$k['worker_status'] !== 'done') { $allDone = false; break; }
  }
  if (!$allDone) continue;

  // Determine depth below Projekte
  $depth = depthUnderRoot($pdo, $pid, $projectsId);
  if ($depth === null) continue;

  // Depth rule:
  // - depth <= 3: keep hierarchy (Projekte > A > AA > AAA) => just set done (no summary, no cleanup)
  // - depth > 3: do NOT auto-close here (summary+cleanup pipeline handles it)
  if ($depth > 3) {
    continue;
  }

  // close parent (no summary at shallow levels)
  $pdo->prepare('UPDATE nodes SET worker_status="done" WHERE id=?')->execute([$pid]);
  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$pid}  [auto] {$tsHuman} Auto-close (depth {$depth})\n", FILE_APPEND);

  $did['closed']++;
}

// log
$logDir = '/var/www/coosdash/shared/logs';
@mkdir($logDir, 0775, true);
$log = $logDir . '/worker_maintenance.log';
file_put_contents($log, $tsLine . " autoclose={$did['closed']} unblock={$did['unblocked']}\n", FILE_APPEND);

echo "OK autoclose={$did['closed']} unblock={$did['unblocked']}\n";

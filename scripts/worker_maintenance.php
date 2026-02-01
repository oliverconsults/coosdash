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
  fwrite(STDERR, date('Y-m-d H:i:s') . "  Missing root 'Projekte'\n");
  exit(2);
}

$tsHuman = date('d.m.Y H:i');
$tsLine = date('Y-m-d H:i:s');

$did = [
  'unblocked' => 0,
  'closed' => 0,
  'reactivated' => 0,
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

// 2) auto-close parents
// Disabled: we keep parents open for manual verification (second check).
// Summary+cleanup pipeline stays enabled separately.
// (If you want this back later, re-enable this block.)

// 3) Reactivate canceled queue jobs (older than 30 minutes)
// Goal: canceled jobs should not permanently block progress. After cooldown, put them back to 'open'
// so worker_main can pick them up again.
$st = $pdo->prepare(
  "SELECT id,node_id,created_at,updated_at FROM worker_queue " .
  "WHERE status='canceled' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) " .
  "ORDER BY updated_at ASC LIMIT 50"
);
$st->execute();
$canceled = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($canceled as $r) {
  $jid = (int)$r['id'];
  $nid = (int)$r['node_id'];
  if ($jid <= 0 || $nid <= 0) continue;
  // only under Projekte
  if (!isUnderRoot($pdo, $nid, $projectsId)) continue;

  // If there is already an open/claimed job for that node, keep this one canceled.
  $st2 = $pdo->prepare("SELECT COUNT(*) FROM worker_queue WHERE node_id=? AND status IN ('open','claimed')");
  $st2->execute([$nid]);
  if ((int)$st2->fetchColumn() > 0) continue;

  // Reactivate the job
  $pdo->prepare("UPDATE worker_queue SET status='open', claimed_by=NULL, claimed_at=NULL, done_at=NULL WHERE id=? AND status='canceled'")
      ->execute([$jid]);

  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$nid}  [auto] {$tsHuman} Queue reactivated: canceled job #{$jid} -> open\n", FILE_APPEND);
  $did['reactivated']++;
}

// log
$logDir = '/var/www/coosdash/shared/logs';
@mkdir($logDir, 0775, true);
$log = $logDir . '/worker_maintenance.log';
file_put_contents($log, $tsLine . " autoclose={$did['closed']} unblock={$did['unblocked']} reactivated={$did['reactivated']}\n", FILE_APPEND);

echo date('Y-m-d H:i:s') . "  OK autoclose={$did['closed']} unblock={$did['unblocked']} reactivated={$did['reactivated']}\n";

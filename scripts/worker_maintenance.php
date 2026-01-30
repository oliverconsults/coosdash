<?php
// COOS CRM maintenance: auto-unblock + auto-close parents (safe)
// Intended to run via Linux cron every 30 minutes.
//
// Rules:
// - only under Root "Projekte"
// - auto-unblock: if node.blocked_by_node_id refers to a node that is done => clear blocker
// - auto-close: if a parent has children and all direct children are done => set parent to done and prepend a short summary
// - NO deletions, NO moves.

require_once __DIR__ . '/../public/functions.inc.php';

$pdo = db();

function rootId(PDO $pdo, string $title): int {
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title=? LIMIT 1');
  $st->execute([$title]);
  $r = $st->fetch();
  return $r ? (int)$r['id'] : 0;
}

function isUnderRoot(PDO $pdo, int $nodeId, int $rootId): bool {
  $cur = $nodeId;
  for ($i=0; $i<80; $i++) {
    $st = $pdo->prepare('SELECT parent_id FROM nodes WHERE id=?');
    $st->execute([$cur]);
    $row = $st->fetch();
    if (!$row) return false;
    if ($row['parent_id'] === null) return false;
    $pid = (int)$row['parent_id'];
    if ($pid === $rootId) return true;
    $cur = $pid;
  }
  return false;
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

  // close parent + prepend short summary
  $pdo->prepare('UPDATE nodes SET worker_status="done" WHERE id=?')->execute([$pid]);

  $sum = "[auto] {$tsHuman} Summary: " . count($kids) . " Subtasks erledigt\n\n";
  foreach ($kids as $k) {
    $sum .= "- #" . (int)$k['id'] . " " . (string)$k['title'] . "\n";
  }
  $sum .= "\n";

  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
      ->execute([$sum, $pid]);

  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$pid}  [auto] {$tsHuman} Summary: " . count($kids) . " Subtasks erledigt\n", FILE_APPEND);

  $did['closed']++;
}

// log
$logDir = '/var/www/coosdash/shared/logs';
@mkdir($logDir, 0775, true);
$log = $logDir . '/worker_maintenance.log';
file_put_contents($log, $tsLine . " autoclose={$did['closed']} unblock={$did['unblocked']}\n", FILE_APPEND);

echo "OK autoclose={$did['closed']} unblock={$did['unblocked']}\n";

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
  'todo_bubbled' => 0,
  'gc_blocks' => 0,
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
// Goal: jobs canceled by the James on/off toggle should not permanently block progress.
// We ONLY reactivate jobs for nodes that are still actionable (todo_james) to avoid re-running already done tasks.
$st = $pdo->prepare(
  "SELECT id,node_id,created_at,updated_at,claimed_by,claimed_at FROM worker_queue " .
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

  // only if the node is still todo_james (otherwise we'd re-run completed work)
  $stN = $pdo->prepare('SELECT worker_status, blocked_until FROM nodes WHERE id=?');
  $stN->execute([$nid]);
  $nr = $stN->fetch(PDO::FETCH_ASSOC);
  if (!$nr) continue;
  if ((string)($nr['worker_status'] ?? '') !== 'todo_james') continue;

  // respect blocked_until
  $bu = (string)($nr['blocked_until'] ?? '');
  if ($bu !== '' && strtotime($bu) > time()) continue;

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

// 4) Bubble up TODO status: if any descendant under "Projekte" is todo, ensure ancestors are todo_james.
// This prevents "done" projects with unfinished children.
$todoIds = [];
$st = $pdo->prepare("SELECT id FROM nodes WHERE worker_status IN ('todo_james','todo_oliver')");
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $id = (int)($r['id'] ?? 0);
  if ($id > 0) $todoIds[] = $id;
}

foreach ($todoIds as $tid) {
  if (!isUnderRoot($pdo, $tid, $projectsId)) continue;

  $cur = $tid;
  for ($i=0; $i<80; $i++) {
    $stP = $pdo->prepare('SELECT id,parent_id,worker_status,title FROM nodes WHERE id=?');
    $stP->execute([$cur]);
    $row = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$row) break;
    if ($row['parent_id'] === null) break;
    $pid = (int)$row['parent_id'];
    if ($pid <= 0) break;

    // stop at Projekte root
    if ($pid === $projectsId) break;

    // only touch ancestors under Projekte
    if (!isUnderRoot($pdo, $pid, $projectsId)) break;

    $stS = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
    $stS->execute([$pid]);
    $pStatus = (string)($stS->fetchColumn() ?: '');

    if ($pStatus !== 'todo_james') {
      $pdo->prepare('UPDATE nodes SET worker_status="todo_james" WHERE id=?')->execute([$pid]);

      // Build chain from the original todo leaf up to this parent
      $chain = [];
      $x = $tid;
      for ($j=0; $j<30; $j++) {
        $stC = $pdo->prepare('SELECT id,parent_id,title FROM nodes WHERE id=?');
        $stC->execute([$x]);
        $rr = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$rr) break;
        $chain[] = '#' . (int)$rr['id'] . ' ' . (string)$rr['title'];
        if ((int)$rr['id'] === $pid) break;
        if ($rr['parent_id'] === null) break;
        $x = (int)$rr['parent_id'];
      }
      $chainTxt = $chain ? implode(' > ', $chain) : ('#' . $tid . ' > #' . $pid);

      @file_put_contents(
        '/var/www/coosdash/shared/logs/worker.log',
        $tsLine . "  #{$pid}  [auto] {$tsHuman} Todo bubbled up: {$chainTxt}\n",
        FILE_APPEND
      );
      $did['todo_bubbled']++;
    }

    $cur = $pid;
  }
}

// 5) GC: clear dangling blocked_by_node_id refs (or refs pointing into Gelöscht)
$deletedRootId = rootId($pdo, 'Gelöscht');
$st = $pdo->prepare('SELECT id, blocked_by_node_id FROM nodes WHERE blocked_by_node_id IS NOT NULL');
$st->execute();
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $id = (int)$r['id'];
  $bid = (int)$r['blocked_by_node_id'];
  if ($id <= 0 || $bid <= 0) continue;

  $clear = false;

  // clear if blocker node is missing
  $st2 = $pdo->prepare('SELECT id FROM nodes WHERE id=?');
  $st2->execute([$bid]);
  $exists = (int)($st2->fetchColumn() ?: 0);
  if ($exists <= 0) {
    $clear = true;
  }

  // clear if blocker node is under Gelöscht
  if (!$clear && $deletedRootId > 0 && isUnderRoot($pdo, $bid, $deletedRootId)) {
    $clear = true;
  }

  if ($clear) {
    $pdo->prepare('UPDATE nodes SET blocked_by_node_id=NULL WHERE id=?')->execute([$id]);
    @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$id}  [auto] {$tsHuman} Blocker GC: cleared dangling blocked_by #{$bid}\n", FILE_APPEND);
    $did['gc_blocks']++;
  }
}

// log
$logDir = '/var/www/coosdash/shared/logs';
@mkdir($logDir, 0775, true);
$log = $logDir . '/worker_maintenance.log';
file_put_contents($log, $tsLine . " autoclose={$did['closed']} unblock={$did['unblocked']} reactivated={$did['reactivated']} todo_bubbled={$did['todo_bubbled']} gc_blocks={$did['gc_blocks']}\n", FILE_APPEND);

echo date('Y-m-d H:i:s') . "  OK autoclose={$did['closed']} unblock={$did['unblocked']} reactivated={$did['reactivated']} todo_bubbled={$did['todo_bubbled']} gc_blocks={$did['gc_blocks']}\n";

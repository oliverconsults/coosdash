<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$pdo = db();

$action = $_POST['action'] ?? '';
$nodeId = (int)($_POST['node_id'] ?? 0);

if (!$nodeId) {
  flash_set('Projekt fehlt.', 'err');
  header('Location: /');
  exit;
}

// Disallow actions on top-level container roots
$st = $pdo->prepare('SELECT id, parent_id, title FROM nodes WHERE id=?');
$st->execute([$nodeId]);
$curNode = $st->fetch();
if (!$curNode) {
  flash_set('Projekt nicht gefunden.', 'err');
  header('Location: /');
  exit;
}
$isContainerRoot = ($curNode['parent_id'] === null) && in_array((string)$curNode['title'], ['Ideen','Projekte','Später','Gelöscht'], true);
if ($isContainerRoot) {
  flash_set('Für Oberprojekte gibt es keine Aktionen.', 'err');
  header('Location: /?id=' . $nodeId);
  exit;
}

// legacy: approve->todo propagation was removed (worker_status now only todo/done)

function propagateDone(PDO $pdo, int $nodeId): int {
  $count = 0;
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
  $st->execute([$nodeId]);
  $children = $st->fetchAll();
  foreach ($children as $c) {
    $cid = (int)$c['id'];
    $pdo->prepare('UPDATE nodes SET worker_status="done" WHERE id=?')->execute([$cid]);
    $count++;
    $count += propagateDone($pdo, $cid);
  }
  return $count;
}

function isUnderRoot(PDO $pdo, int $nodeId, int $rootId): bool {
  $cur = $nodeId;
  for ($i=0; $i<50; $i++) {
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

// legacy actions removed: approve_to_todo_recursive, accept (worker_status is now only todo/done)

if ($action === 'set_block') {
  // Only James manages blockers for now
  $u = strtolower((string)($_SESSION['username'] ?? ''));
  if ($u !== 'james') {
    flash_set('Blocker-Optionen werden aktuell nur von James verwaltet.', 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $blockedUntilRaw = trim((string)($_POST['blocked_until'] ?? ''));
  $blockedByRaw = (int)($_POST['blocked_by_node_id'] ?? 0);

  // datetime-local comes as YYYY-MM-DDTHH:MM
  $blockedUntil = null;
  if ($blockedUntilRaw !== '') {
    $blockedUntilRaw = str_replace('T', ' ', $blockedUntilRaw);
    $dt = DateTime::createFromFormat('Y-m-d H:i', $blockedUntilRaw);
    if ($dt) {
      $blockedUntil = $dt->format('Y-m-d H:i:00');
    }
  }

  // validate blocked_by exists (and prevent self-ref)
  $blockedBy = null;
  if ($blockedByRaw > 0 && $blockedByRaw !== $nodeId) {
    $st = $pdo->prepare('SELECT id FROM nodes WHERE id=?');
    $st->execute([$blockedByRaw]);
    if ($st->fetch()) $blockedBy = $blockedByRaw;
  }

  $pdo->prepare('UPDATE nodes SET blocked_until=?, blocked_by_node_id=? WHERE id=?')
      ->execute([$blockedUntil, $blockedBy, $nodeId]);

  $ts = date('d.m.Y H:i');
  $bits = [];
  if ($blockedUntil) $bits[] = 'bis ' . date('d.m.Y H:i', strtotime($blockedUntil));
  if ($blockedBy) $bits[] = 'wartet auf #' . $blockedBy;
  $txt = $bits ? implode(' · ', $bits) : 'gesetzt';
  $line = "[oliver] {$ts} Blocker: {$txt}\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
      ->execute([$line, $nodeId]);

  $ts = date('d.m.Y H:i');
  workerlog_append($nodeId, "[oliver] {$ts} UI: set_block");

  flash_set('Blocker gespeichert.', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

if ($action === 'clear_block') {
  // Allow Oliver to clear time-blocks quickly from UI.
  // (Used for "Blockiert bis ... [Freigeben]".)
  $u = strtolower((string)($_SESSION['username'] ?? ''));
  if (!in_array($u, ['james','oliver'], true)) {
    flash_set('Keine Berechtigung.', 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  // Only clear blocked_until (time-block) here. Keep blocked_by untouched.
  $pdo->prepare('UPDATE nodes SET blocked_until=NULL WHERE id=?')->execute([$nodeId]);

  $ts = date('d.m.Y H:i');
  $line = "[oliver] {$ts} Blocker: time-block entfernt\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
      ->execute([$line, $nodeId]);
  workerlog_append($nodeId, "[oliver] {$ts} UI: clear_blocked_until");

  flash_set('Time-Block entfernt.', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

if ($action === 'set_later') {
  // Move to "Später" root
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Später" LIMIT 1');
  $st->execute();
  $sp = $st->fetch();
  $spId = $sp ? (int)$sp['id'] : 0;

  $pdo->prepare('UPDATE nodes SET parent_id=?, worker_status="done" WHERE id=?')->execute([$spId ?: null, $nodeId]);
  $n = propagateDone($pdo, $nodeId);
  $ts = date('d.m.Y H:i');
  $line = "[oliver] {$ts} Statusänderung: done (verschoben nach Später; Descendants: {$n})\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
  $ts = date('d.m.Y H:i');
  workerlog_append($nodeId, "[oliver] {$ts} UI: set_later -> Später");

  flash_set('Auf später gesetzt (erledigt) & verschoben.', 'info');
  header('Location: /?id=' . ($spId ?: $nodeId));
  exit;
}

// set_cancel removed (main_status dropped)

if ($action === 'set_active') {
  // Reactivate: active + todo. If coming from Ideen/Später, move under Projekte.
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Projekte" LIMIT 1');
  $st->execute();
  $pr = $st->fetch();
  $projId = $pr ? (int)$pr['id'] : 0;

  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Ideen" LIMIT 1');
  $st->execute();
  $idRow = $st->fetch();
  $ideasId = $idRow ? (int)$idRow['id'] : 0;

  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Später" LIMIT 1');
  $st->execute();
  $spRow = $st->fetch();
  $laterRootId = $spRow ? (int)$spRow['id'] : 0;

  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Gelöscht" LIMIT 1');
  $st->execute();
  $delRow = $st->fetch();
  $deletedRootId = $delRow ? (int)$delRow['id'] : 0;

  $moveToProjects = false;
  if ($ideasId && isUnderRoot($pdo, $nodeId, $ideasId)) $moveToProjects = true;
  if ($laterRootId && isUnderRoot($pdo, $nodeId, $laterRootId)) $moveToProjects = true;
  if ($deletedRootId && isUnderRoot($pdo, $nodeId, $deletedRootId)) $moveToProjects = true;

  if ($moveToProjects && $projId) {
    $pdo->prepare('UPDATE nodes SET parent_id=?, worker_status="todo_james" WHERE id=?')->execute([$projId, $nodeId]);
    $ts = date('d.m.Y H:i');
    $line = "[oliver] {$ts} Statusänderung: todo_james (aktiviert; nach Projekte verschoben)\n\n";
    $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
    workerlog_append($nodeId, "[oliver] {$ts} UI: set_active -> todo_james (moved to Projekte)");
    flash_set('Aktiviert: nach Projekte verschoben (James).', 'info');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $pdo->prepare('UPDATE nodes SET worker_status="todo_james" WHERE id=?')->execute([$nodeId]);
  $ts = date('d.m.Y H:i');
  $line = "[oliver] {$ts} Statusänderung: todo_james (aktiviert)\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
  $ts = date('d.m.Y H:i');
  workerlog_append($nodeId, "[oliver] {$ts} UI: set_active -> todo_james");

  flash_set('Aktiviert (James).', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

function countSubtree(PDO $pdo, int $nodeId): int {
  $count = 1;
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
  $st->execute([$nodeId]);
  foreach ($st->fetchAll() as $k) {
    $count += countSubtree($pdo, (int)$k['id']);
  }
  return $count;
}

function moveSubtreeRoot(PDO $pdo, int $nodeId, int $newParentId): void {
  // move only the root; children stay attached
  $pdo->prepare('UPDATE nodes SET parent_id=? WHERE id=?')->execute([$newParentId, $nodeId]);
}

function deleteSubtreePermanent(PDO $pdo, int $nodeId): int {
  $count = 0;
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
  $st->execute([$nodeId]);
  foreach ($st->fetchAll() as $row) {
    $count += deleteSubtreePermanent($pdo, (int)$row['id']);
  }

  // If other nodes are blocked by this node, clear those refs (avoid dangling blockers)
  $pdo->prepare('UPDATE nodes SET blocked_by_node_id=NULL WHERE blocked_by_node_id=?')->execute([$nodeId]);

  // delete legacy notes + node itself
  $pdo->prepare('DELETE FROM node_notes WHERE node_id=?')->execute([$nodeId]);
  $pdo->prepare('DELETE FROM nodes WHERE id=?')->execute([$nodeId]);
  return $count + 1;
}

if ($action === 'remove_recursive') {
  // Soft-delete: move under root "Gelöscht" (keep notes + subtree)
  $st = $pdo->prepare('SELECT id, title FROM nodes WHERE id=?');
  $st->execute([$nodeId]);
  $n = $st->fetch();
  if (!$n) {
    flash_set('Projekt nicht gefunden.', 'err');
    header('Location: /');
    exit;
  }

  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Gelöscht" LIMIT 1');
  $st->execute();
  $del = $st->fetch();
  $deletedRootId = $del ? (int)$del['id'] : 0;
  if (!$deletedRootId) {
    flash_set('Missing root: Gelöscht', 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $pdo->beginTransaction();
  try {
    $moved = countSubtree($pdo, $nodeId);

    // Clear blockers referencing this node before moving it under Gelöscht.
    $pdo->prepare('UPDATE nodes SET blocked_by_node_id=NULL WHERE blocked_by_node_id=?')->execute([$nodeId]);

    moveSubtreeRoot($pdo, $nodeId, $deletedRootId);
    $pdo->prepare('UPDATE nodes SET worker_status="done" WHERE id=?')->execute([$nodeId]);
    $ts = date('d.m.Y H:i');
    $line = "[oliver] {$ts} Statusänderung: done (nach Gelöscht verschoben; Subtree behalten)\n\n";
    $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('Remove failed: ' . $e->getMessage(), 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  flash_set('Verschoben nach Gelöscht (' . $moved . ' Items).', 'info');
  header('Location: /?id=' . $deletedRootId);
  exit;
}

if ($action === 'delete_permanent') {
  // Hard-delete: only allowed from inside "Gelöscht" section
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Gelöscht" LIMIT 1');
  $st->execute();
  $del = $st->fetch();
  $deletedRootId = $del ? (int)$del['id'] : 0;
  if (!$deletedRootId) {
    flash_set('Missing root: Gelöscht', 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  if (!isUnderRoot($pdo, $nodeId, $deletedRootId) && (int)$curNode['parent_id'] !== $deletedRootId) {
    flash_set('Endgültig löschen ist nur in „Gelöscht“ erlaubt.', 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $pdo->beginTransaction();
  try {
    $n = deleteSubtreePermanent($pdo, $nodeId);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('Endgültig löschen fehlgeschlagen: ' . $e->getMessage(), 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  flash_set('Endgültig gelöscht (' . $n . ' Items).', 'info');
  header('Location: /?id=' . $deletedRootId);
  exit;
}

flash_set('Unbekannte Aktion.', 'err');
header('Location: /?id=' . $nodeId);
exit;

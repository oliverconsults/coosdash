<?php
require_once __DIR__ . '/functions.inc.php';
requireLogin();

$pdo = db();

$action = $_POST['action'] ?? '';
$nodeId = (int)($_POST['node_id'] ?? 0);

if (!$nodeId) {
  flash_set('Missing node.', 'err');
  header('Location: /');
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

if ($action === 'set_later') {
  // Move to "Später" root
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Später" LIMIT 1');
  $st->execute();
  $sp = $st->fetch();
  $spId = $sp ? (int)$sp['id'] : 0;

  $pdo->prepare('UPDATE nodes SET parent_id=?, worker_status="done" WHERE id=?')->execute([$spId ?: null, $nodeId]);
  $n = propagateDone($pdo, $nodeId);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Später: verschoben, Status erledigt. Descendants done: ' . $n]);
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

  $moveToProjects = false;
  if ($ideasId && isUnderRoot($pdo, $nodeId, $ideasId)) $moveToProjects = true;
  if ($laterRootId && isUnderRoot($pdo, $nodeId, $laterRootId)) $moveToProjects = true;

  if ($moveToProjects && $projId) {
    $pdo->prepare('UPDATE nodes SET parent_id=?, worker_status="todo" WHERE id=?')->execute([$projId, $nodeId]);
    $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
        ->execute([$nodeId, 'Aktiviert: nach Projekte verschoben (todo).']);
    flash_set('Aktiviert: nach Projekte verschoben (todo).', 'info');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $pdo->prepare('UPDATE nodes SET worker_status="todo" WHERE id=?')->execute([$nodeId]);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Aktiviert: worker_status=todo']);
  flash_set('Aktiviert (todo).', 'info');
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
    moveSubtreeRoot($pdo, $nodeId, $deletedRootId);
    $pdo->prepare('UPDATE nodes SET worker_status="done" WHERE id=?')->execute([$nodeId]);
    $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
        ->execute([$nodeId, 'Gelöscht: nach „Gelöscht“ verschoben (Subtree behalten).']);
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

flash_set('Unbekannte Aktion.', 'err');
header('Location: /?id=' . $nodeId);
exit;

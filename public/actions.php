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

// Recursively set worker_status=todo for active|approve descendants
function propagateTodo(PDO $pdo, int $nodeId): int {
  $count = 0;
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=? AND main_status="active" AND worker_status="approve"');
  $st->execute([$nodeId]);
  $children = $st->fetchAll();

  foreach ($children as $c) {
    $cid = (int)$c['id'];
    $pdo->prepare('UPDATE nodes SET worker_status="todo" WHERE id=?')->execute([$cid]);
    $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "james", ?)')
        ->execute([$cid, 'Auto: propagated todo from parent approval.']);
    $count++;
    $count += propagateTodo($pdo, $cid);
  }

  return $count;
}

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

if ($action === 'approve_to_todo_recursive') {
  // Only meaningful for active|approve.
  $pdo->prepare('UPDATE nodes SET worker_status="todo" WHERE id=? AND main_status="active"')->execute([$nodeId]);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Freigabe: approve→todo (rekursiv)']);

  $n = propagateTodo($pdo, $nodeId);
  flash_set('Freigegeben. Rekursiv aktiviert: ' . $n . ' Unterpunkte.', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

if ($action === 'accept') {
  // new -> active|todo
  $pdo->prepare('UPDATE nodes SET main_status="active", worker_status="todo" WHERE id=?')->execute([$nodeId]);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Akzeptiert: new→active und approve→todo']);
  flash_set('Akzeptiert.', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

if ($action === 'set_later') {
  // Move to "Später" root
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title="Später" LIMIT 1');
  $st->execute();
  $sp = $st->fetch();
  $spId = $sp ? (int)$sp['id'] : 0;

  $pdo->prepare('UPDATE nodes SET parent_id=?, main_status="later", worker_status="done" WHERE id=?')->execute([$spId ?: null, $nodeId]);
  $n = propagateDone($pdo, $nodeId);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Status: later (worker done) + moved to Später. Descendants done: ' . $n]);
  flash_set('Auf later gesetzt (done) & verschoben.', 'info');
  header('Location: /?id=' . ($spId ?: $nodeId));
  exit;
}

if ($action === 'set_cancel') {
  $pdo->prepare('UPDATE nodes SET main_status="canceled", worker_status="done" WHERE id=?')->execute([$nodeId]);
  $n = propagateDone($pdo, $nodeId);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Status: canceled (worker done). Descendants done: ' . $n]);
  flash_set('Canceled (done).', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

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
    $pdo->prepare('UPDATE nodes SET parent_id=?, main_status="active", worker_status="todo" WHERE id=?')->execute([$projId, $nodeId]);
    $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
        ->execute([$nodeId, 'Activate: moved to Projekte; main_status=active, worker_status=todo']);
    flash_set('Activate: verschoben nach Projekte (todo).', 'info');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  $pdo->prepare('UPDATE nodes SET main_status="active", worker_status="todo" WHERE id=?')->execute([$nodeId]);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Activate: main_status=active, worker_status=todo']);
  flash_set('Activate (todo).', 'info');
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
  $st = $pdo->prepare('SELECT id, main_status, title FROM nodes WHERE id=?');
  $st->execute([$nodeId]);
  $n = $st->fetch();
  if (!$n) {
    flash_set('Node not found.', 'err');
    header('Location: /');
    exit;
  }
  if (($n['main_status'] ?? '') !== 'canceled') {
    flash_set('Remove is only allowed for canceled items.', 'err');
    header('Location: /?id=' . $nodeId);
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
        ->execute([$nodeId, 'Removed: moved to Gelöscht (subtree kept).']);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('Remove failed: ' . $e->getMessage(), 'err');
    header('Location: /?id=' . $nodeId);
    exit;
  }

  flash_set('Removed (' . $moved . ' items) → Gelöscht.', 'info');
  header('Location: /?id=' . $deletedRootId);
  exit;
}

flash_set('Unknown action.', 'err');
header('Location: /?id=' . $nodeId);
exit;

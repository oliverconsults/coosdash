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
  $pdo->prepare('UPDATE nodes SET main_status="later", worker_status="done" WHERE id=?')->execute([$nodeId]);
  $n = propagateDone($pdo, $nodeId);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Status: later (worker done). Descendants done: ' . $n]);
  flash_set('Auf later gesetzt (done).', 'info');
  header('Location: /?id=' . $nodeId);
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

flash_set('Unknown action.', 'err');
header('Location: /?id=' . $nodeId);
exit;

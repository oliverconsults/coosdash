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

if ($action === 'approve_to_todo_recursive') {
  // set this node
  $pdo->prepare('UPDATE nodes SET worker_status="todo" WHERE id=? AND main_status="active"')->execute([$nodeId]);
  $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, "oliver", ?)')
      ->execute([$nodeId, 'Freigabe: worker_status approve→todo (rekursiv)']);

  $n = propagateTodo($pdo, $nodeId);
  flash_set('Freigegeben. Rekursiv aktiviert: ' . $n . ' Unterpunkte.', 'info');
  header('Location: /?id=' . $nodeId);
  exit;
}

flash_set('Unknown action.', 'err');
header('Location: /?id=' . $nodeId);
exit;

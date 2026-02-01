<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// load full tree (we already do this in index.php, but this is a small standalone endpoint)
$allNodes = $pdo->query("SELECT id, parent_id, title, worker_status, blocked_until, blocked_by_node_id FROM nodes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$byParent = [];
$byId = [];
foreach ($allNodes as $n) {
  $id = (int)$n['id'];
  $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
  $byParent[$pid][] = $n;
  $byId[$id] = $n;
}

// find Projekte root
$projectsRootId = 0;
foreach (($byParent[0] ?? []) as $r) {
  if (((string)($r['title'] ?? '')) === 'Projekte') { $projectsRootId = (int)$r['id']; break; }
}

$isUnder = function(int $nid, int $rootId) use ($byId): bool {
  if ($nid <= 0 || $rootId <= 0) return false;
  $cur = $nid;
  for ($i=0; $i<200; $i++) {
    $row = $byId[$cur] ?? null;
    if (!$row) return false;
    $pid = $row['parent_id'];
    if ($pid === null) return false;
    $pid = (int)$pid;
    if ($pid === $rootId) return true;
    $cur = $pid;
  }
  return false;
};

// find active project (top-level under Projekte)
$activeProjectId = 0;
if ($projectsRootId > 0 && $nodeId > 0 && isset($byId[$nodeId]) && $isUnder($nodeId, $projectsRootId)) {
  $cur = $nodeId;
  for ($i=0; $i<200; $i++) {
    $row = $byId[$cur] ?? null;
    if (!$row) break;
    $pid = $row['parent_id'];
    if ($pid === null) break;
    $pid = (int)$pid;
    if ($pid === $projectsRootId) { $activeProjectId = $cur; break; }
    $cur = $pid;
  }
}

$isBlockedNode = function(int $id) use ($byId): bool {
  $n = $byId[$id] ?? null;
  if (!$n) return true;
  $bu = (string)($n['blocked_until'] ?? '');
  $bb = (int)($n['blocked_by_node_id'] ?? 0);
  if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;
  if ($bb > 0) {
    $bn = $byId[$bb] ?? null;
    if (!$bn) return true;
    return (string)($bn['worker_status'] ?? '') !== 'done';
  }
  return false;
};

$nodes = [];
$edges = [];

if ($activeProjectId > 0) {
  $in = [];
  $stack = [$activeProjectId];
  while ($stack) {
    $cur = array_pop($stack);
    $in[$cur] = true;
    foreach (($byParent[$cur] ?? []) as $ch) {
      $stack[] = (int)$ch['id'];
    }
  }

  foreach ($in as $id => $_) {
    $n = $byId[$id] ?? null;
    if (!$n) continue;
    $ws = (string)($n['worker_status'] ?? '');
    $status = $ws;
    if ($ws !== 'done' && $isBlockedNode($id)) $status = 'blocked';

    $nodes[] = [
      'data' => [
        'id' => 'n' . $id,
        'node_id' => $id,
        'label' => '#' . $id . ' ' . (string)($n['title'] ?? ''),
        'title' => (string)($n['title'] ?? ''),
        'status' => $status,
        'worker_status' => $ws,
      ]
    ];

    $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
    if ($pid > 0 && isset($in[$pid])) {
      $edges[] = ['data' => ['id' => 'e' . $pid . '_' . $id, 'source' => 'n' . $pid, 'target' => 'n' . $id, 'type' => 'tree']];
    }

    $bb = (int)($n['blocked_by_node_id'] ?? 0);
    if ($bb > 0 && isset($in[$bb])) {
      $edges[] = ['data' => ['id' => 'b' . $id . '_' . $bb, 'source' => 'n' . $bb, 'target' => 'n' . $id, 'type' => 'blocked_by']];
    }
  }
}

echo json_encode([
  'ok' => true,
  'active_project_id' => $activeProjectId,
  'elements' => array_merge($nodes, $edges),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

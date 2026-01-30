<?php
require_once __DIR__ . '/../public/functions.inc.php';

$pdo = db();
$rows = $pdo->query("SELECT id,parent_id,title,worker_status,description,created_at FROM nodes ORDER BY id")->fetchAll();

$byId = [];
$children = [];
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
  $byId[$id] = $r;
  $children[$pid][] = $id;
}

$projectsRoot = 0;
foreach (($children[0] ?? []) as $rid) {
  if (($byId[$rid]['title'] ?? '') === 'Projekte') {
    $projectsRoot = (int)$rid;
    break;
  }
}

if (!$projectsRoot) {
  fwrite(STDERR, "No Projekte root\n");
  exit(1);
}

// Traverse subtree under Projekte
$depthById = [];
$stack = [[$projectsRoot, 0]];
while ($stack) {
  [$id, $d] = array_pop($stack);
  $depthById[$id] = $d;
  foreach (($children[$id] ?? []) as $cid) {
    $stack[] = [(int)$cid, $d + 1];
  }
}

$maxDepth = -1;
$cands = [];
foreach ($depthById as $id => $d) {
  if ($id === $projectsRoot) continue;
  $n = $byId[$id] ?? null;
  if (!$n) continue;
  if (($n['worker_status'] ?? '') !== 'todo_james') continue;
  if (!empty($children[$id] ?? [])) continue; // leaf only

  $maxDepth = max($maxDepth, $d);
  $cands[] = [
    'id' => (int)$id,
    'depth' => (int)$d,
    'parent_id' => $n['parent_id'] === null ? 0 : (int)$n['parent_id'],
    'title' => (string)$n['title'],
  ];
}

if (!$cands) {
  echo "NO_TODO\n";
  exit(0);
}

// deepest only
$cands = array_values(array_filter($cands, fn($c) => $c['depth'] === $maxDepth));

// Parent-internal chronological gating: candidate must be the smallest-id todo_james leaf among its siblings
$cands = array_values(array_filter($cands, function($c) use ($children, $byId) {
  $pid = (int)$c['parent_id'];
  $sibIds = $children[$pid] ?? [];

  $min = null;
  foreach ($sibIds as $sid) {
    $sid = (int)$sid;
    $sn = $byId[$sid] ?? null;
    if (!$sn) continue;
    if (($sn['worker_status'] ?? '') !== 'todo_james') continue;
    // only leaf siblings
    if (!empty($children[$sid] ?? [])) continue;
    if ($min === null || $sid < $min) $min = $sid;
  }
  return $min === null ? true : ((int)$c['id'] === (int)$min);
}));

usort($cands, function($a, $b) {
  if ($a['depth'] !== $b['depth']) return $b['depth'] <=> $a['depth'];
  return $a['id'] <=> $b['id'];
});

$target = $cands[0];

echo "TARGET\n";
echo json_encode($target, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";

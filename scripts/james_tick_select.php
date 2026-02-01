<?php
require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();
$rows = $pdo->query("SELECT id,parent_id,title,worker_status,description,created_at,blocked_until,blocked_by_node_id FROM nodes ORDER BY id")->fetchAll();

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

$isBlocked = function(array $n) use ($byId): bool {
  $bu = (string)($n['blocked_until'] ?? '');
  $bb = (int)($n['blocked_by_node_id'] ?? 0);

  // time-based block
  if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;

  // dependency-based block: only if blocker exists and is NOT done
  if ($bb > 0) {
    $bn = $byId[$bb] ?? null;
    if (!$bn) return true;
    return (string)($bn['worker_status'] ?? '') !== 'done';
  }

  return false;
};

// Helper: walk up to the top-level project under Projekte (direct child of Projekte root)
$topProjectId = function(int $nodeId) use ($byId, $projectsRoot): int {
  $cur = $nodeId;
  for ($i=0; $i<80; $i++) {
    $row = $byId[$cur] ?? null;
    if (!$row) return 0;
    $pid = $row['parent_id'];
    if ($pid === null) return 0;
    $pid = (int)$pid;
    if ($pid === $projectsRoot) return $cur;
    $cur = $pid;
  }
  return 0;
};

// If a node has (somewhere below) a task that is blocked AND not open for James,
// we should not pick the ancestor (otherwise James "works" on the wrong level and misses a hidden blocker).
$hasBlockedNonOpenDesc = function(int $nodeId) use (&$hasBlockedNonOpenDesc, $children, $byId, $isBlocked): bool {
  foreach (($children[$nodeId] ?? []) as $cid) {
    $cid = (int)$cid;
    $cn = $byId[$cid] ?? null;
    if (!$cn) continue;

    $st = (string)($cn['worker_status'] ?? '');
    // "not open" = not todo_james (done is fine)
    $notOpen = ($st !== 'todo_james' && $st !== 'done');
    if ($notOpen && $isBlocked($cn)) return true;

    if ($hasBlockedNonOpenDesc($cid)) return true;
  }
  return false;
};

// Build candidate nodes (unblocked todo_james) + compute their top project
$cands = [];
foreach ($depthById as $id => $d) {
  if ($id === $projectsRoot) continue;
  $n = $byId[$id] ?? null;
  if (!$n) continue;
  if (($n['worker_status'] ?? '') !== 'todo_james') continue;
  // NOTE: we no longer require leaf-only; James should pick the deepest open node even if it has children.
  if ($isBlocked($n)) continue; // do not pick blocked tasks
  if ($hasBlockedNonOpenDesc((int)$id)) continue; // avoid ancestors above blocked non-open tasks

  $projId = $topProjectId((int)$id);
  if ($projId <= 0) continue;

  $cands[] = [
    'id' => (int)$id,
    'depth' => (int)$d,
    'parent_id' => $n['parent_id'] === null ? 0 : (int)$n['parent_id'],
    'project_id' => (int)$projId,
    'title' => (string)$n['title'],
  ];
}

if (!$cands) {
  echo "NO_TODO\n";
  exit(0);
}

// Choose project fairly: round-robin across all eligible projects (avoids streaks)
$eligibleProjects = [];
foreach ($cands as $c) $eligibleProjects[(int)$c['project_id']] = true;
$eligibleProjects = array_keys($eligibleProjects);

$statePath = '/var/www/coosdash/shared/data/james_worker_state.json';
$state = ['queue'=>[]];
if (is_file($statePath)) {
  $raw = @file_get_contents($statePath);
  $j = $raw ? json_decode($raw, true) : null;
  if (is_array($j)) $state = array_merge($state, $j);
}

$queue = array_values(array_filter(array_map('intval', (array)($state['queue'] ?? []))));

// keep only currently eligible projects
$eligibleSet = array_flip($eligibleProjects);
$queue = array_values(array_filter($queue, fn($pid) => isset($eligibleSet[$pid])));

// append newly eligible projects (shuffled)
$missing = array_values(array_filter($eligibleProjects, fn($pid) => !in_array((int)$pid, $queue, true)));
if ($missing) {
  shuffle($missing);
  $queue = array_merge($queue, $missing);
}

// if still empty (shouldn't happen), fallback to random
if (!$queue) {
  $activeProject = (int)$eligibleProjects[array_rand($eligibleProjects)];
} else {
  $activeProject = (int)$queue[0];
  // rotate: move picked to end
  $queue = array_merge(array_slice($queue, 1), [$activeProject]);
}

@file_put_contents($statePath, json_encode(['queue'=>$queue,'picked_at'=>date('Y-m-d H:i:s')], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Filter to the chosen project only
$cands = array_values(array_filter($cands, fn($c) => (int)$c['project_id'] === (int)$activeProject));

// Within the chosen project: pick deepest; tie-break by smallest id
$maxDepth = max(array_map(fn($c) => (int)$c['depth'], $cands));
$cands = array_values(array_filter($cands, fn($c) => (int)$c['depth'] === (int)$maxDepth));

// Parent-internal chronological gating: candidate must be the smallest-id todo_james leaf among its siblings
$cands = array_values(array_filter($cands, function($c) use ($children, $byId, $isBlocked) {
  $pid = (int)$c['parent_id'];
  $sibIds = $children[$pid] ?? [];

  $min = null;
  foreach ($sibIds as $sid) {
    $sid = (int)$sid;
    $sn = $byId[$sid] ?? null;
    if (!$sn) continue;
    if (($sn['worker_status'] ?? '') !== 'todo_james') continue;
    // ignore blocked siblings for the "oldest-first" rule
    if ($isBlocked($sn)) continue;
    if ($min === null || $sid < $min) $min = $sid;
  }
  return $min === null ? true : ((int)$c['id'] === (int)$min);
}));

usort($cands, function($a, $b) {
  if ($a['depth'] !== $b['depth']) return $b['depth'] <=> $a['depth'];
  return $a['id'] <=> $b['id'];
});

if (!$cands) {
  echo "NO_TODO\n";
  exit(0);
}

$target = $cands[0];

echo "TARGET\n";
echo json_encode($target, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";

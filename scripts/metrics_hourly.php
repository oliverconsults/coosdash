<?php
// Hourly metrics snapshot for COOS CRM.
// Stores counts for Ideen + Projekte by worker_status (leaf nodes), plus totals for Später/Gelöscht.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();

// Load minimal fields
$rows = $pdo->query("SELECT id, parent_id, title, worker_status, blocked_until, blocked_by_node_id FROM nodes ORDER BY id")->fetchAll();

$byId = [];
$byParent = [];
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
  $byId[$id] = $r;
  $byParent[$pid][] = $id;
}

// Root IDs by title
$rootIdByTitle = [];
foreach (($byParent[0] ?? []) as $rid) {
  $t = (string)($byId[$rid]['title'] ?? '');
  if ($t !== '') $rootIdByTitle[$t] = (int)$rid;
}

$ideasRoot = (int)($rootIdByTitle['Ideen'] ?? 0);
$projectsRoot = (int)($rootIdByTitle['Projekte'] ?? 0);
$laterRoot = (int)($rootIdByTitle['Später'] ?? 0);
$deletedRoot = (int)($rootIdByTitle['Gelöscht'] ?? 0);

function isUnderRoot(array $byId, int $nodeId, int $rootId): bool {
  if (!$rootId) return false;
  $cur = $nodeId;
  for ($i=0; $i<80; $i++) {
    $row = $byId[$cur] ?? null;
    if (!$row) return false;
    $pid = $row['parent_id'];
    if ($pid === null) return false;
    $pid = (int)$pid;
    if ($pid === $rootId) return true;
    $cur = $pid;
  }
  return false;
}

function isLeaf(array $byParent, int $id): bool {
  return empty($byParent[$id]);
}

$ideas = ['todo_oliver'=>0,'todo_james'=>0,'blocked'=>0,'done'=>0];
$projects = ['todo_oliver'=>0,'todo_james'=>0,'blocked'=>0,'done'=>0];
$laterTotal = 0;
$deletedTotal = 0;

foreach ($byId as $id => $n) {
  $id = (int)$id;
  // skip container roots themselves
  if (($n['parent_id'] ?? null) === null) continue;

  if ($laterRoot && isUnderRoot($byId, $id, $laterRoot)) {
    $laterTotal++;
    continue;
  }
  if ($deletedRoot && isUnderRoot($byId, $id, $deletedRoot)) {
    $deletedTotal++;
    continue;
  }

  // status breakdown: leaf nodes only
  if (!isLeaf($byParent, $id)) continue;

  $st = (string)($n['worker_status'] ?? '');
  if (!in_array($st, ['todo_oliver','todo_james','done'], true)) continue;

  // Treat blocked James tasks as their own metric bucket (same logic as Kanban)
  if ($st === 'todo_james') {
    $blockedUntil = (string)($n['blocked_until'] ?? '');
    $blockedBy = (int)($n['blocked_by_node_id'] ?? 0);
    $isBlockedUntil = ($blockedUntil !== '' && strtotime($blockedUntil) && strtotime($blockedUntil) > time());
    $isBlockedBy = ($blockedBy > 0);
    if ($isBlockedUntil || $isBlockedBy) $st = 'blocked';
  }

  if ($ideasRoot && isUnderRoot($byId, $id, $ideasRoot)) {
    $ideas[$st]++;
    continue;
  }
  if ($projectsRoot && isUnderRoot($byId, $id, $projectsRoot)) {
    $projects[$st]++;
    continue;
  }
}

// Round timestamp to the hour
$ts = new DateTime('now', new DateTimeZone('Europe/Berlin'));
$ts->setTime((int)$ts->format('H'), 0, 0);
$tsStr = $ts->format('Y-m-d H:i:s');

$pdo->exec("CREATE TABLE IF NOT EXISTS metrics_hourly (
  ts DATETIME NOT NULL,
  ideas_todo_oliver INT NOT NULL,
  ideas_todo_james INT NOT NULL,
  ideas_blocked INT NOT NULL,
  ideas_done INT NOT NULL,
  projects_todo_oliver INT NOT NULL,
  projects_todo_james INT NOT NULL,
  projects_blocked INT NOT NULL,
  projects_done INT NOT NULL,
  later_total INT NOT NULL,
  deleted_total INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Backfill migration for existing tables
$pdo->exec("ALTER TABLE metrics_hourly
  ADD COLUMN IF NOT EXISTS ideas_blocked INT NOT NULL DEFAULT 0 AFTER ideas_todo_james,
  ADD COLUMN IF NOT EXISTS projects_blocked INT NOT NULL DEFAULT 0 AFTER projects_todo_james;");

$sql = "INSERT INTO metrics_hourly
(ts, ideas_todo_oliver, ideas_todo_james, ideas_blocked, ideas_done, projects_todo_oliver, projects_todo_james, projects_blocked, projects_done, later_total, deleted_total)
VALUES
(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
ideas_todo_oliver=VALUES(ideas_todo_oliver),
ideas_todo_james=VALUES(ideas_todo_james),
ideas_blocked=VALUES(ideas_blocked),
ideas_done=VALUES(ideas_done),
projects_todo_oliver=VALUES(projects_todo_oliver),
projects_todo_james=VALUES(projects_todo_james),
projects_blocked=VALUES(projects_blocked),
projects_done=VALUES(projects_done),
later_total=VALUES(later_total),
deleted_total=VALUES(deleted_total);";

$st = $pdo->prepare($sql);
$st->execute([
  $tsStr,
  (int)$ideas['todo_oliver'],
  (int)$ideas['todo_james'],
  (int)$ideas['blocked'],
  (int)$ideas['done'],
  (int)$projects['todo_oliver'],
  (int)$projects['todo_james'],
  (int)$projects['blocked'],
  (int)$projects['done'],
  (int)$laterTotal,
  (int)$deletedTotal,
]);

$tsLine = date('Y-m-d H:i:s');
echo $tsLine . "  OK metrics_hourly ts={$tsStr} ideas=" . json_encode($ideas) . " projects=" . json_encode($projects) . " later={$laterTotal} deleted={$deletedTotal}\n";

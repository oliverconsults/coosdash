<?php
// Shared left navigation panel (Tree + metrics chart + view buttons)
// Usage: requireLogin() must already have run.
// Expects: $pdo (PDO), $nodeId (int), $view (string)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  throw new RuntimeException('left_nav.inc.php: missing $pdo');
}
$nodeId = isset($nodeId) ? (int)$nodeId : 0;
$view = isset($view) ? (string)$view : 'work';

// ---- helpers (kept local to avoid global collisions) ----
$buildSectionMap = function(array $byParentAll, int $parentId=0, string $section='') use (&$buildSectionMap): array {
  $out = [];
  if (empty($byParentAll[$parentId])) return $out;
  foreach ($byParentAll[$parentId] as $n) {
    $id = (int)$n['id'];
    $sec = $section;
    if ($parentId === 0) $sec = (string)$n['title'];
    $out[$id] = $sec;
    $out += $buildSectionMap($byParentAll, $id, $sec);
  }
  return $out;
};

$allNodes = $pdo->query("SELECT id, parent_id, title, worker_status, priority, created_at, updated_at, blocked_until, blocked_by_node_id, token_in, token_out, worktime FROM nodes ORDER BY COALESCE(priority,999), id")->fetchAll();
$byParentAll = [];
$byIdAll = [];
foreach ($allNodes as $n) {
  $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
  $byParentAll[$pid][] = $n;
  $byIdAll[(int)$n['id']] = $n;
}
$sectionByIdAll = $buildSectionMap($byParentAll, 0, '');

// attachment counts (for tree icons)
$attCountById = [];
try {
  $st = $pdo->query('SELECT node_id, COUNT(*) AS c FROM node_attachments GROUP BY node_id');
  foreach ($st->fetchAll() as $r) $attCountById[(int)$r['node_id']] = (int)$r['c'];
} catch (Throwable $e) {
  $attCountById = [];
}

// build open-path map for current selection
$open = [];
if ($nodeId > 0) {
  $cur = $nodeId;
  for ($i=0; $i<120; $i++) {
    $row = $byIdAll[$cur] ?? null;
    if (!$row) break;
    $pid = $row['parent_id'];
    if ($pid === null) break;
    $pid = (int)$pid;
    $open[$pid] = true;
    $cur = $pid;
  }
}

// render tree (uses same counting rules as dashboard)
$renderTree = function(int $parentId=0, int $depth=0, array $prefix=[]) use (&$renderTree, $byParentAll, $byIdAll, $sectionByIdAll, $open, $attCountById, $nodeId): void {
  if (empty($byParentAll[$parentId])) return;
  $i = 0;
  foreach ($byParentAll[$parentId] as $n) {
    $i++;
    $id = (int)$n['id'];
    $title = (string)($n['title'] ?? '');
    $hasKids = !empty($byParentAll[$id]);
    $isActive = ($id === (int)$nodeId);

    // show total number of direct children in parentheses (matches dashboard)
    $directCount = !empty($byParentAll[$id]) ? count($byParentAll[$id]) : 0;
    $countTxt = $hasKids ? ' (' . $directCount . ')' : '';

    $indent = $depth * 5;
    $numParts = array_merge($prefix, [$i]);
    $num = implode('.', $numParts) . '.';

    // right tag: subtree totals by assignee (include self except container roots)
    $todoJames = 0; $todoOliver = 0;
    $stack = [$id];
    while ($stack) {
      $curId = array_pop($stack);
      $isRootNode = (($byIdAll[$curId]['parent_id'] ?? null) === null);
      $tTitle = (string)($byIdAll[$curId]['title'] ?? '');
      $isContainerRootNode = $isRootNode && in_array($tTitle, ['Ideen','Projekte','Später','Gelöscht'], true);
      if (!$isContainerRootNode) {
        $stSelf = (string)($byIdAll[$curId]['worker_status'] ?? '');
        if ($stSelf === 'todo_james') $todoJames++;
        if ($stSelf === 'todo_oliver') $todoOliver++;
      }
      foreach (($byParentAll[$curId] ?? []) as $cc) $stack[] = (int)$cc['id'];
    }
    $parts = [];
    if ($todoJames > 0) $parts[] = 'James: ' . $todoJames;
    if ($todoOliver > 0) $parts[] = 'Oliver: ' . $todoOliver;
    $statusText = $parts ? implode(' | ', $parts) : '';

    $shade = max(0, min(4, $depth));
    $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

    $sec = (string)($sectionByIdAll[$id] ?? '');
    $msClass = '';
    if ($sec === 'Gelöscht') $msClass = ' ms-canceled';
    if ($sec === 'Später') $msClass = ' ms-later';

    $hasAtt = !empty($attCountById[$id]);
    $attIcon = $hasAtt ? ' <span class="att-clip" title="Attachment" style="margin-left:4px;">📁</span>' : '';

    $forceOpenAll = (!empty($_GET['open']) && $_GET['open'] === 'all') || !empty($_GET['q']);
    $isOpen = $forceOpenAll || $isActive || !empty($open[$id]);

    // keep current view when clicking in tree
    $v = (string)($_GET['view'] ?? ($_COOKIE['coos_view'] ?? 'work'));
    if (!in_array($v, ['work','kanban','report'], true)) $v = 'work';
    $base = ($v === 'kanban') ? '/kanban.php' : (($v === 'report') ? '/report.php' : '/');
    $href = $base . '?id=' . $id . '&view=' . rawurlencode($v);

    if ($sec === 'Ideen' && (($byIdAll[$id]['parent_id'] ?? null) !== null)) {
      $href = '/new_project.php?from_node=' . $id;
    }

    if ($hasKids) {
      echo '<details class="tree-branch" ' . ($isOpen ? 'open' : '') . '>';
      echo '<summary class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '" style="margin-left:' . $indent . 'px">';
      echo '<a href="' . h($href) . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . $attIcon . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</summary>';
      $renderTree($id, $depth+1, $numParts);
      echo '</details>';
    } else {
      echo '<div class="tree-leaf"><div class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '" style="margin-left:' . $indent . 'px">';
      echo '<a href="' . h($href) . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . $attIcon . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</div></div>';
    }
  }
};

// metrics chart
$metricsRows = [];
try {
  $st = $pdo->prepare("SELECT ts, projects_todo_oliver, projects_todo_james, projects_blocked, projects_done
                        FROM metrics_hourly
                        WHERE ts >= (SELECT DATE_SUB(MAX(ts), INTERVAL 72 HOUR) FROM metrics_hourly)
                        ORDER BY ts ASC");
  $st->execute();
  $metricsRows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $metricsRows = [];
}

// view button URLs
$baseQs = [];
if ($nodeId) $baseQs['id'] = (int)$nodeId;
if (!empty($_GET['open'])) $baseQs['open'] = (string)$_GET['open'];
if (!empty($_GET['q'])) $baseQs['q'] = (string)$_GET['q'];
$hrefWork   = '/?' . http_build_query(array_merge($baseQs, ['view'=>'work']));
$hrefKanban = '/kanban.php' . ($baseQs ? ('?' . http_build_query($baseQs)) : '');
$hrefReport = '/report.php' . ($baseQs ? ('?' . http_build_query($baseQs)) : '');

?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Projektansicht</h2>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
      <a class="btn btn-md <?php echo $view==='work'?'btn-gold active':''; ?>" href="<?php echo h($hrefWork); ?>">Work</a>
      <a class="btn btn-md <?php echo $view==='kanban'?'btn-gold active':''; ?>" href="<?php echo h($hrefKanban); ?>">Kanban</a>
      <a class="btn btn-md <?php echo $view==='report'?'btn-gold active':''; ?>" href="<?php echo h($hrefReport); ?>">Report</a>
    </div>
  </div>

  <form method="get" style="margin:8px 0 0 0; display:flex; gap:10px;">
    <input type="hidden" name="view" value="<?php echo h($view); ?>">
    <input type="text" name="q" placeholder="Notizen durchsuchen..." value="<?php echo h((string)($_GET['q'] ?? '')); ?>" style="flex:1">
    <?php if ($nodeId): ?><input type="hidden" name="id" value="<?php echo (int)$nodeId; ?>"><?php endif; ?>
    <?php if (!empty($_GET['open'])): ?><input type="hidden" name="open" value="<?php echo h((string)$_GET['open']); ?>"><?php endif; ?>
    <button class="btn" type="submit">Suchen</button>
  </form>

  <div style="height:10px"></div>
  <div class="tree">
    <?php $renderTree(0,0,[]); ?>
  </div>

  <?php require __DIR__ . '/progress_chart.inc.php'; ?>
</div>

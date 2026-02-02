<?php
require_once __DIR__ . '/functions_v3.inc.php';
require_once __DIR__ . '/attachments_lib.php';
requireLogin();

$pdo = db();

// current selection
$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// right-panel view
$view = (string)($_GET['view'] ?? '');
if ($view === '') {
  $view = (string)($_COOKIE['coos_view'] ?? 'work');
}
if (!in_array($view, ['work','kanban','report'], true)) $view = 'work';

// persist selected view
@setcookie('coos_view', $view, time() + 60*60*24*180, '/');

// If user lands on / with a non-work view, forward to the dedicated view pages.
if ($view === 'kanban') {
  $qs = [];
  if ($nodeId) $qs['id'] = $nodeId;
  $qs['view'] = 'kanban';
  header('Location: /kanban.php' . ($qs ? ('?' . http_build_query($qs)) : ''));
  exit;
}
if ($view === 'report') {
  $qs = [];
  if ($nodeId) $qs['id'] = $nodeId;
  $qs['view'] = 'report';
  header('Location: /report.php' . ($qs ? ('?' . http_build_query($qs)) : ''));
  exit;
}

// special routing: selecting the "Projekte" root in Work should go to new_project.php
if ($nodeId > 0 && $view === 'work') {
  try {
    $st = $pdo->prepare('SELECT title, parent_id FROM nodes WHERE id=?');
    $st->execute([$nodeId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && (string)$r['title'] === 'Projekte' && $r['parent_id'] === null) {
      header('Location: /new_project.php');
      exit;
    }
  } catch (Throwable $e) {
    // ignore
  }
}

// form state (to preserve user input on validation errors)
$formNote = '';
$formAsChild = false;
$formChildTitle = '';

// resolve root sections (Ideen/Projekte/Später/Gelöscht) for styling + actions
function buildSectionMap(array $byParentAll, int $parentId=0, string $section=''): array {
  $out = [];
  if (empty($byParentAll[$parentId])) return $out;
  foreach ($byParentAll[$parentId] as $n) {
    $id = (int)$n['id'];
    $sec = $section;
    if ($parentId === 0) $sec = (string)$n['title'];
    $out[$id] = $sec;
    $out += buildSectionMap($byParentAll, $id, $sec);
  }
  return $out;
}

function propagateDelegation(PDO $pdo, int $rootId, string $from, string $to): int {
  $count = 0;
  $stack = [$rootId];
  while ($stack) {
    $cur = array_pop($stack);
    $st = $pdo->prepare('SELECT id, worker_status FROM nodes WHERE parent_id=?');
    $st->execute([$cur]);
    $kids = $st->fetchAll();
    foreach ($kids as $k) {
      $kid = (int)$k['id'];
      $stack[] = $kid;
      if (($k['worker_status'] ?? '') === $from) {
        $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$to, $kid]);
        $count++;
      }
    }
  }
  return $count;
}

function propagateStatusAllDescendants(PDO $pdo, int $rootId, string $to): int {
  $count = 0;
  $stack = [$rootId];
  while ($stack) {
    $cur = array_pop($stack);
    $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
    $st->execute([$cur]);
    $kids = $st->fetchAll();
    foreach ($kids as $k) {
      $kid = (int)$k['id'];
      $stack[] = $kid;
      $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$to, $kid]);
      $count++;
    }
  }
  return $count;
}

// handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'save_task') {
    // Editing existing task text from UI is disabled to avoid noisy histories.
    flash_set('Direktes Editieren von Tasks ist deaktiviert. Bitte nur Subtasks anlegen.', 'err');
    header('Location: /?id=' . (int)($_POST['node_id'] ?? 0));
    exit;
  }

  if ($action === 'add_subtask') {
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $formChildTitle = (string)($_POST['title'] ?? '');
    $formChildBody = (string)($_POST['description'] ?? '');
    $taskType = (string)($_POST['task_type'] ?? 'planung');
    if (!in_array($taskType, ['planung','umsetzung'], true)) $taskType = 'planung';

    $title = trim($formChildTitle);
    $body = trim($formChildBody);

    if (!$parentId) {
      flash_set('Projekt fehlt.', 'err');
    } elseif ($title === '') {
      flash_set('Titel für Subtask bitte eingeben.', 'err');
    } elseif ($body === '') {
      flash_set('Beschreibung für Subtask bitte eingeben.', 'err');
    } elseif (mb_strlen($title) > 40) {
      flash_set('Titel darf max. 40 Zeichen haben.', 'err');
    } else {
      $ts = date('d.m.Y H:i');
      $marker = ($taskType === 'umsetzung') ? ' ##UMSETZUNG##' : '';

      // Always delegate to James (both parent + new child)
      $stTxt = 'todo_james';
      $desc = "[oliver] {$ts} Statusänderung: {$stTxt}{$marker}\n\n" . rtrim($formChildBody);

      $st = $pdo->prepare('INSERT INTO nodes (parent_id, title, description, priority, created_by, worker_status) VALUES (?, ?, ?, ?, ?, ?)');
      $st->execute([$parentId, $title, $desc, null, 'oliver', $stTxt]);
      $newId = (int)$pdo->lastInsertId();

      // Ensure parent is also todo_james
      $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$stTxt, $parentId]);

      // optional attachment upload (Oliver)
      if (!empty($_FILES['attachment'])) {
        try {
          $res = attachments_store_upload($pdo, $newId, $_FILES['attachment'], 'oliver');
          if (is_array($res) && !empty($res['err'])) {
            flash_set('Subtask angelegt, aber Attachment fehlgeschlagen: ' . $res['err'], 'err');
          }
        } catch (Throwable $e) {
          flash_set('Subtask angelegt, aber Attachment fehlgeschlagen.', 'err');
        }
      }

      // Keep history clean: do not prepend into parent description.
      workerlog_append($newId, "[oliver] {$ts} Statusänderung: {$stTxt}{$marker}");
      workerlog_append($parentId, "[oliver] {$ts} Subtask angelegt -> todo_james");

      flash_set('Subtask angelegt & an James. (Parent ebenfalls todo_james)', 'info');
      header('Location: /?id=' . $newId);
      exit;
    }
  }

  if ($action === 'set_worker') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $worker = (string)($_POST['worker_status'] ?? '');
    $allowed = ['todo_james','todo_oliver','done'];
    if ($nid && in_array($worker, $allowed, true)) {
      // No-op guard: if status is unchanged, don't spam description/workerlog
      $stCur = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
      $stCur->execute([$nid]);
      $cur = $stCur->fetch();
      $curStatus = (string)($cur['worker_status'] ?? '');
      if ($curStatus === $worker) {
        flash_set('Status unverändert.', 'info');
        header('Location: /?id=' . $nid);
        exit;
      }

      $st = $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?');
      $st->execute([$worker, $nid]);

      // prepend change into description (newest first)
      $ts = date('d.m.Y H:i');
      $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
          ->execute(["[oliver] {$ts} Statusänderung: {$worker}\n\n", $nid]);

      // delegation rule: if delegating to James, cascade todo_oliver descendants to todo_james
      if ($worker === 'todo_james') {
        $moved = propagateDelegation($pdo, $nid, 'todo_oliver', 'todo_james');
        if ($moved > 0) {
          $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
              ->execute(["[oliver] {$ts} Delegation: {$moved} Subtasks zu James\n\n", $nid]);
        }
      }

      // done rule: when marking a node done, mark ALL descendants done as well
      if ($worker === 'done') {
        $nDone = propagateStatusAllDescendants($pdo, $nid, 'done');
        if ($nDone > 0) {
          $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
              ->execute(["[oliver] {$ts} Done: {$nDone} Subtasks erledigt\n\n", $nid]);
        }
      }

      workerlog_append($nid, "[oliver] {$ts} UI: set_worker -> {$worker}");

      flash_set('Status gespeichert.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Ungültiger Status.', 'err');
  }

  // set_main removed (main_status dropped)
}

// Load full tree for nav
$allNodes = $pdo->query("SELECT id, parent_id, title, worker_status, priority, created_at, updated_at, blocked_until, blocked_by_node_id, token_in, token_out, worktime FROM nodes ORDER BY COALESCE(priority,999), id")->fetchAll();
$byParentAll = [];
$byIdAll = [];
foreach ($allNodes as $n) {
  $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
  $byParentAll[$pid][] = $n;
  $byIdAll[(int)$n['id']] = $n;
}

$sectionByIdAll = buildSectionMap($byParentAll, 0, '');

// attachment counts (for tree icons)
$attCountById = [];
try {
  $st = $pdo->query('SELECT node_id, COUNT(*) AS c FROM node_attachments GROUP BY node_id');
  foreach ($st->fetchAll() as $r) $attCountById[(int)$r['node_id']] = (int)$r['c'];
} catch (Throwable $e) {
  $attCountById = [];
}

// hourly metrics for progress chart (always anchored to latest ts)
$metricsRows = [];
try {
  // Pull an anchored window so the chart always ends at the latest available hour.
  $st = $pdo->prepare("SELECT ts, projects_todo_oliver, projects_todo_james, projects_blocked, projects_done,
                               ideas_todo_oliver, ideas_todo_james, ideas_blocked, ideas_done,
                               later_total, deleted_total
                        FROM metrics_hourly
                        WHERE ts >= (SELECT DATE_SUB(MAX(ts), INTERVAL 72 HOUR) FROM metrics_hourly)
                        ORDER BY ts ASC");
  $st->execute();
  $metricsRows = $st->fetchAll();
} catch (Throwable $e) {
  $metricsRows = [];
}

// Done Projekte (direct children of root "Projekte")
// Important: a project counts as done ONLY if the whole subtree is done.
$doneProjects = [];
try {
  projects_migrate($pdo);
  $projectsId = projects_root_id($pdo);
  if ($projectsId > 0) {
    $st = $pdo->prepare("SELECT n.id, n.title, n.updated_at, p.slug
                         FROM nodes n
                         JOIN projects p ON p.node_id = n.id
                         WHERE n.parent_id = ?
                         ORDER BY n.updated_at DESC, n.id DESC");
    $st->execute([$projectsId]);
    $cands = $st->fetchAll(PDO::FETCH_ASSOC);

    $subtreeAllDone = function(int $rootId) use (&$subtreeAllDone, $byParentAll, $byIdAll): bool {
      $stack = [$rootId];
      while ($stack) {
        $cur = array_pop($stack);
        foreach (($byParentAll[$cur] ?? []) as $kid) {
          $kidId = (int)($kid['id'] ?? 0);
          if ($kidId <= 0) continue;
          $st = (string)($byIdAll[$kidId]['worker_status'] ?? '');
          if ($st !== 'done') return false;
          $stack[] = $kidId;
        }
      }
      return true;
    };

    foreach ($cands as $p) {
      $pid = (int)($p['id'] ?? 0);
      if ($pid <= 0) continue;
      if ((string)($p['slug'] ?? '') === '') continue;
      if ((string)($p['title'] ?? '') === '') continue;
      if ((string)($byIdAll[$pid]['worker_status'] ?? '') !== 'done') continue;
      if (!$subtreeAllDone($pid)) continue;
      $doneProjects[] = $p;
      if (count($doneProjects) >= 60) break;
    }
  }
} catch (Throwable $e) {
  $doneProjects = [];
}

function svg_polyline_points(array $vals, float $w, float $h, int $pad=6): string {
  $n = count($vals);
  if ($n <= 0) return '';
  $min = min($vals);
  $max = max($vals);
  if ($min === $max) { $min = $min - 1; $max = $max + 1; }
  $x0 = $pad; $y0 = $pad; $x1 = $w - $pad; $y1 = $h - $pad;
  $pts = [];
  for ($i=0; $i<$n; $i++) {
    $x = ($n === 1) ? $x0 : ($x0 + ($x1-$x0) * ($i/($n-1)));
    $v = (float)$vals[$i];
    $t = ($v - $min) / ($max - $min);
    $y = $y1 - ($y1-$y0) * $t;
    $pts[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
  }
  return implode(' ', $pts);
}

// optional search: filter tree to only paths that match notes/titles
$q = trim((string)($_GET['q'] ?? ''));
$byParent = $byParentAll;
$byId = $byIdAll;
if ($q !== '') {
  $needle = '%' . $q . '%';
  $st = $pdo->prepare('SELECT DISTINCT node_id FROM node_notes WHERE note LIKE ?');
  $st->execute([$needle]);
  $matchIds = array_map(fn($r) => (int)$r['node_id'], $st->fetchAll());

  $st = $pdo->prepare('SELECT id FROM nodes WHERE title LIKE ? OR description LIKE ?');
  $st->execute([$needle, $needle]);
  foreach ($st->fetchAll() as $r) $matchIds[] = (int)$r['id'];

  $matchIds = array_values(array_unique($matchIds));

  $show = [];
  foreach ($matchIds as $mid) {
    $cur = $mid;
    for ($i=0; $i<50; $i++) {
      if (!isset($byIdAll[$cur])) break;
      $show[$cur] = true;
      $p = $byIdAll[$cur]['parent_id'];
      if ($p === null) break;
      $cur = (int)$p;
    }
  }

  $byParent = [];
  $byId = [];
  foreach ($show as $id => $_) {
    $n = $byIdAll[$id] ?? null;
    if (!$n) continue;
    $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
    $byParent[$pid][] = $n;
    $byId[$id] = $n;
  }
}

// roots (top-level containers)
$roots = $byParent[0] ?? [];
// default: no selection on load (keeps tree collapsed)

// compute open-path (ancestors of current)
$open = [];
$cur = $nodeId;
while ($cur && isset($byId[$cur]) && $byId[$cur]['parent_id'] !== null) {
  $pid = (int)$byId[$cur]['parent_id'];
  $open[$pid] = true;
  $cur = $pid;
}

// numbering map for breadcrumb + display
$numMap = buildNumMap($byParent, 0, []);

function buildNumMap(array $byParent, int $parentId=0, array $prefix=[]): array {
  $out = [];
  if (empty($byParent[$parentId])) return $out;
  $i = 0;
  foreach ($byParent[$parentId] as $n) {
    $i++;
    $id = (int)$n['id'];
    $parts = array_merge($prefix, [$i]);
    $out[$id] = implode('.', $parts) . '.';
    $out += buildNumMap($byParent, $id, $parts);
  }
  return $out;
}

function subtreeCounts(array $byParent, array $byId, int $id, array &$memo): array {
  if (isset($memo[$id])) return $memo[$id];
  $todo = 0; $done = 0;

  $hasKids = !empty($byParent[$id]);

  // Container roots (Ideen/Projekte/Später/Gelöscht) should not count as tasks.
  $isRoot = array_key_exists('parent_id', $byId[$id] ?? []) && ($byId[$id]['parent_id'] === null);
  $title = (string)($byId[$id]['title'] ?? '');
  $isContainerRoot = $isRoot && in_array($title, ['Ideen','Projekte','Später','Gelöscht'], true);

  // include self only if it's a leaf AND not a container root
  if (!$hasKids && !$isContainerRoot) {
    $st = (string)($byId[$id]['worker_status'] ?? '');
    if ($st === 'todo_james') $todo++;
    if ($st === 'todo_oliver') $todo++;
    if ($st === 'done') $done++;
  }

  if (!empty($byParent[$id])) {
    foreach ($byParent[$id] as $c) {
      $cid = (int)$c['id'];
      [$ct, $cd] = subtreeCounts($byParent, $byId, $cid, $memo);
      $todo += $ct; $done += $cd;
    }
  }

  return $memo[$id] = [$todo, $done];
}

function renderTree(array $byParent, array $byId, array $sectionByIdAll, array $open, array $attCountById, int $currentId, int $parentId=0, int $depth=0, array $prefix=[], array &$countsMemo=[]): void {
  if (empty($byParent[$parentId])) return;

  $i = 0;
  foreach ($byParent[$parentId] as $n) {
    $i++;
    $id = (int)$n['id'];
    $title = $n['title'];
    $hasKids = !empty($byParent[$id]);
    $isActive = ($id === (int)$currentId);

    $indent = $depth * 5;
    $numParts = array_merge($prefix, [$i]);
    $num = implode('.', $numParts) . '.';

    // show total number of direct children in parentheses
    $directCount = !empty($byParent[$id]) ? count($byParent[$id]) : 0;
    $countTxt = $hasKids ? ' (' . $directCount . ')' : '';

    // right tag: subtree totals (descendants). If leaf: counts reflect own status.
    // subtree totals by assignee
    $todoJames = 0; $todoOliver = 0; $done = 0;
    $stack = [$id];
    while ($stack) {
      $curId = array_pop($stack);

      // Count self (non-container)
      $isRootNode = (($byId[$curId]['parent_id'] ?? null) === null);
      $tTitle = (string)($byId[$curId]['title'] ?? '');
      $isContainerRootNode = $isRootNode && in_array($tTitle, ['Ideen','Projekte','Später','Gelöscht'], true);
      if (!$isContainerRootNode) {
        $stSelf = (string)($byId[$curId]['worker_status'] ?? '');
        if ($stSelf === 'todo_james') $todoJames++;
        if ($stSelf === 'todo_oliver') $todoOliver++;
        if ($stSelf === 'done') $done++;
      }

      // Continue traversal
      $kids = $byParent[$curId] ?? [];
      if ($kids) {
        foreach ($kids as $cc) $stack[] = (int)$cc['id'];
      }
    }
    $parts = [];
    if ($todoJames > 0) $parts[] = 'James: ' . $todoJames;
    if ($todoOliver > 0) $parts[] = 'Oliver: ' . $todoOliver;
    // Intentionally hide Done-count in tree tags (reduces noise)
    $statusText = $parts ? implode(' | ', $parts) : '';

    $shade = max(0, min(4, $depth));
    $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

    $sec = (string)($sectionByIdAll[$id] ?? '');
    $msClass = '';
    if ($sec === 'Gelöscht') $msClass = ' ms-canceled';
    if ($sec === 'Später') $msClass = ' ms-later';

    $hasAtt = !empty($attCountById[$id]);
    $attIcon = $hasAtt ? ' <span class="att-clip" title="Attachment" style="margin-left:4px;">📁</span>' : '';

    if ($hasKids) {
      $forceOpenAll = (!empty($_GET['open']) && $_GET['open'] === 'all') || !empty($_GET['q']);
      // Default: collapsed. Open only when forced (search/open-all) or when the node is active / on the open-path to the current selection.
      $isOpen = $forceOpenAll || $isActive || !empty($open[$id]);

      // Important: apply indentation to the clickable row, not the <details> wrapper.
      // Otherwise browser default <details> layout can add extra indentation when fully expanded (search).
      echo '<details class="tree-branch" ' . ($isOpen ? 'open' : '') . '>';
      echo '<summary class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '" style="margin-left:' . $indent . 'px">';
      $v = (string)($_GET['view'] ?? ($_COOKIE['coos_view'] ?? 'work'));
      if (!in_array($v, ['work','kanban','report'], true)) $v = 'work';
      $base = ($v === 'kanban') ? '/kanban.php' : (($v === 'report') ? '/report.php' : '/');
      $href = $base . '?id=' . $id . '&view=' . rawurlencode($v);
      // Under "Ideen": clicking should jump to "Neues Projekt" prefilled from this node.
      // (but NOT for the Ideen container root itself)
      if ($sec === 'Ideen' && (($byId[$id]['parent_id'] ?? null) !== null)) {
        $href = '/new_project.php?from_node=' . $id;
      }
      echo '<a href="' . h($href) . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . $attIcon . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</summary>';
      renderTree($byParent, $byId, $sectionByIdAll, $open, $attCountById, $currentId, $id, $depth+1, $numParts, $countsMemo);
      echo '</details>';
    } else {
      echo '<div class="tree-leaf">';
      echo '<div class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '" style="margin-left:' . $indent . 'px">';
      $v = (string)($_GET['view'] ?? ($_COOKIE['coos_view'] ?? 'work'));
      if (!in_array($v, ['work','kanban','report'], true)) $v = 'work';
      $base = ($v === 'kanban') ? '/kanban.php' : (($v === 'report') ? '/report.php' : '/');
      $href = $base . '?id=' . $id . '&view=' . rawurlencode($v);
      // Under "Ideen": clicking should jump to "Neues Projekt" prefilled from this node.
      // (but NOT for the Ideen container root itself)
      if ($sec === 'Ideen' && (($byId[$id]['parent_id'] ?? null) !== null)) {
        $href = '/new_project.php?from_node=' . $id;
      }
      echo '<a href="' . h($href) . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . $attIcon . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</div></div>';
    }
  }
}

$node = null;
$children = [];
$notes = [];
$attachments = [];

if ($nodeId) {
  $st = $pdo->prepare('SELECT * FROM nodes WHERE id=?');
  $st->execute([$nodeId]);
  $node = $st->fetch();

  // children list removed from UI; keep query minimal for potential future use
  $st = $pdo->prepare('SELECT id,title,worker_status FROM nodes WHERE parent_id=? ORDER BY id');
  $st->execute([$nodeId]);
  $children = $st->fetchAll();

  // attachments (created by James, served via token URL)
  try {
    $st = $pdo->prepare('SELECT id, token, orig_name, stored_name, mime, size_bytes, created_at, created_by FROM node_attachments WHERE node_id=? ORDER BY created_at DESC, id DESC');
    $st->execute([$nodeId]);
    $attachments = $st->fetchAll();
  } catch (Throwable $e) {
    $attachments = [];
  }

  // legacy notes no longer rendered (we use nodes.description as the editable task text)
  $notes = [];
}

renderHeader('Dashboard');
?>

<div class="grid">
  <div class="card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <h2 style="margin:0;">Projektansicht</h2>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <?php
          $baseQs = [];
          if ($nodeId) $baseQs['id'] = (int)$nodeId;
          if (!empty($_GET['open'])) $baseQs['open'] = (string)$_GET['open'];
          if (!empty($_GET['q'])) $baseQs['q'] = (string)$_GET['q'];

          $hrefWork   = '/?' . http_build_query(array_merge($baseQs, ['view'=>'work']));
          $hrefKanban = '/kanban.php' . ($baseQs ? ('?' . http_build_query($baseQs)) : '');
          $hrefReport = '/report.php' . ($baseQs ? ('?' . http_build_query($baseQs)) : '');
        ?>
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
      <?php renderTree($byParent, $byId, $sectionByIdAll, $open, $attCountById, (int)$nodeId, 0, 0); ?>
    </div>

    <?php require __DIR__ . '/progress_chart.inc.php'; ?>

    <?php if (!empty($doneProjects)): ?>
      <div class="note" style="margin-top:12px">
        <div class="head">Done Projekte</div>
        <ul style="margin:0; padding-left:18px; font-size:12px;">
          <?php foreach ($doneProjects as $dp): ?>
            <?php
              $slug = (string)($dp['slug'] ?? '');
              $title = (string)($dp['title'] ?? '');
              if ($slug === '') continue;
              $url = 'https://t.coos.eu/' . rawurlencode($slug) . '/';
            ?>
            <?php $tsDone = strtotime((string)($dp['updated_at'] ?? '')); ?>
            <?php $doneTxt = $tsDone ? date('d.m.Y H:i:s', $tsDone) : ''; ?>
            <li>
              <a href="<?php echo h($url); ?>" target="_blank" rel="noopener"><?php echo h($title); ?></a>
              <?php if ($doneTxt !== ''): ?>
                <span class="meta">(Fertig seit: <?php echo h($doneTxt); ?>)</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <div>

    <?php if ($view === 'live'): ?>
      <?php
        // --- LIVE VIEW (Graph) lives in the right column (replaces node details / Kanban)
        $projectsRootId2 = 0;
        foreach ($roots as $r) {
          if (((string)($r['title'] ?? '')) === 'Projekte') $projectsRootId2 = (int)$r['id'];
        }

        $isUnder2 = function(int $nodeId2, int $rootId2) use ($byIdAll): bool {
          if (!$rootId2) return false;
          $cur2 = $nodeId2;
          for ($i=0; $i<120; $i++) {
            $row2 = $byIdAll[$cur2] ?? null;
            if (!$row2) return false;
            $pid2 = $row2['parent_id'];
            if ($pid2 === null) return false;
            $pid2 = (int)$pid2;
            if ($pid2 === $rootId2) return true;
            $cur2 = $pid2;
          }
          return false;
        };

        // Find active project (top-level child of Projekte root) for current selection
        $activeProjectId = 0;
        if ($projectsRootId2 && $nodeId > 0 && !empty($byIdAll[$nodeId]) && $isUnder2($nodeId, $projectsRootId2)) {
          $cur = (int)$nodeId;
          for ($i=0; $i<160; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) break;
            $pid = $row['parent_id'];
            if ($pid === null) break;
            $pid = (int)$pid;
            if ($pid === $projectsRootId2) { $activeProjectId = $cur; break; }
            $cur = $pid;
          }
        }
        $activeProjectTitle = $activeProjectId ? (string)($byIdAll[$activeProjectId]['title'] ?? '') : '';

        // blocked detection (match selector semantics)
        $isBlockedNode = function(int $id) use ($byIdAll): bool {
          $n = $byIdAll[$id] ?? null;
          if (!$n) return true;
          $bu = (string)($n['blocked_until'] ?? '');
          $bb = (int)($n['blocked_by_node_id'] ?? 0);
          if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;
          if ($bb > 0) {
            $bn = $byIdAll[$bb] ?? null;
            if (!$bn) return true;
            return (string)($bn['worker_status'] ?? '') !== 'done';
          }
          return false;
        };

        // Build graph data for current project
        $graph = ['nodes'=>[], 'edges'=>[]];
        if ($activeProjectId > 0) {
          $inProj = [];
          $stack = [$activeProjectId];
          while ($stack) {
            $cur = array_pop($stack);
            $inProj[$cur] = true;
            foreach (($byParentAll[$cur] ?? []) as $ch) {
              $cid = (int)$ch['id'];
              $stack[] = $cid;
            }
          }

          foreach ($inProj as $id3 => $_) {
            $n3 = $byIdAll[(int)$id3] ?? null;
            if (!$n3) continue;

            $ws = (string)($n3['worker_status'] ?? '');
            $status = $ws;
            if ($ws !== 'done' && $isBlockedNode((int)$id3)) $status = 'blocked';

            $graph['nodes'][] = [
              'data' => [
                'id' => 'n' . (int)$id3,
                'node_id' => (int)$id3,
                'label' => '#' . (int)$id3 . ' ' . (string)($n3['title'] ?? ''),
                'title' => (string)($n3['title'] ?? ''),
                'status' => $status,
                'worker_status' => $ws,
              ]
            ];

            $pid = $n3['parent_id'] === null ? 0 : (int)$n3['parent_id'];
            if ($pid > 0 && isset($inProj[$pid])) {
              $graph['edges'][] = [
                'data' => [
                  'id' => 'e' . $pid . '_' . (int)$id3,
                  'source' => 'n' . $pid,
                  'target' => 'n' . (int)$id3,
                  'type' => 'tree',
                ]
              ];
            }

            $bb = (int)($n3['blocked_by_node_id'] ?? 0);
            if ($bb > 0 && isset($inProj[$bb])) {
              $graph['edges'][] = [
                'data' => [
                  'id' => 'b' . (int)$id3 . '_' . $bb,
                  'source' => 'n' . $bb,
                  'target' => 'n' . (int)$id3,
                  'type' => 'blocked_by',
                ]
              ];
            }
          }
        }
      ?>

      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; gap:12px;">
          <div>
            <h2 style="margin:0 0 6px 0;">Test: Projekte-Graph<?php if ($activeProjectTitle !== ''): ?> · <?php echo h($activeProjectTitle); ?><?php endif; ?></h2>
            <div class="meta">Klick = Fokus · Doppelklick = öffnet Node · Filter/Zoom/Highlight. (Experimental)</div>
          </div>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
            <input id="gSearch" type="text" placeholder="#id oder Text…" style="width:220px;">
            <label class="pill" style="display:flex; gap:6px; align-items:center;"><input id="gShowDone" type="checkbox" checked> Done</label>
            <label class="pill" style="display:flex; gap:6px; align-items:center;"><input id="gShowBlockedEdges" type="checkbox" checked> Blocker-Kanten</label>
            <a class="btn btn-md" href="#" id="gFit">Fit</a>
            <a class="btn btn-md" href="#" id="gLayout">Layout</a>
          </div>
        </div>

        <div style="height:12px"></div>
        <div id="cy" style="height: calc(100vh - 220px); min-height:560px; border-radius:14px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08);"></div>

        <div style="height:10px"></div>
        <div class="meta" id="gHint"></div>
      </div>

      <link rel="stylesheet" href="https://unpkg.com/cytoscape-panzoom/cytoscape.js-panzoom.css">
      <script src="https://unpkg.com/cytoscape@3.26.0/dist/cytoscape.min.js"></script>
      <script src="https://unpkg.com/dagre@0.8.5/dist/dagre.min.js"></script>
      <script src="https://unpkg.com/cytoscape-dagre@2.5.0/cytoscape-dagre.js"></script>
      <script src="https://unpkg.com/cytoscape-panzoom/cytoscape-panzoom.js"></script>

      <script>
        (function(){
          const elements = <?php echo json_encode(array_merge($graph['nodes'], $graph['edges']), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
          const selectedNodeId = <?php echo (int)$nodeId; ?>;
          const activeProjectId = <?php echo (int)$activeProjectId; ?>;

          function colorFor(status){
            if(status==='todo_james') return '#b28cff';
            if(status==='todo_oliver') return '#78c8ff';
            if(status==='done') return '#ffd580';
            if(status==='blocked') return 'rgba(255,120,120,0.95)';
            return '#9aa0a6';
          }

          if (!elements || elements.length === 0) {
            const hint = document.getElementById('gHint');
            if (hint) hint.textContent = 'Kein Projekt ausgewählt. Bitte links einen Node unter "Projekte" anklicken.';
            return;
          }

          const cy = window.cy = cytoscape({
            container: document.getElementById('cy'),
            elements,
            wheelSensitivity: 0.20,
            style: [
              { selector:'node', style:{
                'background-color': ele => colorFor(ele.data('status')),
                'label':'data(label)',
                'color':'rgba(255,255,255,0.92)',
                'text-outline-color':'rgba(0,0,0,0.55)',
                'text-outline-width':2,
                'font-size':11,
                'text-wrap':'wrap',
                'text-max-width':140,
                'width':26,
                'height':26,
                'border-width':1,
                'border-color':'rgba(255,255,255,0.15)'
              }},
              { selector:'node[status="done"]', style:{ 'opacity':0.55 }},
              { selector:'edge[type="tree"]', style:{ 'width':1.4, 'line-color':'rgba(255,255,255,0.14)', 'curve-style':'bezier', 'target-arrow-shape':'none' }},
              { selector:'edge[type="blocked_by"]', style:{ 'width':1.6, 'line-color':'rgba(255,120,120,0.65)', 'line-style':'dashed', 'target-arrow-shape':'triangle', 'target-arrow-color':'rgba(255,120,120,0.75)', 'curve-style':'bezier' }},
              { selector:'.hidden', style:{ 'display':'none' }},
              { selector:'.faded', style:{ 'opacity':0.12 }},
              { selector:'.focused', style:{ 'border-width':3, 'border-color':'rgba(255,255,255,0.65)', 'width':30, 'height':30 }}
            ],
            layout: { name:'dagre', rankDir:'TB', nodeSep:18, rankSep:55, edgeSep:10, animate:true, animationDuration:280 }
          });

          try { cy.panzoom({}); } catch(e) {}

          function applyFilters(){
            const showDone = document.getElementById('gShowDone').checked;
            const showBlockedEdges = document.getElementById('gShowBlockedEdges').checked;
            cy.nodes().forEach(n => {
              const isDone = (n.data('status') === 'done');
              n.toggleClass('hidden', isDone && !showDone);
            });
            cy.edges('[type="blocked_by"]').toggleClass('hidden', !showBlockedEdges);
          }

          function focusNode(n){
            cy.elements().removeClass('focused');
            cy.elements().addClass('faded');
            n.removeClass('faded');
            n.addClass('focused');
            n.closedNeighborhood().removeClass('faded');
            cy.animate({ fit: { eles: n.closedNeighborhood(), padding: 60 } }, { duration: 260 });
            const hint = document.getElementById('gHint');
            if (hint) hint.textContent = `Fokus: #${n.data('node_id')} · ${n.data('title')} · status=${n.data('status')}`;
          }

          cy.on('tap', 'node', (evt) => focusNode(evt.target));
          cy.on('dbltap', 'node', (evt) => {
            const nid = evt.target.data('node_id');
            const qs = new URLSearchParams(window.location.search);
            qs.set('id', nid);
            qs.set('view', 'test');
            window.location.href = '/?' + qs.toString();
          });

          document.getElementById('gFit').addEventListener('click', (e)=>{ e.preventDefault(); cy.animate({ fit: { eles: cy.elements(':visible'), padding: 50 } }, { duration: 240 }); });
          document.getElementById('gLayout').addEventListener('click', (e)=>{ e.preventDefault(); cy.layout({ name:'dagre', rankDir:'TB', nodeSep:18, rankSep:55, edgeSep:10, animate:true, animationDuration:260 }).run(); });
          document.getElementById('gShowDone').addEventListener('change', applyFilters);
          document.getElementById('gShowBlockedEdges').addEventListener('change', applyFilters);

          const searchEl = document.getElementById('gSearch');
          let searchT = null;
          function doSearch(){
            const q = (searchEl.value || '').trim().toLowerCase();
            if (!q){
              cy.elements().removeClass('faded');
              document.getElementById('gHint').textContent = '';
              return;
            }
            const hit = cy.nodes().filter(n => {
              const id = String(n.data('node_id'));
              const label = String(n.data('label')||'').toLowerCase();
              return ('#'+id) === q || id === q || label.includes(q);
            })[0];
            if (hit) focusNode(hit);
          }
          searchEl.addEventListener('input', ()=>{ clearTimeout(searchT); searchT=setTimeout(doSearch, 160); });

          applyFilters();

          // Live updates (poll)
          let pollT = null;
          async function poll(){
            try {
              const u = new URL('/api_project_graph.php', window.location.origin);
              u.searchParams.set('id', String(selectedNodeId || 0));
              const res = await fetch(u.toString(), {credentials:'same-origin'});
              const j = await res.json();
              if (j && j.ok && Array.isArray(j.elements)) {
                // reset elements (simple & robust; animated layout makes it feel alive)
                cy.elements().remove();
                cy.add(j.elements);
                applyFilters();
                cy.layout({ name:'dagre', rankDir:'TB', nodeSep:18, rankSep:55, edgeSep:10, animate:true, animationDuration:220 }).run();
              }
            } catch(e) {
              // ignore
            }
            pollT = setTimeout(poll, 4000);
          }
          pollT = setTimeout(poll, 4000);

          const root = (activeProjectId ? cy.getElementById('n'+activeProjectId) : null);
          if (root && root.length) {
            focusNode(root);
            if (selectedNodeId) {
              const n = cy.getElementById('n'+selectedNodeId);
              if (n && n.length) setTimeout(()=>focusNode(n), 180);
            }
          } else {
            cy.fit(cy.elements(':visible'), 50);
          }
        })();
      </script>

    <?php elseif ($view === 'kanban'): ?>
      <?php
        // Kanban view (leafs under Projekte) – restore richer cards (project name + time since done)
        $projectsRootIdK = 0;
        foreach ($roots as $r) { if (((string)($r['title'] ?? '')) === 'Projekte') $projectsRootIdK = (int)$r['id']; }

        $isUnderK = function(int $nid, int $rootId) use ($byIdAll): bool {
          if ($nid <= 0 || $rootId <= 0) return false;
          $cur = $nid;
          for ($i=0; $i<120; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return false;
            $pid = $row['parent_id'];
            if ($pid === null) return false;
            $pid = (int)$pid;
            if ($pid === $rootId) return true;
            $cur = $pid;
          }
          return false;
        };

        $topProjectTitleK = function(int $nodeId) use ($byIdAll, $projectsRootIdK): string {
          if ($nodeId <= 0 || !$projectsRootIdK) return '';
          $cur = $nodeId;
          for ($i=0; $i<140; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return '';
            $pid = $row['parent_id'];
            if ($pid === null) return '';
            $pid = (int)$pid;
            if ($pid === $projectsRootIdK) return (string)($row['title'] ?? '');
            $cur = $pid;
          }
          return '';
        };

        $isBlockedK = function(int $id) use ($byIdAll): bool {
          $n = $byIdAll[$id] ?? null;
          if (!$n) return true;
          $bu = (string)($n['blocked_until'] ?? '');
          $bb = (int)($n['blocked_by_node_id'] ?? 0);
          if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;
          if ($bb > 0) {
            $bn = $byIdAll[$bb] ?? null;
            if (!$bn) return true;
            return (string)($bn['worker_status'] ?? '') !== 'done';
          }
          return false;
        };

        $cols = ['todo_oliver'=>[], 'todo_james'=>[], 'blocked'=>[], 'done'=>[]];
        // Optional filter: if an id is selected, show only tasks that are that node or under it.
        $filterRootId = ($nodeId > 0) ? (int)$nodeId : 0;

        $isUnderFilter = function(int $nid, int $rootId) use ($byIdAll): bool {
          if ($rootId <= 0) return true;
          if ($nid === $rootId) return true;
          $cur = $nid;
          for ($i=0; $i<200; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return false;
            $pid = $row['parent_id'];
            if ($pid === null) return false;
            $pid = (int)$pid;
            if ($pid === $rootId) return true;
            $cur = $pid;
          }
          return false;
        };

        foreach ($byIdAll as $id => $n) {
          $id = (int)$id;
          if (!$projectsRootIdK || !$isUnderK($id, $projectsRootIdK)) continue;
          if (!$isUnderFilter($id, $filterRootId)) continue;
          if (!empty($byParentAll[$id] ?? [])) continue; // leafs only

          $ws = (string)($n['worker_status'] ?? '');
          $key = $ws;
          if ($ws !== 'done' && $isBlockedK($id)) $key = 'blocked';
          if (!isset($cols[$key])) continue;

          $cols[$key][] = [
            'id' => $id,
            'title' => (string)($n['title'] ?? ''),
            'project' => $topProjectTitleK($id),
            'updated_at' => (string)($n['updated_at'] ?? ''),
            'has_att' => !empty($attCountById[$id] ?? 0),
          ];
        }
        foreach ($cols as $k => $arr) {
          usort($arr, function($a,$b){
            $ta = (string)($a['updated_at'] ?? '');
            $tb = (string)($b['updated_at'] ?? '');
            if ($ta === $tb) return ((int)($b['id']??0) <=> (int)($a['id']??0));
            return strcmp($tb, $ta);
          });
          $cols[$k] = $arr;
        }
        $colTitle = ['todo_oliver'=>'ToDo (Oliver)','todo_james'=>'ToDo (James)','blocked'=>'BLOCKED','done'=>'Done'];
      ?>

      <?php
        // Depth bars (like the old Kanban): scale against deepest node under Projekte
        $depthUnderRootK = function(int $nodeId, int $rootId) use ($byIdAll): int {
          if ($nodeId <= 0 || $rootId <= 0) return 0;
          $d = 0;
          $cur = $nodeId;
          for ($i=0; $i<160; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return 0;
            $pid = $row['parent_id'];
            if ($pid === null) return 0;
            $pid = (int)$pid;
            $d++;
            if ($pid === $rootId) return $d;
            $cur = $pid;
          }
          return 0;
        };

        $maxDepthProjects = 0;
        if ($projectsRootIdK > 0) {
          foreach ($byIdAll as $id2 => $n2) {
            $id2 = (int)$id2;
            if (!$isUnderK($id2, $projectsRootIdK)) continue;
            $dd = $depthUnderRootK($id2, $projectsRootIdK);
            if ($dd > $maxDepthProjects) $maxDepthProjects = $dd;
          }
        }
        if ($maxDepthProjects <= 0) $maxDepthProjects = 1;
      ?>

      <div class="card">
        <h2 style="margin:0 0 10px 0;">Kanban (Leafs: Projekte)</h2>
        <div class="kanban">
          <?php foreach (['todo_oliver','todo_james','blocked','done'] as $col): ?>
            <div class="kanban-col">
              <h3>
                <span><?php echo h($colTitle[$col]); ?></span>
                <span class="pill dim"><?php echo count($cols[$col]); ?></span>
              </h3>

              <?php
                $top = $cols[$col][0] ?? null;
                $topId = $top ? (int)$top['id'] : 0;
                $curDepth = ($topId && $projectsRootIdK) ? $depthUnderRootK($topId, $projectsRootIdK) : 0;
                $pct = ($maxDepthProjects > 0 && $curDepth > 0) ? max(0, min(100, (int)round(($curDepth / $maxDepthProjects) * 100))) : 0;
                $tt = $topId ? ('Top-Task: Tiefe ' . $curDepth . ' / ' . $maxDepthProjects . ' (#' . $topId . ')') : '—';
              ?>

              <?php if ($pct > 0): ?>
                <div title="<?php echo h($tt); ?>" style="position:relative; height:8px; border-radius:999px; background:linear-gradient(90deg, rgba(120,255,170,0.9), rgba(255,200,90,0.9), rgba(255,120,120,0.95)); overflow:hidden; margin:6px 0 10px 0;">
                  <div style="position:absolute; top:0; right:0; height:100%; width:<?php echo (int)(100-$pct); ?>%; background:rgba(0,0,0,0.80);"></div>
                </div>
              <?php else: ?>
                <div title="<?php echo h($tt); ?>" style="position:relative; height:8px; border-radius:999px; background:linear-gradient(90deg, rgba(120,255,170,0.9), rgba(255,200,90,0.9), rgba(255,120,120,0.95)); overflow:hidden; margin:6px 0 10px 0;">
                  <div style="position:absolute; top:0; right:0; height:100%; width:100%; background:rgba(0,0,0,0.88);"></div>
                </div>
              <?php endif; ?>

              <?php if (empty($cols[$col])): ?>
                <div class="meta" style="padding:8px 2px;">—</div>
              <?php else: ?>
                <?php foreach (array_slice($cols[$col], 0, 40) as $c): ?>
                  <a class="kanban-card" href="/?id=<?php echo (int)$c['id']; ?>">
                    <div class="kanban-title"><?php echo h($c['title']); ?><?php if (!empty($c['has_att'])): ?> <span class="att-clip" title="Attachment">📁</span><?php endif; ?></div>
                    <div class="kanban-meta">
                      <span class="pill section"><?php echo h((string)($c['project'] ?? '')); ?></span>
                      <?php
                        $right = '#' . (int)$c['id'];
                        if ($col === 'done') {
                          $ts = strtotime((string)($c['updated_at'] ?? ''));
                          if ($ts) {
                            $delta = time() - $ts;
                            if ($delta < 0) $delta = 0;
                            if ($delta < 60) {
                              $right = 'vor ' . $delta . 's · ' . $right;
                            } elseif ($delta < 90*60) {
                              $mins = (int)round($delta / 60);
                              $right = 'vor ' . $mins . ' Min. · ' . $right;
                            } elseif ($delta < 48*3600) {
                              $hrs = $delta / 3600;
                              $hrsTxt = ($hrs < 10) ? number_format($hrs, 1, ',', '') : (string)round($hrs);
                              $right = 'vor ' . $hrsTxt . 'h · ' . $right;
                            } else {
                              $days = (int)round($delta / 86400);
                              $right = 'vor ' . $days . 'd · ' . $right;
                            }
                          }
                        }
                      ?>
                      <span class="pill dim"><?php echo h($right); ?></span>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php elseif ($view === 'report'): ?>
      <div class="card">
        <h2 style="margin:0 0 10px 0;">Report</h2>
        <div class="meta">Platzhalter: hier können wir als nächstes Projekt-Reportings/Charts/Time/Token pro Projekt reinpacken.</div>
      </div>

    <?php elseif ($view === 'work' && !$node): ?>
      <?php
        // Welcome dashboard for Work view (no selection)
        $last = null;
        if (!empty($metricsRows)) $last = $metricsRows[count($metricsRows)-1];
        $p = $last ? [
          'todo_james' => (int)($last['projects_todo_james'] ?? 0),
          'todo_oliver' => (int)($last['projects_todo_oliver'] ?? 0),
          'blocked' => (int)($last['projects_blocked'] ?? 0),
          'done' => (int)($last['projects_done'] ?? 0),
        ] : null;
        $i = $last ? [
          'todo_james' => (int)($last['ideas_todo_james'] ?? 0),
          'todo_oliver' => (int)($last['ideas_todo_oliver'] ?? 0),
          'blocked' => (int)($last['ideas_blocked'] ?? 0),
          'done' => (int)($last['ideas_done'] ?? 0),
        ] : null;
        $lastTs = $last ? strtotime((string)($last['ts'] ?? '')) : 0;
      ?>

      <div class="card">
        <h2 style="margin:0 0 8px 0;">Willkommen, Oliver</h2>
        <div class="meta">Work-Ansicht · wähle links ein Projekt oder eine Idee, um Details zu sehen.</div>

        <?php if ($lastTs): ?>
          <div class="meta" style="margin-top:6px;">Letztes Metrics-Update: <?php echo h(date('d.m.Y H:i', $lastTs)); ?></div>
        <?php endif; ?>

        <div style="height:14px"></div>

        <div class="row" style="gap:12px; align-items:stretch;">
          <div class="card" style="flex:1; background:rgba(5,7,11,.22);">
            <h3 style="margin:0 0 10px 0; font-size:13px; color:rgba(242,217,138,.95);">Projekte</h3>
            <?php if ($p): ?>
              <div class="row" style="gap:8px;">
                <span class="pill">ToDo (J): <?php echo (int)$p['todo_james']; ?></span>
                <span class="pill">ToDo (O): <?php echo (int)$p['todo_oliver']; ?></span>
                <span class="pill">Blocked: <?php echo (int)$p['blocked']; ?></span>
                <span class="pill">Done: <?php echo (int)$p['done']; ?></span>
              </div>
            <?php else: ?>
              <div class="meta">(Keine Metrics)</div>
            <?php endif; ?>
            <div style="height:10px"></div>
            <div class="meta">Tipp: Klick auf ein Projekt links → dann siehst du Status/Env/Artefakte.</div>
          </div>

          <div class="card" style="flex:1; background:rgba(5,7,11,.22);">
            <h3 style="margin:0 0 10px 0; font-size:13px; color:rgba(242,217,138,.95);">Ideen</h3>
            <?php if ($i): ?>
              <div class="row" style="gap:8px;">
                <span class="pill">ToDo (J): <?php echo (int)$i['todo_james']; ?></span>
                <span class="pill">ToDo (O): <?php echo (int)$i['todo_oliver']; ?></span>
                <span class="pill">Blocked: <?php echo (int)$i['blocked']; ?></span>
                <span class="pill">Done: <?php echo (int)$i['done']; ?></span>
              </div>
            <?php else: ?>
              <div class="meta">(Keine Metrics)</div>
            <?php endif; ?>
            <div style="height:10px"></div>
            <div class="meta">Tipp: Ideen kannst du links direkt zu Projekten machen (Neues Projekt).</div>
          </div>
        </div>

        <div style="height:14px"></div>

        <div class="row" style="gap:10px;">
          <a class="btn btn-gold" href="/new_project.php">Neues Projekt anlegen</a>
          <a class="btn" href="/report.php">Zum Report</a>
          <a class="btn" href="/kanban.php">Zum Kanban</a>
        </div>

        <?php
          // Last updated nodes (Projekte subtree only)
          $projectsRootId = 0;
          foreach ($roots as $r) {
            if (((string)($r['title'] ?? '')) === 'Projekte') $projectsRootId = (int)$r['id'];
          }

          $isUnder = function(int $nodeId2, int $rootId2) use ($byIdAll): bool {
            if ($nodeId2 <= 0 || $rootId2 <= 0) return false;
            $cur = $nodeId2;
            for ($i=0; $i<160; $i++) {
              $row = $byIdAll[$cur] ?? null;
              if (!$row) return false;
              $pid = $row['parent_id'];
              if ($pid === null) return false;
              $pid = (int)$pid;
              if ($pid === $rootId2) return true;
              $cur = $pid;
            }
            return false;
          };

          $topProjectTitle = function(int $nodeId2) use ($byIdAll, $projectsRootId): string {
            if ($nodeId2 <= 0 || !$projectsRootId) return '';
            $cur = $nodeId2;
            for ($i=0; $i<160; $i++) {
              $row = $byIdAll[$cur] ?? null;
              if (!$row) return '';
              $pid = $row['parent_id'];
              if ($pid === null) return '';
              $pid = (int)$pid;
              if ($pid === $projectsRootId) return (string)($row['title'] ?? '');
              $cur = $pid;
            }
            return '';
          };

          $recent = [];
          foreach ($byIdAll as $id2 => $n2) {
            $id2 = (int)$id2;
            if (!$projectsRootId || !$isUnder($id2, $projectsRootId)) continue;
            $u = (string)($n2['updated_at'] ?? '');
            if ($u === '') continue;
            $recent[] = [
              'id' => $id2,
              'title' => (string)($n2['title'] ?? ''),
              'worker_status' => (string)($n2['worker_status'] ?? ''),
              'updated_at' => $u,
              'project' => $topProjectTitle($id2),
            ];
          }
          usort($recent, function($a,$b){
            $ta = (string)($a['updated_at'] ?? '');
            $tb = (string)($b['updated_at'] ?? '');
            if ($ta === $tb) return ((int)($b['id']??0) <=> (int)($a['id']??0));
            return strcmp($tb, $ta);
          });
          $recent = array_slice($recent, 0, 5);

          $sinceTxt = function(string $ts): string {
            $t = strtotime($ts);
            if (!$t) return '';
            $d = time() - $t;
            if ($d < 0) $d = 0;
            if ($d < 60) return 'vor ' . $d . 's';
            if ($d < 90*60) return 'vor ' . (int)round($d/60) . ' Min.';
            if ($d < 48*3600) {
              $hrs = $d/3600;
              $hrsTxt = ($hrs < 10) ? number_format($hrs, 1, ',', '') : (string)round($hrs);
              return 'vor ' . $hrsTxt . 'h';
            }
            return 'vor ' . (int)round($d/86400) . 'd';
          };

          $wsLabel = function(string $ws): string {
            return match($ws) {
              'todo_james' => 'ToDo (James)',
              'todo_oliver' => 'ToDo (Oliver)',
              'done' => 'Done',
              default => $ws,
            };
          };
        ?>

        <div style="height:16px"></div>
        <div class="card" style="background:rgba(5,7,11,.22);">
          <h3 style="margin:0 0 10px 0; font-size:13px; color:rgba(242,217,138,.95);">Zuletzt bearbeitet (Projekte)</h3>
          <?php if (empty($recent)): ?>
            <div class="meta">—</div>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <?php foreach ($recent as $r): ?>
                <a class="btn" href="/?id=<?php echo (int)$r['id']; ?>&view=work" style="text-decoration:none;">
                  <div class="row" style="justify-content:space-between; align-items:center; gap:10px;">
                    <div>
                      <div style="font-size:13px; line-height:1.25; color:rgba(255,255,255,0.92)">#<?php echo (int)$r['id']; ?> · <?php echo h((string)$r['project']); ?> — <?php echo h((string)$r['title']); ?></div>
                      <div class="meta"><?php echo h($wsLabel((string)$r['worker_status'])); ?> · <?php echo h($sinceTxt((string)$r['updated_at'])); ?></div>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($node): ?>
      <div class="card">
        <?php
          // breadcrumb
          $crumbIds = [];
          $cur = (int)$node['id'];
          while ($cur && isset($byId[$cur])) {
            $crumbIds[] = $cur;
            $p = $byId[$cur]['parent_id'];
            if ($p === null) break;
            $cur = (int)$p;
          }
          $crumbIds = array_reverse($crumbIds);
          $crumbParts = [];
          foreach ($crumbIds as $cid) {
            $nn = $numMap[$cid] ?? '';
            $tt = $byId[$cid]['title'] ?? '';
            $crumbParts[] = trim($nn . ' ' . $tt);
          }
          $crumb = implode(' > ', $crumbParts);
        ?>

        <div class="row" style="justify-content:space-between; align-items:center">
          <h2 style="margin:0;"><?php echo h($crumb); ?></h2>
          <?php
            $work = (string)($node['worker_status'] ?? '');
            $workMap = ['todo_james'=>'ToDo (James)','todo_oliver'=>'ToDo (Oliver)','done'=>'erledigt'];
            $statusLabel = ($workMap[$work] ?? $work);
          ?>
          <span class="tag gold"><?php echo h($statusLabel); ?></span>
        </div>

        <?php
          // Determine section early (needed for metrics in meta line)
          $sec = (string)($sectionByIdAll[(int)$node['id']] ?? '');
          $isInProjekte = ($sec === 'Projekte');

          $createdTs = strtotime((string)($node['created_at'] ?? ''));

          // metrics (optional columns)
          $fmtTok = function(int $n): string {
            $abs = abs($n);
            $sign = $n < 0 ? '-' : '';
            if ($abs >= 1000000) {
              $v = $abs / 1000000;
              $txt = ($v < 10) ? number_format($v, 1, '.', '') : (string)round($v);
              // drop trailing .0
              $txt = preg_replace('/\.0$/', '', $txt);
              return $sign . $txt . 'M';
            }
            if ($abs >= 1000) {
              $v = $abs / 1000;
              $txt = ($v < 10) ? number_format($v, 1, '.', '') : (string)round($v);
              $txt = preg_replace('/\.0$/', '', $txt);
              return $sign . $txt . 'K';
            }
            return (string)$n;
          };

          $tokIn = isset($node['token_in']) ? (int)$node['token_in'] : null;
          $tokOut = isset($node['token_out']) ? (int)$node['token_out'] : null;
          $wt = isset($node['worktime']) ? (int)$node['worktime'] : null;
          $tokAll = ($tokIn !== null && $tokOut !== null) ? ($tokIn + $tokOut) : null;
          $wtTxt = null;
          if ($wt !== null) {
            $h = intdiv($wt, 3600);
            $m = intdiv($wt % 3600, 60);
            $s = $wt % 60;
            $wtTxt = sprintf('%d:%02d:%02d', $h, $m, $s);
          }

          // Subtree sums (current node + all descendants)
          $sumTokIn = null;
          $sumTokOut = null;
          $sumWt = null;
          $sumTokAll = null;
          $sumWtTxt = null;

          if ($isInProjekte && $tokIn !== null && $tokOut !== null && $wt !== null) {
            $sumTokIn = 0;
            $sumTokOut = 0;
            $sumWt = 0;

            $seen = [];
            $stack = [(int)$node['id']];
            while ($stack) {
              $id2 = array_pop($stack);
              if ($id2 <= 0 || isset($seen[$id2])) continue;
              $seen[$id2] = true;

              $n2 = $byIdAll[$id2] ?? null;
              if ($n2) {
                $sumTokIn += (int)($n2['token_in'] ?? 0);
                $sumTokOut += (int)($n2['token_out'] ?? 0);
                $sumWt += (int)($n2['worktime'] ?? 0);
              }

              foreach (($byParent[$id2] ?? []) as $childRow) {
                if (is_array($childRow) && isset($childRow['id'])) {
                  $stack[] = (int)$childRow['id'];
                }
              }
            }

            $sumTokAll = $sumTokIn + $sumTokOut;
            $h2 = intdiv($sumWt, 3600);
            $m2 = intdiv($sumWt % 3600, 60);
            $s2 = $sumWt % 60;
            $sumWtTxt = sprintf('%d:%02d:%02d', $h2, $m2, $s2);
          }
        ?>
        <div class="meta">
          #<?php echo (int)$node['id']; ?> • erstellt von <?php echo h($node['created_by']); ?>
          <?php if ($createdTs): ?> am <?php echo h(date('d.m.Y H:i', $createdTs)); ?><?php endif; ?>

          <?php if ($isInProjekte && $tokIn !== null && $tokOut !== null && $wtTxt !== null): ?>
            <?php
              // For Projekte: show aggregated metrics for the current node + all descendants.
              $showTokIn = ($sumTokIn !== null) ? (int)$sumTokIn : (int)$tokIn;
              $showTokOut = ($sumTokOut !== null) ? (int)$sumTokOut : (int)$tokOut;
              $showTokAll = ($sumTokAll !== null) ? (int)$sumTokAll : (int)$tokAll;
              $showWtTxt = ($sumWtTxt !== null) ? (string)$sumWtTxt : (string)$wtTxt;
            ?>
            <span style="white-space:nowrap;"> | Token in/out/all: <?php echo h($fmtTok((int)$showTokIn)); ?>/<?php echo h($fmtTok((int)$showTokOut)); ?>/<?php echo h($fmtTok((int)$showTokAll)); ?>
              &nbsp; Worktime: <?php echo htmlspecialchars($showWtTxt, ENT_QUOTES, 'UTF-8'); ?>
            </span>
          <?php endif; ?>
        </div>

        <?php
          $blockedUntil = (string)($node['blocked_until'] ?? '');
          $blockedBy = (int)($node['blocked_by_node_id'] ?? 0);
          $isBlockedUntil = ($blockedUntil !== '' && strtotime($blockedUntil) && strtotime($blockedUntil) > time());
          $isBlockedBy = ($blockedBy > 0);
        ?>
        <?php if ($isBlockedUntil || $isBlockedBy): ?>
          <div class="meta" style="margin-top:6px">
            Blockiert
            <?php if ($isBlockedUntil): ?>
              bis <?php echo h(date('d.m.Y H:i:s', strtotime($blockedUntil))); ?>
            <?php endif; ?>
            <?php if ($isBlockedBy): ?>
              <?php if ($isBlockedUntil): ?>·<?php endif; ?> wartet auf <a href="/?id=<?php echo (int)$blockedBy; ?>">#<?php echo (int)$blockedBy; ?></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php
          $ws = (string)($node['worker_status'] ?? '');
          $sec = (string)($sectionByIdAll[(int)$node['id']] ?? '');
          $isInProjekte = ($sec === 'Projekte');
        ?>

        <?php
          // Project env preview (always visible for nodes under Projekte)
          $envText = '';
          if ($isInProjekte) {
            $envText = project_env_text_for_node($pdo, (int)$node['id'], 3500);
          }
        ?>
        <?php if ($envText !== ''): ?>
          <div class="card" style="margin-top:12px; background:rgba(15,22,35,.72);">
            <div class="row" style="justify-content:space-between; align-items:center; gap:10px;">
              <h3 style="margin:0;">Projekt-Umgebung (env.md)</h3>
              <button class="btn btn-sm" type="button" onclick="(function(){var el=document.getElementById('envBox'); if(!el) return; el.style.display = (el.style.display==='none'?'block':'none');})()">ein-/ausblenden</button>
            </div>
            <div class="meta">Dieser Block wird automatisch in jeden James-Task injiziert. Standard: ausgeblendet.</div>
            <div id="envBox" style="display:none; margin-top:10px;">
              <textarea readonly style="min-height:160px; opacity:0.95; font-size:12px; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;"><?php echo h($envText); ?></textarea>
            </div>
          </div>
        <?php endif; ?>

        <?php
          $isRoot = (($byId[(int)$node['id']]['parent_id'] ?? null) === null);
          $isContainerRoot = $isRoot && in_array((string)$node['title'], ['Ideen','Projekte','Später','Gelöscht'], true);
        ?>

        <?php if (!$isContainerRoot): ?>
        <div class="row" style="margin-top:10px; align-items:center">
          <div class="meta">Optionen:</div>

          <?php $u = strtolower((string)($_SESSION['username'] ?? '')); ?>
          <?php if ($u === 'james'): ?>
            <form method="post" action="/actions.php" style="margin:0; display:flex; gap:8px; align-items:center; flex-wrap:wrap">
              <input type="hidden" name="action" value="set_block">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <input type="datetime-local" name="blocked_until" value="" style="width:auto; min-width:210px;">
              <input type="number" name="blocked_by_node_id" placeholder="#Node" value="" style="width:110px;">
              <button class="btn" type="submit">blocken</button>
            </form>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="clear_block">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">unblock</button>
            </form>
          <?php endif; ?>

          <?php if ($sec !== 'Projekte'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_active">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">aktivieren</button>
            </form>

            <?php if ($sec === 'Ideen'): ?>
              <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Wirklich nach „Gelöscht" verschieben? (inkl. Subtasks)');">
                <input type="hidden" name="action" value="remove_recursive">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" type="submit">löschen</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($sec === 'Projekte'): ?>
            <?php if ($ws === 'done'): ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="todo_james" type="submit">an James</button>
              </form>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="todo_oliver" type="submit">an Oliver</button>
              </form>
            <?php elseif ($ws === 'todo_oliver'): ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="todo_james" type="submit">an James</button>
              </form>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="done" type="submit">erledigt</button>
              </form>
            <?php else: /* todo_james */ ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="todo_oliver" type="submit">an Oliver</button>
              </form>
              <form method="post" style="margin:0">
                <input type="hidden" name="action" value="set_worker">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" name="worker_status" value="done" type="submit">erledigt</button>
              </form>
            <?php endif; ?>

            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_later">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">später</button>
            </form>

            <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Wirklich nach „Gelöscht" verschieben? (inkl. Subtasks)');">
              <input type="hidden" name="action" value="remove_recursive">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">löschen</button>
            </form>

            <?php if ($isBlockedUntil): ?>
              <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Time-Block wirklich entfernen?');">
                <input type="hidden" name="action" value="clear_block">
                <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
                <button class="btn" type="submit">Blocker entfernen</button>
              </form>
            <?php endif; ?>
          <?php elseif ($sec === 'Später'): ?>
            <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Wirklich nach „Gelöscht" verschieben? (inkl. Subtasks)');">
              <input type="hidden" name="action" value="remove_recursive">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">löschen</button>
            </form>
          <?php elseif ($sec === 'Gelöscht'): ?>
            <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('ENDGÜLTIG löschen? Das entfernt den Node + alle Subtasks + alle Notes unwiderruflich.');">
              <input type="hidden" name="action" value="delete_permanent">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">endgültig löschen</button>
            </form>
          <?php endif; ?>

        </div>
        <?php endif; ?>
      </div>

      <div class="card" style="margin-top:16px">
        <?php if (!$isContainerRoot): ?>

          <?php if (!empty($attachments)): ?>
            <div class="note" style="margin:0 0 12px 0;">
              <div class="head">Attachments</div>
              <ul style="margin:0; padding-left:18px;">
                <?php foreach ($attachments as $a): ?>
                  <?php
                    $tok = (string)($a['token'] ?? '');
                    $stored = (string)($a['stored_name'] ?? '');
                    $orig = (string)($a['orig_name'] ?? $stored);
                    $url = '/att/' . $tok . '/' . $stored;
                    $sz = $a['size_bytes'] ?? null;
                    $szTxt = '';
                    if ($sz !== null && $sz !== '') {
                      $n = (float)$sz;
                      if ($n >= 1024*1024) $szTxt = number_format($n/(1024*1024), 1, '.', '') . ' MB';
                      elseif ($n >= 1024) $szTxt = number_format($n/1024, 0, '.', '') . ' KB';
                      else $szTxt = (int)$n . ' B';
                    }
                    $cts = strtotime((string)($a['created_at'] ?? ''));
                    $meta = [];
                    if ($szTxt !== '') $meta[] = $szTxt;
                    if ($cts) $meta[] = date('d.m.Y H:i', $cts);
                    $metaTxt = $meta ? (' • ' . implode(' • ', $meta)) : '';
                    $uploader = (string)($a['created_by'] ?? '');
                    if ($uploader !== '') $metaTxt .= ($metaTxt !== '' ? ' • ' : ' • ') . 'von ' . $uploader;
                  ?>
                  <li>
                    <a href="<?php echo h($url); ?>" target="_blank" rel="noopener"><?php echo h($orig); ?></a>
                    <span class="meta"><?php echo h($metaTxt); ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <label>Aktueller Task-Text (read-only)</label>
          <?php $descCur = (string)($node['description'] ?? ''); ?>
          <textarea readonly style="min-height:180px; max-height:320px; overflow:auto; opacity:0.95; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px;"><?php echo h($descCur); ?></textarea>
          <div class="meta">Direktes Editieren ist deaktiviert. Bitte neue Subtasks anlegen.</div>
          <div style="height:14px"></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin:0">
          <input type="hidden" name="action" value="add_subtask">
          <input type="hidden" name="parent_id" value="<?php echo (int)$node['id']; ?>">

          <div class="row" style="align-items:center; gap:10px; margin-bottom:8px;">
            <label style="margin:0;">Neuen Subtask anlegen:</label>
            <select name="task_type" style="width:auto; min-width:180px; margin:0;">
              <option value="planung">Planung</option>
              <option value="umsetzung">Umsetzung</option>
            </select>
            <input name="title" placeholder="Titel (max. 3–4 Wörter)" required maxlength="40" style="flex:1; min-width:220px;">
          </div>

          <label>Beschreibung</label>
          <textarea name="description" required></textarea>

          <div class="row" style="margin-top:10px; align-items:center;">
            <span class="meta">Anhang optional:</span>
            <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt" style="width:auto; max-width:380px">
            <button class="btn" name="submit_to" value="oliver" type="submit">Absenden</button>
            <button class="btn" name="submit_to" value="james" type="submit">Absenden &gt; James</button>
          </div>
        </form>
      </div>

    <?php else: ?>
      <?php
        // Kanban overview (leaf nodes only) for Projekte
        $projectsRootId = 0;
        foreach ($roots as $r) {
          $t = (string)($r['title'] ?? '');
          if ($t === 'Projekte') $projectsRootId = (int)$r['id'];
        }

        // helper: is node under a specific root (by walking parents in $byIdAll)
        $isUnder = function(int $nodeId, int $rootId) use ($byIdAll): bool {
          if (!$rootId) return false;
          $cur = $nodeId;
          for ($i=0; $i<60; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return false;
            $pid = $row['parent_id'];
            if ($pid === null) return false;
            $pid = (int)$pid;
            if ($pid === $rootId) return true;
            $cur = $pid;
          }
          return false;
        };

        $cards = [
          'todo_oliver' => [],
          'todo_james' => [],
          'blocked' => [],
          'done' => [],
        ];

        $topProjectTitle = function(int $nodeId) use ($byIdAll, $projectsRootId): string {
          if ($nodeId <= 0 || !$projectsRootId) return '';
          $cur = $nodeId;
          for ($i=0; $i<120; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return '';
            $pid = $row['parent_id'];
            if ($pid === null) return '';
            $pid = (int)$pid;
            if ($pid === $projectsRootId) {
              return (string)($row['title'] ?? '');
            }
            $cur = $pid;
          }
          return '';
        };

        foreach ($byIdAll as $id => $n) {
          $id = (int)$id;
          // leaf only
          $hasKids = !empty($byParentAll[$id]);
          if ($hasKids) continue;

          // only Projekte
          if (!($isUnder($id, $projectsRootId))) continue;

          $st = (string)($n['worker_status'] ?? '');

          // route blocked James tasks into a separate kanban column
          if ($st === 'todo_james') {
            $blockedUntil = (string)($n['blocked_until'] ?? '');
            $blockedBy = (int)($n['blocked_by_node_id'] ?? 0);
            $isBlockedUntil = ($blockedUntil !== '' && strtotime($blockedUntil) && strtotime($blockedUntil) > time());
            $isBlockedBy = ($blockedBy > 0);
            if ($isBlockedUntil || $isBlockedBy) $st = 'blocked';
          }

          if (!isset($cards[$st])) continue;

          $sec = (string)($sectionByIdAll[$id] ?? '');
          if ($sec !== 'Projekte') continue;

          $projTitle = $topProjectTitle($id);
          if ($projTitle === '') $projTitle = $sec;

          $cards[$st][] = [
            'id' => $id,
            'title' => (string)($n['title'] ?? ''),
            'section' => $sec,
            'project' => $projTitle,
            'updated_at' => (string)($n['updated_at'] ?? ''),
            'has_att' => !empty($attCountById[$id]),
          ];
        }

        // sort newest updated first (fallback to id)
        foreach ($cards as $k => $arr) {
          usort($arr, function($a, $b) {
            $ta = $a['updated_at'] ?? '';
            $tb = $b['updated_at'] ?? '';
            if ($ta === $tb) return ($b['id'] <=> $a['id']);
            return strcmp($tb, $ta);
          });
          $cards[$k] = $arr;
        }

        $colTitle = [
          'todo_oliver' => 'ToDo (Oliver)',
          'todo_james' => 'ToDo (James)',
          'blocked' => 'BLOCKED',
          'done' => 'Done',
        ];

        // Depth indicator per Kanban column: use the top card in the list.
        $projectsRootId = $projectsRootId ?: 0;
        $ideasRootId = $ideasRootId ?: 0;

        $depthUnderRoot = function(int $nodeId, int $rootId) use ($byIdAll): int {
          if ($nodeId <= 0 || $rootId <= 0) return 0;
          $d = 0;
          $cur = $nodeId;
          for ($i=0; $i<120; $i++) {
            $row = $byIdAll[$cur] ?? null;
            if (!$row) return 0;
            $pid = $row['parent_id'];
            if ($pid === null) return 0;
            $pid = (int)$pid;
            $d++;
            if ($pid === $rootId) return $d;
            $cur = $pid;
          }
          return 0;
        };

        $maxDepthProjects = 0;
        $maxDepthIdeas = 0;
        foreach ($byIdAll as $id2 => $n2) {
          $id2 = (int)$id2;
          if ($projectsRootId && $isUnder($id2, $projectsRootId)) {
            $dd = $depthUnderRoot($id2, $projectsRootId);
            if ($dd > $maxDepthProjects) $maxDepthProjects = $dd;
          }
          if ($ideasRootId && $isUnder($id2, $ideasRootId)) {
            $dd = $depthUnderRoot($id2, $ideasRootId);
            if ($dd > $maxDepthIdeas) $maxDepthIdeas = $dd;
          }
        }

      ?>

      <?php if ($view === 'kanban'): ?>
      <div class="card">
        <h2 style="margin-bottom:10px">Kanban (Leafs: Projekte)</h2>
        <div class="kanban">
          <?php foreach (['todo_oliver','todo_james','blocked','done'] as $col): ?>
            <div class="kanban-col">
              <h3>
                <span><?php echo h($colTitle[$col]); ?></span>
                <span class="pill dim"><?php echo count($cards[$col]); ?></span>
              </h3>

              <?php
                $top = $cards[$col][0] ?? null;
                $topId = $top ? (int)$top['id'] : 0;
                $topSec = $top ? (string)$top['section'] : '';

                // Depth bars should be comparable across columns.
                // We scale everything against the maximum depth in "Projekte" (deepest node under Projekte).
                $scaleMaxD = (int)$maxDepthProjects;
                if ($scaleMaxD <= 0) $scaleMaxD = (int)$maxDepthIdeas; // fallback only if Projekte root missing

                // Current depth is still computed under the node's own section root.
                $rootId = 0;
                if ($topSec === 'Projekte') $rootId = $projectsRootId;
                if ($topSec === 'Ideen') $rootId = $ideasRootId;

                $curDepth = ($rootId && $topId) ? $depthUnderRoot($topId, $rootId) : 0;
                $pct = ($scaleMaxD > 0 && $curDepth > 0) ? max(0, min(100, (int)round(($curDepth / $scaleMaxD) * 100))) : 0;
                $tt = $topId ? ('Top-Task: Tiefe ' . $curDepth . ' / ' . $scaleMaxD . ' (#' . $topId . ')') : '—';
              ?>

              <?php if ($pct > 0): ?>
                <div title="<?php echo h($tt); ?>" style="position:relative; height:8px; border-radius:999px; background:linear-gradient(90deg, rgba(120,255,170,0.9), rgba(255,200,90,0.9), rgba(255,120,120,0.95)); overflow:hidden; margin:6px 0 10px 0;">
                  <div style="position:absolute; top:0; right:0; height:100%; width:<?php echo (int)(100-$pct); ?>%; background:rgba(0,0,0,0.80);"></div>
                </div>
              <?php else: ?>
                <div title="<?php echo h($tt); ?>" style="position:relative; height:8px; border-radius:999px; background:linear-gradient(90deg, rgba(120,255,170,0.9), rgba(255,200,90,0.9), rgba(255,120,120,0.95)); overflow:hidden; margin:6px 0 10px 0;">
                  <div style="position:absolute; top:0; right:0; height:100%; width:100%; background:rgba(0,0,0,0.88);"></div>
                </div>
              <?php endif; ?>

              <?php if (empty($cards[$col])): ?>
                <div class="meta" style="padding:8px 2px;">—</div>
              <?php else: ?>
                <?php foreach (array_slice($cards[$col], 0, 20) as $c): ?>
                  <a class="kanban-card" href="/?id=<?php echo (int)$c['id']; ?>">
                    <div class="kanban-title"><?php echo h($c['title']); ?><?php if (!empty($c['has_att'])): ?> <span class="att-clip" title="Attachment">📁</span><?php endif; ?></div>
                    <div class="kanban-meta">
                      <span class="pill section"><?php echo h((string)($c['project'] ?? $c['section'])); ?></span>
                      <?php
                        $right = '#' . (int)$c['id'];
                        if ($col === 'done') {
                          $ts = strtotime((string)($c['updated_at'] ?? ''));
                          if ($ts) {
                            $delta = time() - $ts;
                            if ($delta < 0) $delta = 0;
                            if ($delta < 60) {
                              $right = 'vor ' . $delta . 's · ' . $right;
                            } elseif ($delta < 90*60) {
                              $mins = (int)round($delta / 60);
                              $right = 'vor ' . $mins . ' Min. · ' . $right;
                            } elseif ($delta < 48*3600) {
                              $hrs = $delta / 3600;
                              $hrsTxt = ($hrs < 10) ? number_format($hrs, 1, ',', '') : (string)round($hrs);
                              $right = 'vor ' . $hrsTxt . 'h · ' . $right;
                            } else {
                              $days = (int)round($delta / 86400);
                              $right = 'vor ' . $days . 'd · ' . $right;
                            }
                          }
                        }
                      ?>
                      <span class="pill dim"><?php echo h($right); ?></span>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php elseif ($view === 'live'): ?>
        <?php
          // Build graph data for the CURRENT project under "Projekte" (not the whole Projekte subtree)
          $graph = ['nodes'=>[], 'edges'=>[]];
          $activeProjectId = 0;

          // Find active project (top-level child of Projekte root) for current selection
          if (!empty($projectsRootId) && $nodeId > 0 && !empty($byIdAll[$nodeId]) && $isUnder($nodeId, (int)$projectsRootId)) {
            $cur = (int)$nodeId;
            for ($i=0; $i<120; $i++) {
              $row = $byIdAll[$cur] ?? null;
              if (!$row) break;
              $pid = $row['parent_id'];
              if ($pid === null) break;
              $pid = (int)$pid;
              if ($pid === (int)$projectsRootId) { $activeProjectId = $cur; break; }
              $cur = $pid;
            }
          }

          $activeProjectTitle = $activeProjectId ? (string)($byIdAll[$activeProjectId]['title'] ?? '') : '';

          // blocked detection (match selector semantics)
          $isBlockedNode = function(int $id) use ($byIdAll): bool {
            $n = $byIdAll[$id] ?? null;
            if (!$n) return true;
            $bu = (string)($n['blocked_until'] ?? '');
            $bb = (int)($n['blocked_by_node_id'] ?? 0);
            if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;
            if ($bb > 0) {
              $bn = $byIdAll[$bb] ?? null;
              if (!$bn) return true;
              return (string)($bn['worker_status'] ?? '') !== 'done';
            }
            return false;
          };

          if ($activeProjectId > 0) {
            $inProj = [];
            $stack = [$activeProjectId];
            while ($stack) {
              $cur = array_pop($stack);
              $inProj[$cur] = true;
              foreach (($byParentAll[$cur] ?? []) as $ch) {
                $cid = (int)$ch['id'];
                $stack[] = $cid;
              }
            }

            foreach ($inProj as $id3 => $_) {
              $n3 = $byIdAll[(int)$id3] ?? null;
              if (!$n3) continue;

              $ws = (string)($n3['worker_status'] ?? '');
              $status = $ws;
              if ($ws !== 'done' && $isBlockedNode((int)$id3)) $status = 'blocked';

              $graph['nodes'][] = [
                'data' => [
                  'id' => 'n' . (int)$id3,
                  'node_id' => (int)$id3,
                  'label' => '#' . (int)$id3 . ' ' . (string)($n3['title'] ?? ''),
                  'title' => (string)($n3['title'] ?? ''),
                  'status' => $status,
                  'worker_status' => $ws,
                ]
              ];

              $pid = $n3['parent_id'] === null ? 0 : (int)$n3['parent_id'];
              if ($pid > 0 && isset($inProj[$pid])) {
                $graph['edges'][] = [
                  'data' => [
                    'id' => 'e' . $pid . '_' . (int)$id3,
                    'source' => 'n' . $pid,
                    'target' => 'n' . (int)$id3,
                    'type' => 'tree',
                  ]
                ];
              }

              $bb = (int)($n3['blocked_by_node_id'] ?? 0);
              if ($bb > 0 && isset($inProj[$bb])) {
                $graph['edges'][] = [
                  'data' => [
                    'id' => 'b' . (int)$id3 . '_' . $bb,
                    'source' => 'n' . $bb,
                    'target' => 'n' . (int)$id3,
                    'type' => 'blocked_by',
                  ]
                ];
              }
            }
          }
        ?>

        <div class="card">
          <div class="row" style="justify-content:space-between; align-items:center; gap:12px;">
            <div>
              <h2 style="margin:0 0 6px 0;">Test: Projekte-Graph<?php if (!empty($activeProjectTitle)): ?> · <?php echo h($activeProjectTitle); ?><?php endif; ?></h2>
              <div class="meta">Klick = Fokus · Doppelklick = öffnet Node · Filter/Zoom/Highlight. (Experimental)</div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
              <input id="gSearch" type="text" placeholder="#id oder Text…" style="width:220px;">
              <label class="pill" style="display:flex; gap:6px; align-items:center;"><input id="gShowDone" type="checkbox" checked> Done</label>
              <label class="pill" style="display:flex; gap:6px; align-items:center;"><input id="gShowBlockedEdges" type="checkbox" checked> Blocker-Kanten</label>
              <a class="btn btn-md" href="#" id="gFit">Fit</a>
              <a class="btn btn-md" href="#" id="gLayout">Layout</a>
            </div>
          </div>

          <div style="height:12px"></div>
          <div id="cy" style="height: calc(100vh - 240px); min-height:520px; border-radius:14px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08);"></div>

          <div style="height:10px"></div>
          <div class="meta" id="gHint"></div>
        </div>

        <link rel="stylesheet" href="https://unpkg.com/cytoscape-panzoom/cytoscape.js-panzoom.css">
        <script src="https://unpkg.com/cytoscape@3.26.0/dist/cytoscape.min.js"></script>
        <script src="https://unpkg.com/dagre@0.8.5/dist/dagre.min.js"></script>
        <script src="https://unpkg.com/cytoscape-dagre@2.5.0/cytoscape-dagre.js"></script>
        <script src="https://unpkg.com/cytoscape-panzoom/cytoscape-panzoom.js"></script>

        <script>
          (function(){
            const elements = <?php echo json_encode(array_merge($graph['nodes'], $graph['edges']), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
            const selectedNodeId = <?php echo (int)$nodeId; ?>;
            const activeProjectId = <?php echo (int)$activeProjectId; ?>;

            function colorFor(status){
              // match the chart colors
              if(status==='todo_james') return '#b28cff'; // purple
              if(status==='todo_oliver') return '#78c8ff'; // blue
              if(status==='done') return '#ffd580'; // yellow
              if(status==='blocked') return 'rgba(255,120,120,0.95)'; // red
              return '#9aa0a6';
            }

            const cy = window.cy = cytoscape({
              container: document.getElementById('cy'),
              elements,
              wheelSensitivity: 0.20,
              style: [
                {
                  selector: 'node',
                  style: {
                    'background-color': ele => colorFor(ele.data('status')),
                    'label': 'data(label)',
                    'color': 'rgba(255,255,255,0.92)',
                    'text-outline-color': 'rgba(0,0,0,0.55)',
                    'text-outline-width': 2,
                    'font-size': 11,
                    'text-wrap': 'wrap',
                    'text-max-width': 140,
                    'width': 26,
                    'height': 26,
                    'border-width': 1,
                    'border-color': 'rgba(255,255,255,0.15)'
                  }
                },
                {
                  selector: 'node[status="done"]',
                  style: { 'opacity': 0.55 }
                },
                {
                  selector: 'edge[type="tree"]',
                  style: {
                    'width': 1.4,
                    'line-color': 'rgba(255,255,255,0.14)',
                    'curve-style': 'bezier',
                    'target-arrow-shape': 'none'
                  }
                },
                {
                  selector: 'edge[type="blocked_by"]',
                  style: {
                    'width': 1.6,
                    'line-color': 'rgba(255,120,120,0.65)',
                    'line-style': 'dashed',
                    'target-arrow-shape': 'triangle',
                    'target-arrow-color': 'rgba(255,120,120,0.75)',
                    'curve-style': 'bezier'
                  }
                },
                {
                  selector: '.hidden',
                  style: { 'display': 'none' }
                },
                {
                  selector: '.faded',
                  style: { 'opacity': 0.12 }
                },
                {
                  selector: '.focused',
                  style: {
                    'border-width': 3,
                    'border-color': 'rgba(255,255,255,0.65)',
                    'width': 30,
                    'height': 30
                  }
                }
              ],
              layout: {
                name: 'dagre',
                rankDir: 'TB',
                nodeSep: 18,
                rankSep: 55,
                edgeSep: 10,
                animate: true,
                animationDuration: 280
              }
            });

            try { cy.panzoom({}); } catch(e) {}

            function applyFilters(){
              const showDone = document.getElementById('gShowDone').checked;
              const showBlockedEdges = document.getElementById('gShowBlockedEdges').checked;

              cy.nodes().forEach(n => {
                const isDone = (n.data('status') === 'done');
                n.toggleClass('hidden', isDone && !showDone);
              });
              cy.edges('[type="blocked_by"]').toggleClass('hidden', !showBlockedEdges);
            }

            function focusNode(n){
              cy.elements().removeClass('focused');
              cy.elements().addClass('faded');
              n.removeClass('faded');
              n.addClass('focused');
              // show neighborhood
              n.closedNeighborhood().removeClass('faded');
              cy.animate({ fit: { eles: n.closedNeighborhood(), padding: 60 } }, { duration: 260 });
              const hint = document.getElementById('gHint');
              if (hint) hint.textContent = `Fokus: #${n.data('node_id')} · ${n.data('title')} · status=${n.data('status')}`;
            }

            cy.on('tap', 'node', (evt) => {
              const n = evt.target;
              focusNode(n);
            });

            cy.on('dbltap', 'node', (evt) => {
              const nid = evt.target.data('node_id');
              const qs = new URLSearchParams(window.location.search);
              qs.set('id', nid);
              qs.set('view', 'test');
              window.location.href = '/?' + qs.toString();
            });

            document.getElementById('gFit').addEventListener('click', (e)=>{ e.preventDefault(); cy.animate({ fit: { eles: cy.elements(':visible'), padding: 50 } }, { duration: 240 }); });
            document.getElementById('gLayout').addEventListener('click', (e)=>{ e.preventDefault(); cy.layout({ name:'dagre', rankDir:'TB', nodeSep:18, rankSep:55, edgeSep:10, animate:true, animationDuration:260 }).run(); });
            document.getElementById('gShowDone').addEventListener('change', applyFilters);
            document.getElementById('gShowBlockedEdges').addEventListener('change', applyFilters);

            const searchEl = document.getElementById('gSearch');
            let searchT = null;
            function doSearch(){
              const q = (searchEl.value || '').trim().toLowerCase();
              if (!q){
                cy.elements().removeClass('faded');
                document.getElementById('gHint').textContent = '';
                return;
              }
              const hit = cy.nodes().filter(n => {
                const id = String(n.data('node_id'));
                const label = String(n.data('label')||'').toLowerCase();
                return ('#'+id) === q || id === q || label.includes(q);
              })[0];
              if (hit) focusNode(hit);
            }
            searchEl.addEventListener('input', ()=>{ clearTimeout(searchT); searchT=setTimeout(doSearch, 160); });

            applyFilters();

            // initial focus
            if (!elements || elements.length===0) {
              const hint = document.getElementById('gHint');
              if (hint) hint.textContent = 'Kein Projekt ausgewählt. Bitte links einen Node unter "Projekte" anklicken.';
              return;
            }

            // Prefer showing the project root at the top, then (optionally) focus the selected node.
            const root = (activeProjectId ? cy.getElementById('n'+activeProjectId) : null);
            if (root && root.length) {
              focusNode(root);
              // if selection is inside this project, focus it afterwards
              if (selectedNodeId) {
                const n = cy.getElementById('n'+selectedNodeId);
                if (n && n.length) setTimeout(()=>focusNode(n), 180);
              }
            } else {
              cy.fit(cy.elements(':visible'), 50);
            }
          })();
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>

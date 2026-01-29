<?php
require_once __DIR__ . '/functions.inc.php';
requireLogin();

$pdo = db();

// current selection
$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
    $nid = (int)($_POST['node_id'] ?? 0);
    $formNote = (string)($_POST['description'] ?? '');

    // block editing of container roots
    $st = $pdo->prepare('SELECT parent_id, title FROM nodes WHERE id=?');
    $st->execute([$nid]);
    $row = $st->fetch();
    $isContainerRoot = $row && ($row['parent_id'] === null) && in_array((string)$row['title'], ['Ideen','Projekte','Später','Gelöscht'], true);

    if (!$nid) {
      flash_set('Projekt fehlt.', 'err');
    } elseif ($isContainerRoot) {
      flash_set('Oberprojekte haben kein Notizfeld. Bitte nur Subtasks anlegen.', 'err');
    } elseif (trim($formNote) === '') {
      flash_set('Text fehlt.', 'err');
    } else {
      $ts = date('d.m.Y H:i');
      $newDesc = rtrim($formNote);
      $newDesc .= "\n\n[oliver] {$ts} Statusänderung: todo";

      $st = $pdo->prepare('UPDATE nodes SET description=?, worker_status="todo_oliver" WHERE id=?');
      $st->execute([$newDesc, $nid]);

      flash_set('Gespeichert.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
  }

  if ($action === 'add_subtask') {
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $formChildTitle = (string)($_POST['title'] ?? '');
    $formChildBody = (string)($_POST['description'] ?? '');

    $title = trim($formChildTitle);
    $body = trim($formChildBody);

    if (!$parentId) {
      flash_set('Projekt fehlt.', 'err');
    } elseif ($title === '') {
      flash_set('Titel für Subtask bitte eingeben.', 'err');
    } elseif ($body === '') {
      flash_set('Beschreibung für Subtask bitte eingeben.', 'err');
    } else {
      $ts = date('d.m.Y H:i');
      $desc = rtrim($formChildBody) . "\n\n[oliver] {$ts} Statusänderung: todo";

      $st = $pdo->prepare('INSERT INTO nodes (parent_id, title, description, priority, created_by, worker_status) VALUES (?, ?, ?, ?, ?, ?)');
      $st->execute([$parentId, $title, $desc, null, 'oliver', 'todo_oliver']);
      $newId = (int)$pdo->lastInsertId();

      // also append a short line into parent description
      $st = $pdo->prepare('UPDATE nodes SET description=CONCAT(COALESCE(description,\'\'), ?) WHERE id=?');
      $st->execute(["\n\n[oliver] {$ts} Subtask angelegt: {$title}", $parentId]);

      flash_set('Subtask angelegt.', 'info');
      header('Location: /?id=' . $newId);
      exit;
    }
  }

  if ($action === 'set_worker') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $worker = (string)($_POST['worker_status'] ?? '');
    $allowed = ['todo_james','todo_oliver','done'];
    if ($nid && in_array($worker, $allowed, true)) {
      $st = $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?');
      $st->execute([$worker, $nid]);

      // append change into description
      $ts = date('d.m.Y H:i');
      $pdo->prepare('UPDATE nodes SET description=CONCAT(COALESCE(description,\'\'), ?) WHERE id=?')
          ->execute(["\n\n[oliver] {$ts} Statusänderung: {$worker}", $nid]);

      // delegation rule: if delegating to James, cascade todo_oliver descendants to todo_james
      if ($worker === 'todo_james') {
        $moved = propagateDelegation($pdo, $nid, 'todo_oliver', 'todo_james');
        if ($moved > 0) {
          $pdo->prepare('UPDATE nodes SET description=CONCAT(COALESCE(description,\'\'), ?) WHERE id=?')
              ->execute(["\n\n[oliver] {$ts} Delegation: {$moved} Subtasks zu James", $nid]);
        }
      }

      // done rule: when marking a node done, mark ALL descendants done as well
      if ($worker === 'done') {
        $nDone = propagateStatusAllDescendants($pdo, $nid, 'done');
        if ($nDone > 0) {
          $pdo->prepare('UPDATE nodes SET description=CONCAT(COALESCE(description,\'\'), ?) WHERE id=?')
              ->execute(["\n\n[oliver] {$ts} Done: {$nDone} Subtasks erledigt", $nid]);
        }
      }

      flash_set('Status gespeichert.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Ungültiger Status.', 'err');
  }

  // set_main removed (main_status dropped)
}

// Load full tree for nav
$allNodes = $pdo->query("SELECT id, parent_id, title, worker_status, priority FROM nodes ORDER BY COALESCE(priority,999), id")->fetchAll();
$byParentAll = [];
$byIdAll = [];
foreach ($allNodes as $n) {
  $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
  $byParentAll[$pid][] = $n;
  $byIdAll[(int)$n['id']] = $n;
}

$sectionByIdAll = buildSectionMap($byParentAll, 0, '');

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

// pick default selection: first root
$roots = $byParent[0] ?? [];
if (!$nodeId && $roots) $nodeId = (int)$roots[0]['id'];

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

function renderTree(array $byParent, array $byId, array $sectionByIdAll, array $open, int $currentId, int $parentId=0, int $depth=0, array $prefix=[], array &$countsMemo=[]): void {
  if (empty($byParent[$parentId])) return;

  $i = 0;
  foreach ($byParent[$parentId] as $n) {
    $i++;
    $id = (int)$n['id'];
    $title = $n['title'];
    $hasKids = !empty($byParent[$id]);
    $isActive = ($id === (int)$currentId);

    $indent = $depth * 14;
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
      $kids = $byParent[$curId] ?? [];
      if ($kids) {
        foreach ($kids as $cc) $stack[] = (int)$cc['id'];
      } else {
        $st = (string)($byId[$curId]['worker_status'] ?? '');
        if ($st === 'todo_james') $todoJames++;
        if ($st === 'todo_oliver') $todoOliver++;
        if ($st === 'done') $done++;
      }
    }
    $parts = [];
    if ($todoJames > 0) $parts[] = 'James: ' . $todoJames;
    if ($todoOliver > 0) $parts[] = 'Oliver: ' . $todoOliver;
    if ($done > 0) $parts[] = 'Done: ' . $done;
    $statusText = $parts ? implode(' | ', $parts) : '';

    $shade = max(0, min(4, $depth));
    $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

    $sec = (string)($sectionByIdAll[$id] ?? '');
    $msClass = '';
    if ($sec === 'Gelöscht') $msClass = ' ms-canceled';
    if ($sec === 'Später') $msClass = ' ms-later';

    if ($hasKids) {
      $forceOpenAll = (!empty($_GET['open']) && $_GET['open'] === 'all') || !empty($_GET['q']);
      $isOpen = $forceOpenAll || ($open[$id] ?? $isActive);
      echo '<details class="tree-branch" ' . ($isOpen ? 'open' : '') . ' style="margin-left:' . $indent . 'px">';
      echo '<summary class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</summary>';
      renderTree($byParent, $byId, $sectionByIdAll, $open, $currentId, $id, $depth+1, $numParts, $countsMemo);
      echo '</details>';
    } else {
      echo '<div class="tree-leaf" style="margin-left:' . $indent . 'px">';
      echo '<div class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      if ($statusText !== '') echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</div></div>';
    }
  }
}

$node = null;
$children = [];
$notes = [];

if ($nodeId) {
  $st = $pdo->prepare('SELECT * FROM nodes WHERE id=?');
  $st->execute([$nodeId]);
  $node = $st->fetch();

  // children list removed from UI; keep query minimal for potential future use
  $st = $pdo->prepare('SELECT id,title,worker_status FROM nodes WHERE parent_id=? ORDER BY id');
  $st->execute([$nodeId]);
  $children = $st->fetchAll();

  // legacy notes no longer rendered (we use nodes.description as the editable task text)
  $notes = [];
}

renderHeader('Dashboard');
?>

<div class="grid">
  <div class="card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <h2 style="margin:0;">Projekte / Ideen (<?php echo count($roots); ?>)</h2>
      <form method="get" style="margin:0;">
        <?php if ($nodeId): ?><input type="hidden" name="id" value="<?php echo (int)$nodeId; ?>"><?php endif; ?>
        <?php if (!empty($_GET['q'])): ?><input type="hidden" name="q" value="<?php echo h((string)$_GET['q']); ?>"><?php endif; ?>
        <select name="open" onchange="this.form.submit()" style="width:auto; padding:6px 10px; font-size:12px;">
          <option value="">close all</option>
          <option value="all" <?php echo (!empty($_GET['open']) && $_GET['open']==='all') ? 'selected' : ''; ?>>open all</option>
        </select>
      </form>
    </div>

    <form method="get" style="margin:8px 0 0 0; display:flex; gap:10px;">
      <input type="text" name="q" placeholder="Notizen durchsuchen..." value="<?php echo h((string)($_GET['q'] ?? '')); ?>" style="flex:1">
      <?php if ($nodeId): ?><input type="hidden" name="id" value="<?php echo (int)$nodeId; ?>"><?php endif; ?>
      <?php if (!empty($_GET['open'])): ?><input type="hidden" name="open" value="<?php echo h((string)$_GET['open']); ?>"><?php endif; ?>
      <button class="btn" type="submit">Suchen</button>
    </form>

    <div style="height:10px"></div>

    <div class="tree">
      <?php renderTree($byParent, $byId, $sectionByIdAll, $open, (int)$nodeId, 0, 0); ?>
    </div>
  </div>

  <div>
    <?php if ($node): ?>
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
        <div class="meta">#<?php echo (int)$node['id']; ?> • created_by=<?php echo h($node['created_by']); ?></div>

        <?php
          $ws = (string)($node['worker_status'] ?? '');
          $sec = (string)($sectionByIdAll[(int)$node['id']] ?? '');
          $isInProjekte = ($sec === 'Projekte');
        ?>

        <?php
          $isRoot = (($byId[(int)$node['id']]['parent_id'] ?? null) === null);
          $isContainerRoot = $isRoot && in_array((string)$node['title'], ['Ideen','Projekte','Später','Gelöscht'], true);
        ?>

        <?php if (!$isContainerRoot): ?>
        <div class="row" style="margin-top:10px; align-items:center">
          <div class="meta">Optionen:</div>

          <?php if ($sec !== 'Projekte'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_active">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn btn-gold" type="submit">aktivieren</button>
            </form>
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
                <button class="btn btn-gold" name="worker_status" value="todo_oliver" type="submit">an Oliver</button>
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
          <?php elseif ($sec === 'Später'): ?>
            <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Wirklich nach „Gelöscht" verschieben? (inkl. Subtasks)');">
              <input type="hidden" name="action" value="remove_recursive">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">löschen</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="card" style="margin-top:16px">
        <?php if (!$isContainerRoot): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">

            <label>Aufgabe / Notiz:</label>
            <textarea name="description" required><?php echo h((string)($node['description'] ?? '')); ?></textarea>

            <div style="margin-top:10px">
              <button class="btn btn-gold" type="submit">Speichern</button>
            </div>
          </form>

          <div style="height:14px"></div>
        <?php endif; ?>

        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="add_subtask">
          <input type="hidden" name="parent_id" value="<?php echo (int)$node['id']; ?>">

          <label>Neuen Subtask anlegen: <span class="meta">Titel</span></label>
          <input name="title" placeholder="max. 3–4 Wörter" required>

          <label>Beschreibung</label>
          <textarea name="description" required></textarea>

          <div style="margin-top:10px">
            <button class="btn btn-gold" type="submit">Absenden</button>
          </div>
        </form>
      </div>

    <?php else: ?>
      <div class="card"><h2>No node selected</h2></div>
    <?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>

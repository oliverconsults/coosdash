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

// handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_note_or_child') {
    $nid = (int)($_POST['node_id'] ?? 0);

    // keep raw input for re-render (don't lose user's text)
    $formNote = (string)($_POST['note'] ?? '');
    $formAsChild = !empty($_POST['as_child']);
    $formChildTitle = (string)($_POST['child_title'] ?? '');

    $note = trim($formNote);
    $asChild = $formAsChild;
    $title = trim($formChildTitle);

    if (!$nid) {
      flash_set('Missing node.', 'err');
    } elseif ($note === '') {
      flash_set('Missing note.', 'err');
    } elseif ($asChild && $title === '') {
      flash_set('Namen für Subprojekt bitte eingeben.', 'err');
    } else {
      if ($asChild) {
        // create a subproject under current node
        $st = $pdo->prepare('INSERT INTO nodes (parent_id, title, description, priority, created_by, main_status, worker_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$nid, $title, null, null, 'oliver', 'active', 'todo']);
        $newId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, ?, ?)');
        $st->execute([$newId, 'oliver', $note]);

        flash_set('Subprojekt created.', 'info');
        header('Location: /?id=' . $newId);
        exit;
      }

      // just add note to current node
      $st = $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, ?, ?)');
      $st->execute([$nid, 'oliver', $note]);
      flash_set('Note added.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
  }

  if ($action === 'set_worker') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $worker = (string)($_POST['worker_status'] ?? '');
    $allowed = ['todo','approve','done'];
    if ($nid && in_array($worker, $allowed, true)) {
      $st = $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?');
      $st->execute([$worker, $nid]);
      flash_set('Worker status updated.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Invalid worker status.', 'err');
  }

  if ($action === 'set_main') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $main = (string)($_POST['main_status'] ?? '');
    $allowed = ['new','active','later','canceled'];
    if ($nid && in_array($main, $allowed, true)) {
      $st = $pdo->prepare('UPDATE nodes SET main_status=? WHERE id=?');
      $st->execute([$main, $nid]);
      flash_set('Main status updated.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Invalid main status.', 'err');
  }
}

// Load full tree for nav
$allNodes = $pdo->query("SELECT id, parent_id, title, main_status, worker_status, priority FROM nodes ORDER BY COALESCE(priority,999), id")->fetchAll();
$byParent = [];
$byId = [];
foreach ($allNodes as $n) {
  $pid = $n['parent_id'] === null ? 0 : (int)$n['parent_id'];
  $byParent[$pid][] = $n;
  $byId[(int)$n['id']] = $n;
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

function renderTree(array $byParent, array $open, int $currentId, int $parentId=0, int $depth=0, array $prefix=[]): void {
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

    // right tag stays as main_status | worker_status
    $statusText = ($n['main_status'] ?? '') . ' | ' . ($n['worker_status'] ?? '');

    $shade = max(0, min(4, $depth));
    $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

    $msClass = '';
    if (($n['main_status'] ?? '') === 'canceled') $msClass = ' ms-canceled';
    if (($n['main_status'] ?? '') === 'later') $msClass = ' ms-later';

    if ($hasKids) {
      $forceOpenAll = (!empty($_GET['open']) && $_GET['open'] === 'all');
      $isOpen = $forceOpenAll || ($open[$id] ?? $isActive);
      echo '<details class="tree-branch" ' . ($isOpen ? 'open' : '') . ' style="margin-left:' . $indent . 'px">';
      echo '<summary class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
      echo '</summary>';
      renderTree($byParent, $open, $currentId, $id, $depth+1, $numParts);
      echo '</details>';
    } else {
      echo '<div class="tree-leaf" style="margin-left:' . $indent . 'px">';
      echo '<div class="tree-item ' . ($isActive ? 'active' : '') . $msClass . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num) . '</span>'
        . '&nbsp;'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      echo '<span class="tag" style="margin-left:auto">' . h($statusText) . '</span>';
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
  $st = $pdo->prepare('SELECT id,title,main_status,worker_status FROM nodes WHERE parent_id=? ORDER BY id');
  $st->execute([$nodeId]);
  $children = $st->fetchAll();

  $st = $pdo->prepare('SELECT author, created_at, note FROM node_notes WHERE node_id=? ORDER BY id DESC LIMIT 50');
  $st->execute([$nodeId]);
  $notes = $st->fetchAll();
}

renderHeader('Dashboard');
?>

<div class="grid">
  <div class="card">
    <div class="row" style="justify-content:space-between; align-items:center;">
      <h2 style="margin:0;">Projects / Ideas (<?php echo count($roots); ?>)</h2>
      <form method="get" style="margin:0;">
        <?php if ($nodeId): ?><input type="hidden" name="id" value="<?php echo (int)$nodeId; ?>"><?php endif; ?>
        <select name="open" onchange="this.form.submit()" style="width:auto; padding:6px 10px; font-size:12px;">
          <option value="">close all</option>
          <option value="all" <?php echo (!empty($_GET['open']) && $_GET['open']==='all') ? 'selected' : ''; ?>>open all</option>
        </select>
      </form>
    </div>

    <div style="height:10px"></div>

    <div class="tree">
      <?php renderTree($byParent, $open, (int)$nodeId, 0, 0); ?>
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
          <span class="tag gold"><?php echo h(($node['main_status'] ?? '') . ' | ' . ($node['worker_status'] ?? '')); ?></span>
        </div>
        <div class="meta">#<?php echo (int)$node['id']; ?> • created_by=<?php echo h($node['created_by']); ?></div>

        <?php
          $ms = (string)($node['main_status'] ?? '');
          $ws = (string)($node['worker_status'] ?? '');
        ?>

        <div class="row" style="margin-top:10px; align-items:center">
          <div class="meta">Optionen:</div>

          <?php if ($ms === 'new' && $ws === 'approve'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="accept">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn btn-gold" type="submit">accept</button>
            </form>
          <?php endif; ?>

          <?php if ($ms === 'active' && $ws === 'approve'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="approve_to_todo_recursive">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn btn-gold" type="submit">release</button>
            </form>
          <?php endif; ?>

          <?php if ($ms === 'later' || $ms === 'canceled'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_active">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn btn-gold" type="submit">activate</button>
            </form>
          <?php endif; ?>

          <?php if ($ms !== 'later'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_later">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">later</button>
            </form>
          <?php endif; ?>

          <?php if ($ms !== 'canceled'): ?>
            <form method="post" action="/actions.php" style="margin:0">
              <input type="hidden" name="action" value="set_cancel">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">cancel</button>
            </form>
          <?php endif; ?>

          <?php if ($ms === 'canceled'): ?>
            <form method="post" action="/actions.php" style="margin:0" onsubmit="return confirm('Wirklich endgültig löschen? Das löscht auch alle Subprojekte und Notizen.');">
              <input type="hidden" name="action" value="remove_recursive">
              <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
              <button class="btn" type="submit">remove</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <form method="post" style="margin:0" onsubmit="if (this.as_child && this.as_child.checked) { return confirm('Subprojekt anlegen?'); } return true;">
          <input type="hidden" name="action" value="add_note_or_child">
          <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">

          <label>Neue Notiz anlegen:</label>
          <textarea name="note" placeholder="[oliver] <?php echo h(date('d.m.Y H:i')); ?> - ..." required><?php echo h($formNote); ?></textarea>

          <div class="row" style="align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;margin:0; white-space:nowrap;">
              als Subprojekt anlegen:
              <input type="checkbox" name="as_child" value="1" <?php echo $formAsChild ? 'checked' : ''; ?>>
            </label>

            <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:320px">
              <span class="meta" style="white-space:nowrap">Name:</span>
              <input name="child_title" placeholder="max. 3–4 Wörter" style="flex:1" value="<?php echo h($formChildTitle); ?>">
            </div>

            <button class="btn btn-gold" type="submit">Absenden</button>
          </div>
        </form>

        <?php foreach ($notes as $n): ?>
          <div class="note">
            <div class="head">[<?php echo h($n['author']); ?>] <?php echo h($n['created_at']); ?></div>
            <div><?php echo nl2br(h($n['note'])); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="card"><h2>No node selected</h2></div>
    <?php endif; ?>
  </div>
</div>

<?php renderFooter(); ?>

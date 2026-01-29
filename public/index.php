<?php
require_once __DIR__ . '/functions.inc.php';
requireLogin();

$pdo = db();

// current selection
$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_note') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($nid && $note !== '') {
      $st = $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, ?, ?)');
      $st->execute([$nid, 'oliver', $note]);
      flash_set('Note added.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Missing note.', 'err');
  }

  if ($action === 'add_child') {
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($parentId && $title !== '' && $note !== '') {
      // Always create an active subproject that is ready to work on
      $st = $pdo->prepare('INSERT INTO nodes (parent_id, title, description, priority, created_by, main_status, worker_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $st->execute([$parentId, $title, null, null, 'oliver', 'active', 'todo']);
      $newId = (int)$pdo->lastInsertId();

      // first note is required
      $st = $pdo->prepare('INSERT INTO node_notes (node_id, author, note) VALUES (?, ?, ?)');
      $st->execute([$newId, 'oliver', $note]);

      flash_set('Subprojekt created.', 'info');
      header('Location: /?id=' . $newId);
      exit;
    }
    flash_set('Missing title or note.', 'err');
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

    $directCount = !empty($byParent[$id]) ? count($byParent[$id]) : 0;
    $countTxt = $hasKids ? ' (' . $directCount . ')' : '';

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

          <form method="post" action="/actions.php" style="margin:0">
            <input type="hidden" name="action" value="set_later">
            <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
            <button class="btn" type="submit">later</button>
          </form>

          <form method="post" action="/actions.php" style="margin:0">
            <input type="hidden" name="action" value="set_cancel">
            <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
            <button class="btn" type="submit">cancel</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="row" style="justify-content:space-between; align-items:center;">
          <h2 style="margin:0;">Neues Subprojekt anlegen:</h2>
        </div>

        <form method="post" style="margin-top:10px">
          <input type="hidden" name="action" value="add_child">
          <input type="hidden" name="parent_id" value="<?php echo (int)$node['id']; ?>">

          <label>Neuer Subprojekt-Titel (kurz)</label>
          <input name="title" required placeholder="max. 3–4 Wörter">

          <label>Erste Notiz (Pflicht)</label>
          <textarea name="note" required placeholder="[oliver] <?php echo h(date('d.m.Y H:i')); ?> - ..."></textarea>

          <div style="margin-top:12px">
            <button class="btn btn-gold" type="submit">Create</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <h2>Notizzettel</h2>

        <form method="post">
          <input type="hidden" name="action" value="add_note">
          <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
          <label>Neue Notiz</label>
          <textarea name="note" placeholder="[oliver] <?php echo h(date('d.m.Y H:i')); ?> - ..." required></textarea>
          <div style="margin-top:10px">
            <button class="btn btn-gold" type="submit">Add note</button>
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

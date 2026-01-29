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
    $type = (string)($_POST['type'] ?? 'idea');
    $priority = $_POST['priority'] !== '' ? (int)$_POST['priority'] : null;
    if ($parentId && $title !== '') {
      $st = $pdo->prepare('INSERT INTO nodes (parent_id, type, status, title, description, priority, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $st->execute([$parentId, $type, 'new', $title, null, $priority, 'oliver']);
      $newId = (int)$pdo->lastInsertId();
      flash_set('Child node created.', 'info');
      header('Location: /?id=' . $newId);
      exit;
    }
    flash_set('Missing title.', 'err');
  }

  if ($action === 'set_status') {
    $nid = (int)($_POST['node_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $allowed = ['new','accepted','deferred','rejected','active','done'];
    if ($nid && in_array($status, $allowed, true)) {
      $st = $pdo->prepare('UPDATE nodes SET status=? WHERE id=?');
      $st->execute([$status, $nid]);
      flash_set('Status updated.', 'info');
      header('Location: /?id=' . $nid);
      exit;
    }
    flash_set('Invalid status.', 'err');
  }
}

// Load full tree for nav
$allNodes = $pdo->query("SELECT id, parent_id, title, type, status, priority FROM nodes ORDER BY COALESCE(priority,999), id")->fetchAll();
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

    if ($hasKids) {
      $forceOpenAll = (!empty($_GET['open']) && $_GET['open'] === 'all');
      $isOpen = $forceOpenAll || ($open[$id] ?? $isActive);
      echo '<details class="tree-branch" ' . ($isOpen ? 'open' : '') . ' style="margin-left:' . $indent . 'px">';
      $shade = max(0, min(4, $depth));
      // gold -> white gradient by depth
      $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

      echo '<summary class="tree-item ' . ($isActive ? 'active' : '') . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num . ' ') . '</span>'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      echo '<span class="tag" style="margin-left:auto">' . h($n['status']) . '</span>';
      echo '</summary>';
      renderTree($byParent, $open, $currentId, $id, $depth+1, $numParts);
      echo '</details>';
    } else {
      echo '<div class="tree-leaf" style="margin-left:' . $indent . 'px">';
      $shade = max(0, min(4, $depth));
      // gold -> white gradient by depth
      $col = ['#d4af37','#f2d98a','#f6e7b9','#fbf3dc','#e8eefc'][$shade];

      echo '<div class="tree-item ' . ($isActive ? 'active' : '') . '">';
      echo '<a href="/?id=' . $id . '" style="display:flex;align-items:center;gap:0;">'
        . '<span style="color:' . $col . ';">' . h($num . ' ') . '</span>'
        . '<span style="color:' . $col . ';">' . h($title) . h($countTxt) . '</span>'
        . '</a>';
      echo '<span class="tag" style="margin-left:auto">' . h($n['status']) . '</span>';
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

  $st = $pdo->prepare('SELECT id,title,type,status,priority FROM nodes WHERE parent_id=? ORDER BY COALESCE(priority,999), id');
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
        <div class="row" style="justify-content:space-between">
          <h2 style="margin:0;"><?php echo h($node['title']); ?></h2>
          <span class="tag gold"><?php echo h($node['status']); ?></span>
        </div>
        <div class="meta">#<?php echo (int)$node['id']; ?> • <?php echo h($node['type']); ?> • created_by=<?php echo h($node['created_by']); ?></div>

        <div class="row" style="margin-top:10px">
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="node_id" value="<?php echo (int)$node['id']; ?>">
            <button class="btn" name="status" value="accepted">annehmen</button>
            <button class="btn" name="status" value="deferred">zurückstellen</button>
            <button class="btn" name="status" value="rejected">ablehnen</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <h2>Subnodes</h2>
        <?php if (!$children): ?>
          <div class="muted">Keine Unterideen.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr><th>Title</th><th>Type</th><th>Status</th><th>Priority</th></tr>
            </thead>
            <tbody>
              <?php foreach ($children as $c): ?>
                <tr>
                  <td><a href="/?id=<?php echo (int)$c['id']; ?>"><?php echo h($c['title']); ?></a></td>
                  <td class="meta"><?php echo h($c['type']); ?></td>
                  <td><span class="tag"><?php echo h($c['status']); ?></span></td>
                  <td class="meta"><?php echo $c['priority'] ? 'P'.(int)$c['priority'] : '-'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <details style="margin-top:12px">
          <summary class="btn">+ Unteridee hinzufügen</summary>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="action" value="add_child">
            <input type="hidden" name="parent_id" value="<?php echo (int)$node['id']; ?>">

            <label>Titel</label>
            <input name="title" required>

            <div class="row">
              <div style="flex:1; min-width:180px">
                <label>Type</label>
                <select name="type">
                  <option value="idea">idea</option>
                  <option value="project">project</option>
                  <option value="task">task</option>
                  <option value="research">research</option>
                </select>
              </div>
              <div style="flex:1; min-width:140px">
                <label>Priority</label>
                <input name="priority" type="number" min="1" max="5" placeholder="1-5">
              </div>
            </div>

            <div style="margin-top:12px">
              <button class="btn btn-gold" type="submit">Create</button>
            </div>
          </form>
        </details>
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

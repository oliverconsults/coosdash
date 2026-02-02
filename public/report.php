<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

$pdo = db();

// view selection (persisted by cookie in index.php too, but report.php stands alone)
$view = 'report';
@setcookie('coos_view', $view, time() + 60*60*24*180, '/');

// Ensure reports table exists
require_once __DIR__ . '/../scripts/migrate_project_reports.php';

// Very small HTML sanitizer for LLM output (allow basic formatting, remove scripts/styles/on* attrs).
function report_sanitize_html(string $html): string {
  $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
  $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
  $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
  $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $html);
  return (string)$html;
}

// --- ensure prompt exists ---
$defaultProjectReportPrompt = "# COOS Projekt-Report\n\n"
  . "Du erstellst einen kompakten HTML-Report (kein Markdown) für Oliver.\n\n"
  . "Input:\n"
  . "- Projekt: {PROJECT_TITLE} (#{PROJECT_NODE_ID})\n"
  . "- Slug: {PROJECT_SLUG}\n"
  . "- Timestamp: {TS}\n"
  . "- Projektstart (Root created_at): {PROJECT_ROOT_CREATED_AT}\n\n"
  . "Daten (Tree + Status):\n"
  . "{PROJECT_TREE}\n\n"
  . "Daten (Stats):\n"
  . "{PROJECT_STATS}\n\n"
  . "Daten (Artefakte/Attachments im Projekt):\n"
  . "{PROJECT_ATTACHMENTS}\n\n"
  . "Output (NUR HTML, ohne ```):\n"
  . "- Starte mit <div class=\"report\"> ... </div>\n"
  . "- Nutze nur leichtes HTML: h2/h3/p/ul/li/strong/em/code/pre/table/tr/th/td/hr\n"
  . "- Schreibe die Report-Überschrift selbst als <h2>coos.eu Projektreport (Projekt / Slug) vom TS</h2>\n"
  . "- Ignoriere QC-Blocker: Wenn Blocker/Abhängigkeiten NUR aus dem QC-Teilbaum kommen, nicht erwähnen.\n"
  . "- Berichte explizit über Artefakte: Liste relevante Attachments + kurze Bedeutung.\n"
  . "- Keine externen Links außer coos.eu / t.coos.eu\n";

if (!is_file(prompts_path())) {
  prompt_set('project_report_prompt', $defaultProjectReportPrompt);
} else {
  $all = prompts_load();
  if (!isset($all['project_report_prompt']) || !is_string($all['project_report_prompt']) || trim((string)$all['project_report_prompt']) === '') {
    prompt_set('project_report_prompt', $defaultProjectReportPrompt);
  }
}

// current selection
$nodeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// find Projekte root
$projectsRootId = (int)($pdo->query("SELECT id FROM nodes WHERE parent_id IS NULL AND title='Projekte' LIMIT 1")->fetchColumn() ?: 0);

// find active project id from current node (if inside Projekte)
$activeProjectId = 0;
if ($projectsRootId > 0 && $nodeId > 0) {
  $cur = $nodeId;
  for ($i=0; $i<200; $i++) {
    $st = $pdo->prepare('SELECT id,parent_id FROM nodes WHERE id=?');
    $st->execute([$cur]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) break;
    $pid = $r['parent_id'];
    if ($pid === null) break;
    $pid = (int)$pid;
    if ($pid === $projectsRootId) { $activeProjectId = (int)$r['id']; break; }
    $cur = $pid;
  }
}

// selected project (explicit param wins)
$selectedProjectId = isset($_REQUEST['project_id']) ? (int)$_REQUEST['project_id'] : 0;
if ($selectedProjectId <= 0) $selectedProjectId = $activeProjectId;

// Load projects list (top-level children of Projekte)
$projects = [];
if ($projectsRootId > 0) {
  $st = $pdo->prepare('SELECT id,title FROM nodes WHERE parent_id=? ORDER BY id DESC');
  $st->execute([$projectsRootId]);
  $projects = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Slug lookup
$slugByProjectId = [];
try {
  projects_migrate($pdo);
  $st = $pdo->query('SELECT node_id,slug FROM projects');
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $slugByProjectId[(int)$r['node_id']] = (string)$r['slug'];
} catch (Throwable $e) {
  $slugByProjectId = [];
}

// Helpers
$isBlocked = function(array $n) use ($pdo): bool {
  $bu = (string)($n['blocked_until'] ?? '');
  $bb = (int)($n['blocked_by_node_id'] ?? 0);
  if ($bu !== '' && strtotime($bu) && strtotime($bu) > time()) return true;
  if ($bb > 0) {
    $st = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
    $st->execute([$bb]);
    $ws = (string)($st->fetchColumn() ?: '');
    return $ws !== 'done';
  }
  return false;
};

$buildTreeText = function(int $rootId) use ($pdo, $isBlocked): string {
  if ($rootId <= 0) return '';
  $rows = $pdo->query('SELECT id,parent_id,title,worker_status,blocked_until,blocked_by_node_id,updated_at FROM nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
  $byParent = [];
  $byId = [];
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    $byParent[$pid][] = $id;
    $byId[$id] = $r;
  }

  $out = [];
  $walk = function(int $id, int $depth) use (&$walk, &$out, $byParent, $byId, $isBlocked) {
    $n = $byId[$id] ?? null;
    if (!$n) return;
    $ws = (string)($n['worker_status'] ?? '');
    $blocked = ($ws !== 'done' && $isBlocked($n)) ? ' BLOCKED' : '';
    $pad = str_repeat('  ', max(0,$depth));
    $out[] = $pad . '- #' . (int)$id . ' [' . $ws . $blocked . '] ' . (string)($n['title'] ?? '');
    foreach (($byParent[$id] ?? []) as $cid) $walk((int)$cid, $depth+1);
  };
  $walk($rootId, 0);
  return implode("\n", $out);
};

$buildAttachmentsText = function(int $rootId) use ($pdo): string {
  if ($rootId <= 0) return '';

  // collect subtree node ids
  $rows = $pdo->query('SELECT id,parent_id FROM nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
  $byParent = [];
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    $byParent[$pid][] = $id;
  }

  $ids = [];
  $stack = [$rootId];
  while ($stack) {
    $id = array_pop($stack);
    $ids[$id] = true;
    foreach (($byParent[$id] ?? []) as $cid) $stack[] = (int)$cid;
  }

  if (!$ids) return '';

  // fetch attachments
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT node_id, token, stored_name, orig_name, created_at FROM node_attachments WHERE node_id IN ($in) ORDER BY created_at DESC, id DESC LIMIT 80");
  $st->execute(array_keys($ids));
  $atts = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$atts) return '—';

  $out = [];
  foreach ($atts as $a) {
    $nid = (int)($a['node_id'] ?? 0);
    $tok = (string)($a['token'] ?? '');
    $fn = (string)($a['stored_name'] ?? '');
    $orig = (string)($a['orig_name'] ?? $fn);
    if ($tok === '' || $fn === '') continue;
    $url = '/att/' . $tok . '/' . $fn;
    $out[] = '- #' . $nid . ' · ' . $orig . ' (' . $url . ')';
  }
  return $out ? implode("\n", $out) : '—';
};

$buildStatsText = function(int $rootId) use ($pdo, $isBlocked): string {
  if ($rootId <= 0) return '';
  $rows = $pdo->query('SELECT id,parent_id,worker_status,blocked_until,blocked_by_node_id,token_in,token_out,worktime FROM nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
  $byParent = [];
  $byId = [];
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $pid = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    $byParent[$pid][] = $id;
    $byId[$id] = $r;
  }

  $todoJ=0;$todoO=0;$done=0;$blocked=0;
  $tokIn=0;$tokOut=0;$wt=0;

  $stack = [$rootId];
  while ($stack) {
    $id = array_pop($stack);
    $n = $byId[$id] ?? null;
    if ($n) {
      $ws = (string)($n['worker_status'] ?? '');
      if ($ws === 'todo_james') $todoJ++;
      if ($ws === 'todo_oliver') $todoO++;
      if ($ws === 'done') $done++;
      if ($ws !== 'done' && $isBlocked($n)) $blocked++;
      $tokIn += (int)($n['token_in'] ?? 0);
      $tokOut += (int)($n['token_out'] ?? 0);
      $wt += (int)($n['worktime'] ?? 0);
    }
    foreach (($byParent[$id] ?? []) as $cid) $stack[] = (int)$cid;
  }

  $h = intdiv($wt,3600); $m=intdiv($wt%3600,60); $s=$wt%60;
  $wtTxt = sprintf('%d:%02d:%02d', $h,$m,$s);

  return "- todo_james: {$todoJ}\n- todo_oliver: {$todoO}\n- blocked: {$blocked}\n- done: {$done}\n- token_in/out/all: {$tokIn}/{$tokOut}/" . ($tokIn+$tokOut) . "\n- worktime: {$wtTxt}";
};

// Create report action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_report') {
  $pid = (int)($_POST['project_id'] ?? 0);
  if ($pid <= 0) {
    flash_set('Bitte Projekt wählen.', 'err');
    header('Location: /report.php');
    exit;
  }

  $st = $pdo->prepare('SELECT id,title FROM nodes WHERE id=?');
  $st->execute([$pid]);
  $proj = $st->fetch(PDO::FETCH_ASSOC);
  if (!$proj) {
    flash_set('Projekt nicht gefunden.', 'err');
    header('Location: /report.php');
    exit;
  }

  $slug = (string)($slugByProjectId[$pid] ?? '');
  $title = (string)($proj['title'] ?? '');

  // Block duplicate report runs for the same project (only one pending/open at a time)
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM project_reports WHERE project_node_id=? AND status='pending'");
    $st->execute([$pid]);
    $pending = (int)($st->fetchColumn() ?: 0);
    if ($pending > 0) {
      flash_set('Für dieses Projekt läuft bereits ein Report (pending). Bitte warten.', 'err');
      header('Location: /report.php?project_id=' . $pid);
      exit;
    }
  } catch (Throwable $e) {
    // ignore
  }
  try {
    // also guard against duplicates in worker_queue (open/claimed)
    $st = $pdo->prepare("SELECT COUNT(*) FROM worker_queue WHERE status IN ('open','claimed') AND selector_meta LIKE ?");
    $needle = '%"type":"project_report"%"project_id":' . $pid . '%';
    $st->execute([$needle]);
    $q = (int)($st->fetchColumn() ?: 0);
    if ($q > 0) {
      flash_set('Für dieses Projekt ist bereits ein Report in der Queue. Bitte warten.', 'err');
      header('Location: /report.php?project_id=' . $pid);
      exit;
    }
  } catch (Throwable $e) {
    // ignore
  }

  $reqId = 'project_report_' . date('Ymd_His') . '_p' . $pid;

  // Create report row first
  $pdo->prepare('INSERT INTO project_reports (project_node_id, slug, project_title, status, req_id) VALUES (?,?,?,?,?)')
      ->execute([$pid, $slug, $title, 'pending', $reqId]);
  $reportId = (int)$pdo->lastInsertId();

  $ts = date('d.m.Y H:i:s');

  // root created_at (project start)
  $st = $pdo->prepare('SELECT created_at FROM nodes WHERE id=?');
  $st->execute([$pid]);
  $rootCreatedAt = (string)($st->fetchColumn() ?: '');

  $tree = $buildTreeText($pid);
  $stats = $buildStatsText($pid);
  $attsTxt = $buildAttachmentsText($pid);

  $tpl = prompt_require('project_report_prompt');
  $prompt = str_replace(
    ['{PROJECT_TITLE}','{PROJECT_NODE_ID}','{PROJECT_SLUG}','{TS}','{PROJECT_ROOT_CREATED_AT}','{PROJECT_TREE}','{PROJECT_STATS}','{PROJECT_ATTACHMENTS}'],
    [$title, (string)$pid, $slug, $ts, $rootCreatedAt, $tree, $stats, $attsTxt],
    $tpl
  );

  // write prompt file
  $llmDir = '/var/www/coosdash/shared/llm';
  @mkdir($llmDir, 0775, true);
  $promptFile = $reqId . '_prompt.txt';
  @file_put_contents($llmDir . '/' . $promptFile, $prompt);

  $pdo->prepare('UPDATE project_reports SET prompt_file=? WHERE id=?')->execute([$promptFile, $reportId]);

  // Enqueue a worker job (no wrapper; handled in worker_main)
  require_once __DIR__ . '/../scripts/migrate_worker_queue.php';
  $pdo->prepare("INSERT INTO worker_queue (status,node_id,prompt_text,selector_meta) VALUES ('open', ?, ?, ?)")
      ->execute([$pid, $prompt, json_encode(['type'=>'project_report','req_id'=>$reqId,'report_id'=>$reportId,'project_id'=>$pid], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

  flash_set('Report wird erstellt…', 'info');
  header('Location: /report.php?project_id=' . $pid . '&report_id=' . $reportId);
  exit;
}

// Fetch current report (if any)
$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$currentReport = null;
if ($reportId > 0) {
  $st = $pdo->prepare('SELECT * FROM project_reports WHERE id=?');
  $st->execute([$reportId]);
  $currentReport = $st->fetch(PDO::FETCH_ASSOC);
}

// List all reports
$reports = [];
try {
  $st = $pdo->query('SELECT id, project_node_id, project_title, slug, status, created_at, generated_at, html_file FROM project_reports ORDER BY id DESC LIMIT 200');
  $reports = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $reports = [];
}

renderHeader('Report');
?>

<div class="grid">
  <div>
    <?php require __DIR__ . '/left_nav.inc.php'; ?>
  </div>

  <div>
    <div class="card">
      <div class="row" style="justify-content:space-between; align-items:center;">
        <h2 style="margin:0;">Report</h2>
      </div>

      <div style="height:8px"></div>

      <form method="post" action="/report.php" class="row" style="gap:10px; align-items:center;">
        <input type="hidden" name="action" value="create_report">
        <label style="margin:0;">Report:</label>
        <select name="project_id" style="min-width:260px;">
          <option value="0">Bitte wählen</option>
          <?php foreach ($projects as $p): ?>
            <?php $pid = (int)($p['id'] ?? 0); $pt = (string)($p['title'] ?? ''); ?>
            <option value="<?php echo $pid; ?>" <?php echo ($pid === (int)$selectedProjectId) ? 'selected' : ''; ?>><?php echo h($pt . ' (#' . $pid . ')'); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-gold" type="submit">Live Report erstellen</button>
      </form>

      <div style="height:14px"></div>

      <div id="liveBox">
        <?php if ($currentReport): ?>
          <?php
            $pt = (string)($currentReport['project_title'] ?? '');
            $slug = (string)($currentReport['slug'] ?? '');
            $ts = (string)($currentReport['generated_at'] ?? $currentReport['created_at'] ?? '');
            $hdr = 'coos.eu Projektreport (' . $pt . ($slug!=='' ? (' / ' . $slug) : '') . ') vom ' . ($ts ? date('d.m.Y H:i:s', strtotime($ts)) : '');
          ?>
          <h3 style="margin:0 0 8px 0;"><?php echo h($hdr); ?></h3>
          <?php if ((string)($currentReport['status'] ?? '') !== 'done'): ?>
            <div class="meta">Status: <?php echo h((string)$currentReport['status']); ?> (live…)</div>
          <?php else: ?>
            <?php
              $file = (string)($currentReport['html_file'] ?? '');
              $html = '';
              if ($file !== '') {
                $p = '/var/www/coosdash/shared/reports/' . basename($file);
                if (is_file($p)) $html = (string)@file_get_contents($p);
              }
              if ($html === '') $html = '<div class="meta">(leer)</div>';
              $html = report_sanitize_html($html);
            ?>
            <div class="card" style="margin-top:10px; background:rgba(5,7,11,.28);">
              <?php echo $html; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="meta">Noch kein Report gewählt.</div>
        <?php endif; ?>
      </div>
    </div>

    <div style="height:16px"></div>

    <div class="card">
      <h2 style="margin:0 0 10px 0;">Vorhandene Reports</h2>
      <?php if (empty($reports)): ?>
        <div class="meta">Keine Reports vorhanden.</div>
      <?php else: ?>
        <ul style="margin:0; padding-left:18px; font-size:13px;">
          <?php foreach ($reports as $r): ?>
            <?php
              $rid = (int)($r['id'] ?? 0);
              $pt = (string)($r['project_title'] ?? '');
              $slug = (string)($r['slug'] ?? '');
              $ts = (string)($r['generated_at'] ?? $r['created_at'] ?? '');
              $tsTxt = $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '';
              $st = (string)($r['status'] ?? '');
            ?>
            <li>
              <a href="/report.php?project_id=<?php echo (int)($r['project_node_id'] ?? 0); ?>&report_id=<?php echo $rid; ?>"><?php echo h($pt); ?></a>
              <?php if ($slug !== ''): ?><span class="meta">/<?php echo h($slug); ?></span><?php endif; ?>
              <span class="meta">· <?php echo h($tsTxt); ?> · <?php echo h($st); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($currentReport && (string)($currentReport['status'] ?? '') !== 'done'): ?>
<script>
(function(){
  const reportId = <?php echo (int)$reportId; ?>;
  async function poll(){
    try {
      const res = await fetch('/report_status.php?report_id=' + encodeURIComponent(reportId), {credentials:'same-origin'});
      const j = await res.json();
      if (j && j.ok && j.status === 'done') {
        location.reload();
        return;
      }
    } catch(e) {}
    setTimeout(poll, 3000);
  }
  setTimeout(poll, 2500);
})();
</script>
<?php endif; ?>

<?php renderFooter();

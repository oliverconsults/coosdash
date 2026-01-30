<?php
// Worker API (server-side writes) – to keep LLM away from raw SQL.
// Used by the upcoming queue consumer.

require_once __DIR__ . '/functions_v3.inc.php';

$pdo = db();
$action = (string)($_REQUEST['action'] ?? '');
$nodeId = (int)($_REQUEST['node_id'] ?? 0);
$jobId = (int)($_REQUEST['job_id'] ?? 0);

// Security: allow only CLI or localhost.
// This endpoint must not be usable from the public internet.
$isCli = (PHP_SAPI === 'cli');
$ra = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal = in_array($ra, ['127.0.0.1','::1'], true);
if (!$isCli && !$isLocal) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Forbidden";
  exit;
}

function out(bool $ok, string $msg, array $extra=[]): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function mustNode(PDO $pdo, int $nodeId): array {
  $st = $pdo->prepare('SELECT * FROM nodes WHERE id=?');
  $st->execute([$nodeId]);
  $n = $st->fetch();
  if (!$n) out(false, 'node not found');
  return $n;
}

function prependDesc(PDO $pdo, int $nodeId, string $text): void {
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')
      ->execute([$text, $nodeId]);
}

function logLine(string $line): void {
  $p = '/var/www/coosdash/shared/logs/worker.log';
  @mkdir(dirname($p), 0775, true);
  file_put_contents($p, $line . "\n", FILE_APPEND);
}

if ($action === 'ping') out(true, 'pong');

// Job helpers
if ($action === 'job_claim') {
  if ($jobId <= 0) out(false, 'missing job_id');
  $who = trim((string)($_REQUEST['claimed_by'] ?? 'james-worker'));
  // atomic claim
  $st = $pdo->prepare("UPDATE worker_queue SET status='claimed', claimed_by=?, claimed_at=NOW() WHERE id=? AND status='open'");
  $st->execute([$who, $jobId]);
  if ($st->rowCount() !== 1) out(false, 'job not claimable');

  $st = $pdo->prepare('SELECT id,status,node_id,prompt_text,selector_meta,fail_count FROM worker_queue WHERE id=?');
  $st->execute([$jobId]);
  $job = $st->fetch();
  out(true, 'claimed', ['job'=>$job]);
}

if ($action === 'job_claim_next') {
  $who = trim((string)($_REQUEST['claimed_by'] ?? 'james-worker'));

  // claim oldest open job
  $st = $pdo->prepare("SELECT id FROM worker_queue WHERE status='open' ORDER BY created_at ASC, id ASC LIMIT 1");
  $st->execute();
  $r = $st->fetch();
  if (!$r) out(true, 'no job', ['job'=>null]);
  $jid = (int)$r['id'];

  $st = $pdo->prepare("UPDATE worker_queue SET status='claimed', claimed_by=?, claimed_at=NOW() WHERE id=? AND status='open'");
  $st->execute([$who, $jid]);
  if ($st->rowCount() !== 1) {
    // race: someone else claimed; caller can retry next tick
    out(true, 'no job', ['job'=>null]);
  }

  $st = $pdo->prepare('SELECT id,status,node_id,prompt_text,selector_meta,fail_count FROM worker_queue WHERE id=?');
  $st->execute([$jid]);
  $job = $st->fetch();
  out(true, 'claimed', ['job'=>$job]);
}

if ($action === 'job_done') {
  if ($jobId <= 0) out(false, 'missing job_id');
  $pdo->prepare("UPDATE worker_queue SET status='done', done_at=NOW() WHERE id=? AND status IN ('open','claimed')")->execute([$jobId]);
  out(true, 'job done');
}

if ($action === 'job_fail') {
  if ($jobId <= 0) out(false, 'missing job_id');
  $reason = trim((string)($_REQUEST['reason'] ?? ''));

  // increment fail_count
  $pdo->prepare("UPDATE worker_queue SET status='failed', fail_count=fail_count+1 WHERE id=?")->execute([$jobId]);

  $st = $pdo->prepare('SELECT node_id, fail_count FROM worker_queue WHERE id=?');
  $st->execute([$jobId]);
  $j = $st->fetch();
  $nid = $j ? (int)$j['node_id'] : 0;
  $fc = $j ? (int)$j['fail_count'] : 0;

  // after 3 fails: block node (shows up in BLOCKED column)
  if ($nid > 0 && $fc >= 3) {
    $until = date('Y-m-d H:i:00', time() + 24*3600);
    $pdo->prepare('UPDATE nodes SET blocked_until=? WHERE id=?')->execute([$until, $nid]);
    $tsH = date('d.m.Y H:i');
    $line = "[auto] {$tsH} Blocked nach {$fc} Fails";
    if ($reason !== '') $line .= ": {$reason}";
    $line .= "\n\n";
    prependDesc($pdo, $nid, $line);
    logLine(date('Y-m-d H:i:s') . "  #{$nid}  {$line}");

    $pdo->prepare("UPDATE worker_queue SET status='blocked' WHERE id=?")->execute([$jobId]);
  }

  out(true, 'job failed', ['reason'=>$reason,'fail_count'=>$fc,'node_id'=>$nid]);
}

// Node ops
if ($nodeId <= 0) out(false, 'missing node_id');
$node = mustNode($pdo, $nodeId);

$ts = date('d.m.Y H:i');
$tsLog = date('Y-m-d H:i:s');

if ($action === 'prepend_update') {
  $headline = trim((string)($_REQUEST['headline'] ?? 'Update'));
  $body = trim((string)($_REQUEST['body'] ?? ''));
  $txt = "[james] {$ts} Update: {$headline}\n\n";
  if ($body !== '') $txt .= $body . "\n\n";
  prependDesc($pdo, $nodeId, $txt);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Update: {$headline}");
  out(true, 'prepended');
}

if ($action === 'set_status') {
  $st = (string)($_REQUEST['worker_status'] ?? '');
  $allowed = ['todo_james','todo_oliver','done'];
  if (!in_array($st, $allowed, true)) out(false, 'invalid worker_status');
  $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$st, $nodeId]);
  $line = "[james] {$ts} Statusänderung: {$st}\n\n";
  prependDesc($pdo, $nodeId, $line);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Statusänderung: {$st}");
  out(true, 'status set');
}

if ($action === 'set_blocked_by') {
  $bid = (int)($_REQUEST['blocked_by_node_id'] ?? 0);
  if ($bid <= 0 || $bid === $nodeId) out(false, 'invalid blocked_by_node_id');
  $st = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
  $st->execute([$bid]);
  if (!$st->fetch()) out(false, 'blocker not found');

  $pdo->prepare('UPDATE nodes SET blocked_by_node_id=? WHERE id=?')->execute([$bid, $nodeId]);
  $line = "[james] {$ts} Blocker: wartet auf #{$bid}\n\n";
  prependDesc($pdo, $nodeId, $line);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Blocker: wartet auf #{$bid}");
  out(true, 'blocked_by set');
}

if ($action === 'set_blocked_until') {
  $raw = trim((string)($_REQUEST['blocked_until'] ?? ''));
  if ($raw === '') out(false, 'missing blocked_until');
  $raw2 = str_replace('T',' ',$raw);
  $dt = DateTime::createFromFormat('Y-m-d H:i', $raw2) ?: DateTime::createFromFormat('Y-m-d H:i:s', $raw2);
  if (!$dt) out(false, 'invalid datetime');
  $val = $dt->format('Y-m-d H:i:00');

  $pdo->prepare('UPDATE nodes SET blocked_until=? WHERE id=?')->execute([$val, $nodeId]);
  $line = "[james] {$ts} Blocker: bis " . $dt->format('d.m.Y H:i') . "\n\n";
  prependDesc($pdo, $nodeId, $line);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Blocker: bis " . $dt->format('d.m.Y H:i'));
  out(true, 'blocked_until set');
}

if ($action === 'add_children') {
  $titlesRaw = (string)($_REQUEST['titles'] ?? '');
  $titles = array_values(array_filter(array_map('trim', preg_split('/\R/', $titlesRaw))));
  if (!$titles) out(false, 'missing titles');
  if (count($titles) > 6) out(false, 'too many children (max 6)');

  $newIds = [];
  foreach ($titles as $t) {
    if (mb_strlen($t) > 40) $t = mb_substr($t, 0, 40);
    $pdo->prepare('INSERT INTO nodes (parent_id, title, description, priority, created_by, worker_status) VALUES (?,?,?,?,?,?)')
        ->execute([$nodeId, $t, "[james] {$ts} Statusänderung: todo\n\n", null, 'james', 'todo_james']);
    $newIds[] = (int)$pdo->lastInsertId();
  }
  $line = "[james] {$ts} Zerlegt: " . count($newIds) . " Subtasks angelegt\n\n";
  prependDesc($pdo, $nodeId, $line);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Zerlegt: " . count($newIds) . " Subtasks angelegt");
  out(true, 'children added', ['new_ids'=>$newIds]);
}

if ($action === 'add_attachment') {
  // Attach local file via existing helper script (respects allowlist)
  $path = trim((string)($_REQUEST['path'] ?? ''));
  $name = trim((string)($_REQUEST['display_name'] ?? ''));
  if ($path === '') out(false, 'missing path');
  $cmd = 'php ' . escapeshellarg(__DIR__ . '/../scripts/add_attachment.php') . ' ' . escapeshellarg((string)$nodeId) . ' ' . escapeshellarg($path);
  if ($name !== '') $cmd .= ' ' . escapeshellarg($name);
  $outLines = [];
  $rc = 0;
  exec($cmd . ' 2>&1', $outLines, $rc);
  if ($rc !== 0) out(false, 'add_attachment failed', ['output'=>implode("\n", $outLines)]);
  out(true, 'attached', ['output'=>implode("\n", $outLines)]);
}

out(false, 'unknown action');

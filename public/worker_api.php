<?php
// Worker API (server-side writes) – to keep LLM away from raw SQL.
// Used by the upcoming queue consumer.

require_once __DIR__ . '/functions_v3.inc.php';
require_once __DIR__ . '/../scripts/_clawdbot_usage_lookup.php';

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

// Safely fetch text params from CLI/HTTP.
// Supports <key>_b64 (base64) to avoid shell/encoding issues.
function getTextParam(string $key, string $default=''): string {
  $b64Key = $key . '_b64';
  $raw = null;
  if (isset($_REQUEST[$b64Key]) && (string)$_REQUEST[$b64Key] !== '') {
    $dec = base64_decode((string)$_REQUEST[$b64Key], true);
    if ($dec !== false) $raw = $dec;
  }
  if ($raw === null && isset($_REQUEST[$key])) {
    $raw = (string)$_REQUEST[$key];
  }
  if ($raw === null) $raw = $default;

  // Strip problematic ASCII control chars (keep \n, \r, \t)
  $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw);
  return (string)$raw;
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

  // optional metrics (accumulated on nodes)
  $tokIn = (int)($_REQUEST['token_in'] ?? 0);
  $tokOut = (int)($_REQUEST['token_out'] ?? 0);
  $wt = $_REQUEST['worktime'] ?? null; // seconds; if omitted -> compute from claimed_at

  // If worker didn't pass tokens, try to infer from Clawdbot session logs (best effort).
  $sessionId = trim((string)($_REQUEST['session_id'] ?? 'coos-worker-queue'));

  if ($tokIn <= 0 && $tokOut <= 0) {
    $u = clawdbot_find_usage_for_job($jobId, 'job_done', $sessionId);
    if ($u) {
      $tokIn = (int)($u['input'] ?? 0);
      $tokOut = (int)($u['output'] ?? 0);
      $_REQUEST['llm_calls'] = (int)($u['calls'] ?? 0);
    }
  }

  $pdo->prepare("UPDATE worker_queue SET status='done', done_at=NOW() WHERE id=? AND status IN ('open','claimed')")->execute([$jobId]);

  // Optional: record additional LLM metadata + logfile
  $llmCalls = (int)($_REQUEST['llm_calls'] ?? 0);
  try {
    $st = $pdo->prepare('SELECT node_id, claimed_at FROM worker_queue WHERE id=?');
    $st->execute([$jobId]);
    $jr = $st->fetch(PDO::FETCH_ASSOC);
    $nid = $jr ? (int)($jr['node_id'] ?? 0) : 0;
    $claimedAt = $jr ? (string)($jr['claimed_at'] ?? '') : '';

    $wtSec = null;
    if ($claimedAt !== '') {
      $st2 = $pdo->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, ?, NOW())) AS s');
      $st2->execute([$claimedAt]);
      $wtSec = (int)$st2->fetchColumn();
    }

    $p = '/var/www/coosdash/shared/logs/worker_llm_usage.log';
    @mkdir(dirname($p), 0775, true);
    $wtPart = ($wtSec !== null) ? (' worktime=' . $wtSec . 's') : '';
    @file_put_contents($p, date('Y-m-d H:i:s') . "  job={$jobId} node={$nid} llm_calls={$llmCalls} token_in={$tokIn} token_out={$tokOut}{$wtPart} action=job_done session={$sessionId}\n", FILE_APPEND);
  } catch (Throwable $e) {
    // ignore
  }

  // Add to node metrics (if columns exist)
  try {
    if ($wt === null || $wt === '') {
      // compute elapsed seconds since claim
      $sql = "UPDATE nodes n JOIN worker_queue q ON q.node_id=n.id " .
             "SET n.token_in=n.token_in+?, n.token_out=n.token_out+?, n.worktime=n.worktime+GREATEST(0, TIMESTAMPDIFF(SECOND, q.claimed_at, NOW())) " .
             "WHERE q.id=?";
      $pdo->prepare($sql)->execute([$tokIn, $tokOut, $jobId]);
    } else {
      $wtI = max(0, (int)$wt);
      $sql = "UPDATE nodes n JOIN worker_queue q ON q.node_id=n.id " .
             "SET n.token_in=n.token_in+?, n.token_out=n.token_out+?, n.worktime=n.worktime+? " .
             "WHERE q.id=?";
      $pdo->prepare($sql)->execute([$tokIn, $tokOut, $wtI, $jobId]);
    }
  } catch (Throwable $e) {
    // keep API robust if schema isn't migrated yet
  }

  out(true, 'job done');
}

if ($action === 'job_fail') {
  if ($jobId <= 0) out(false, 'missing job_id');
  $reason = trim((string)($_REQUEST['reason'] ?? ''));

  // optional metrics (accumulated on nodes)
  $tokIn = (int)($_REQUEST['token_in'] ?? 0);
  $tokOut = (int)($_REQUEST['token_out'] ?? 0);
  $wt = $_REQUEST['worktime'] ?? null;

  // If worker didn't pass tokens, try to infer from Clawdbot session logs (best effort).
  $sessionId = trim((string)($_REQUEST['session_id'] ?? 'coos-worker-queue'));
  if ($tokIn <= 0 && $tokOut <= 0) {
    $u = clawdbot_find_usage_for_job($jobId, 'job_fail', $sessionId);
    if ($u) {
      $tokIn = (int)($u['input'] ?? 0);
      $tokOut = (int)($u['output'] ?? 0);
      $_REQUEST['llm_calls'] = (int)($u['calls'] ?? 0);
    }
  }

  // increment fail_count
  $pdo->prepare("UPDATE worker_queue SET status='failed', fail_count=fail_count+1 WHERE id=?")->execute([$jobId]);

  // Optional: record additional LLM metadata + logfile
  $llmCalls = (int)($_REQUEST['llm_calls'] ?? 0);
  try {
    $st = $pdo->prepare('SELECT node_id, claimed_at FROM worker_queue WHERE id=?');
    $st->execute([$jobId]);
    $jr = $st->fetch(PDO::FETCH_ASSOC);
    $nid = $jr ? (int)($jr['node_id'] ?? 0) : 0;
    $claimedAt = $jr ? (string)($jr['claimed_at'] ?? '') : '';

    $wtSec = null;
    if ($claimedAt !== '') {
      $st2 = $pdo->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, ?, NOW())) AS s');
      $st2->execute([$claimedAt]);
      $wtSec = (int)$st2->fetchColumn();
    }

    $p = '/var/www/coosdash/shared/logs/worker_llm_usage.log';
    @mkdir(dirname($p), 0775, true);
    $wtPart = ($wtSec !== null) ? (' worktime=' . $wtSec . 's') : '';
    $reasonPart = ($reason !== '') ? (' reason=' . str_replace(["\n","\r","\t"],' ', $reason)) : '';
    @file_put_contents($p, date('Y-m-d H:i:s') . "  job={$jobId} node={$nid} llm_calls={$llmCalls} token_in={$tokIn} token_out={$tokOut}{$wtPart} action=job_fail session={$sessionId}{$reasonPart}\n", FILE_APPEND);
  } catch (Throwable $e) {
    // ignore
  }

  // Add to node metrics (if columns exist)
  try {
    if ($wt === null || $wt === '') {
      $sql = "UPDATE nodes n JOIN worker_queue q ON q.node_id=n.id " .
             "SET n.token_in=n.token_in+?, n.token_out=n.token_out+?, n.worktime=n.worktime+GREATEST(0, TIMESTAMPDIFF(SECOND, q.claimed_at, NOW())) " .
             "WHERE q.id=?";
      $pdo->prepare($sql)->execute([$tokIn, $tokOut, $jobId]);
    } else {
      $wtI = max(0, (int)$wt);
      $sql = "UPDATE nodes n JOIN worker_queue q ON q.node_id=n.id " .
             "SET n.token_in=n.token_in+?, n.token_out=n.token_out+?, n.worktime=n.worktime+? " .
             "WHERE q.id=?";
      $pdo->prepare($sql)->execute([$tokIn, $tokOut, $wtI, $jobId]);
    }
  } catch (Throwable $e) {
    // keep API robust if schema isn't migrated yet
  }

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
  $headline = trim(getTextParam('headline', 'Update'));
  $body = trim(getTextParam('body', ''));
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

  // No-op guard: don't spam description/log if unchanged
  $curStatus = (string)($node['worker_status'] ?? '');
  if ($curStatus === $st) {
    out(true, 'status unchanged');
  }

  $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$st, $nodeId]);
  $line = "[james] {$ts} Statusänderung: {$st}\n\n";
  prependDesc($pdo, $nodeId, $line);
  logLine("{$tsLog}  #{$nodeId}  [james] {$ts} Statusänderung: {$st}");
  out(true, 'status set');
}

if ($action === 'set_status_silent') {
  $st = (string)($_REQUEST['worker_status'] ?? '');
  $allowed = ['todo_james','todo_oliver','done'];
  if (!in_array($st, $allowed, true)) out(false, 'invalid worker_status');

  $curStatus = (string)($node['worker_status'] ?? '');
  if ($curStatus === $st) {
    out(true, 'status unchanged');
  }

  $pdo->prepare('UPDATE nodes SET worker_status=? WHERE id=?')->execute([$st, $nodeId]);
  // Do NOT write into node.description (keeps project descriptions clean).
  logLine("{$tsLog}  #{$nodeId}  [auto] {$ts} Statusänderung (silent): {$st}");
  out(true, 'status set (silent)');
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
  // Guardrail: once a node is delegated to Oliver, James must not keep splitting deeper
  if (((string)($node['worker_status'] ?? '')) === 'todo_oliver') {
    out(false, 'node delegated to oliver; add_children not allowed');
  }

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

if ($action === 'cleanup_done_subtree') {
  $summary = trim(getTextParam('summary', ''));
  if ($summary === '') out(false, 'missing summary');

  // Re-load fresh node (avoid stale)
  $node = mustNode($pdo, $nodeId);

  // Collect descendants with depth (BFS)
  $desc = []; // [id=>depth]
  $queue = [[$nodeId, 0]];
  while ($queue) {
    [$cur,$depth] = array_shift($queue);
    $st = $pdo->prepare('SELECT id, worker_status, updated_at FROM nodes WHERE parent_id=?');
    $st->execute([(int)$cur]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k) {
      $kid = (int)$k['id'];
      $d = $depth + 1;
      if (!isset($desc[$kid]) || $desc[$kid] < $d) $desc[$kid] = $d;
      $queue[] = [$kid, $d];
    }
  }

  if (!$desc) out(false, 'no descendants');

  $descIds = array_keys($desc);

  // Guard: do not clean up blocked tasks
  $bu = (string)($node['blocked_until'] ?? '');
  if ($bu !== '' && strtotime($bu) > time()) {
    out(false, 'node is blocked (blocked_until in future)');
  }

  // Guard: all descendants must be done
  $in = implode(',', array_fill(0, count($descIds), '?'));
  $st = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE id IN ($in) AND worker_status <> 'done'");
  $st->execute($descIds);
  $notDone = (int)$st->fetchColumn();
  if ($notDone > 0) out(false, 'descendants not all done');

  // Guard: stability window (all nodes in subtree older than 10 minutes)
  $minTs = date('Y-m-d H:i:s', time() - 10*60);
  $st = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE id IN ($in) AND updated_at >= ?");
  $args = $descIds;
  $args[] = $minTs;
  $st->execute($args);
  $tooFresh = (int)$st->fetchColumn();
  if ($tooFresh > 0) out(false, 'subtree recently updated');

  // Guard: no open/claimed queue job for any node in subtree (or the parent)
  // (ignore the current job_id if provided)
  $idsForJobs = array_merge([$nodeId], $descIds);
  $in2 = implode(',', array_fill(0, count($idsForJobs), '?'));
  $q = "SELECT COUNT(*) FROM worker_queue WHERE node_id IN ($in2) AND status IN ('open','claimed')";
  $args = $idsForJobs;
  if ($jobId > 0) {
    $q .= " AND id <> ?";
    $args[] = $jobId;
  }
  $st = $pdo->prepare($q);
  $st->execute($args);
  $jobOpen = (int)$st->fetchColumn();
  if ($jobOpen > 0) out(false, 'queue job open/claimed for subtree');

  // Move attachments up to parent
  $st = $pdo->prepare("UPDATE node_attachments SET node_id=? WHERE node_id IN ($in)");
  $args = [$nodeId];
  foreach ($descIds as $id) $args[] = $id;
  $st->execute($args);
  $movedAtt = $st->rowCount();

  // Deduplicate attachments on parent (avoid double-uploaded identical files)
  // Strategy: compute sha256 from the stored file and remove duplicates (keep oldest attachment row).
  $dedupRemoved = 0;
  try {
    require_once __DIR__ . '/attachments_lib.php';
    $slug = '';
    try {
      $slug = (string)project_slug_for_node($pdo, $nodeId);
    } catch (Throwable $e) {
      $slug = '';
    }

    $bases = [];
    if ($slug !== '') {
      $bases[] = '/var/www/t/' . $slug . '/shared/artifacts/att';
    }
    $bases[] = '/var/www/coosdash/shared/att';

    $stA = $pdo->prepare('SELECT id, token, stored_name, size_bytes FROM node_attachments WHERE node_id=? ORDER BY id ASC');
    $stA->execute([$nodeId]);
    $atts = $stA->fetchAll(PDO::FETCH_ASSOC);

    $seen = []; // sha256 => keepId
    foreach ($atts as $a) {
      $aid = (int)($a['id'] ?? 0);
      $token = (string)($a['token'] ?? '');
      $stored = (string)($a['stored_name'] ?? '');
      if ($aid <= 0 || $token === '' || $stored === '') continue;

      $path = '';
      foreach ($bases as $b) {
        $p = rtrim($b, '/') . '/' . $token . '/' . $stored;
        if (is_file($p)) { $path = $p; break; }
      }
      if ($path === '') continue;

      $sha = @hash_file('sha256', $path);
      if (!is_string($sha) || $sha === '') continue;

      if (!isset($seen[$sha])) {
        $seen[$sha] = ['id' => $aid, 'token' => $token];
        continue;
      }

      // duplicate found → delete attachment row + file folder
      try {
        $lp = '/var/www/coosdash/shared/logs/worker_dedup.log';
        @mkdir(dirname($lp), 0775, true);
        $tsd = date('Y-m-d H:i:s');
        $keepId = (int)($seen[$sha]['id'] ?? 0);
        $keepToken = (string)($seen[$sha]['token'] ?? '');
        @file_put_contents(
          $lp,
          $tsd . "  #{$nodeId}  dedup sha={$sha} keep_att={$keepId} keep_token={$keepToken} removed_att={$aid} removed_token={$token} file=" . $stored . "\n",
          FILE_APPEND
        );
      } catch (Throwable $e) {
        // ignore
      }

      $pdo->prepare('DELETE FROM node_attachments WHERE id=?')->execute([$aid]);
      $dedupRemoved++;

      // best-effort remove directory (token is unique per upload)
      $dir = dirname($path);
      if (is_dir($dir)) {
        foreach (@scandir($dir) ?: [] as $f) {
          if ($f === '.' || $f === '..') continue;
          @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
      }
    }
  } catch (Throwable $e) {
    // best-effort; cleanup must still succeed
    $dedupRemoved = 0;
  }

  // Roll up metrics from descendants into parent (token_in/token_out/worktime)
  $rolled = ['token_in'=>0,'token_out'=>0,'worktime'=>0];
  try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(token_in),0) AS token_in, COALESCE(SUM(token_out),0) AS token_out, COALESCE(SUM(worktime),0) AS worktime FROM nodes WHERE id IN ($in)");
    $st->execute($descIds);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $rolled['token_in'] = (int)($r['token_in'] ?? 0);
      $rolled['token_out'] = (int)($r['token_out'] ?? 0);
      $rolled['worktime'] = (int)($r['worktime'] ?? 0);
    }

    // Add to parent
    $pdo->prepare('UPDATE nodes SET token_in=token_in+?, token_out=token_out+?, worktime=worktime+? WHERE id=?')
        ->execute([$rolled['token_in'], $rolled['token_out'], $rolled['worktime'], $nodeId]);
  } catch (Throwable $e) {
    // If schema doesn't have these columns yet, or query fails, ignore (cleanup must still work)
  }

  // Delete notes for descendants
  $st = $pdo->prepare("DELETE FROM node_notes WHERE node_id IN ($in)");
  $st->execute($descIds);

  // Delete descendants deepest-first
  arsort($desc); // depth desc
  $deleted = 0;
  foreach (array_keys($desc) as $did) {
    // If other nodes are blocked by this node, clear those refs (avoid dangling blockers)
    $pdo->prepare('UPDATE nodes SET blocked_by_node_id=NULL WHERE blocked_by_node_id=?')->execute([(int)$did]);

    $pdo->prepare('DELETE FROM nodes WHERE id=?')->execute([(int)$did]);
    $deleted += 1;
  }

  // Prepend summary to parent and mark as Umsetzung + todo_james (handoff back to James)
  $ts2 = date('d.m.Y H:i');
  $txt = "[auto] {$ts2} Zusammenfassung ##UMSETZUNG##\n\n" . rtrim($summary) . "\n\n";
  $txt .= "[auto] {$ts2} Cleanup: Attachments hochgezogen={$movedAtt}, Dedupe entfernt={$dedupRemoved}, Nodes geloescht={$deleted}";
  if (($rolled['token_in'] + $rolled['token_out'] + $rolled['worktime']) > 0) {
    $txt .= ", Metrics gerollt (Token in/out=" . (int)$rolled['token_in'] . "/" . (int)$rolled['token_out'] . ", Worktime=" . (int)$rolled['worktime'] . "s)";
  }
  $txt .= "\n\n";

  prependDesc($pdo, $nodeId, $txt);
  // Keep parent worker_status unchanged (Oliver requested). Cleanup should not reopen the task.
  logLine(date('Y-m-d H:i:s') . "  #{$nodeId}  [auto] {$ts2} Cleanup+Summary: moved_att={$movedAtt} dedup_removed={$dedupRemoved} deleted_nodes={$deleted} rolled_in={$rolled['token_in']} rolled_out={$rolled['token_out']} rolled_worktime={$rolled['worktime']}s (status unchanged)");

  out(true, 'cleaned', ['moved_attachments'=>$movedAtt, 'dedup_removed'=>$dedupRemoved, 'deleted_nodes'=>$deleted, 'rolled_metrics'=>$rolled]);
}

out(false, 'unknown action');

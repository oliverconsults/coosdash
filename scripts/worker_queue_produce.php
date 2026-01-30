<?php
// Produce a worker_queue job (open) if none is currently open/claimed.
// Intended to run via Linux cron.

require_once __DIR__ . '/../public/functions.inc.php';

$pdo = db();

// Ensure table exists
require_once __DIR__ . '/migrate_worker_queue.php';

// If there is already an open/claimed job, do nothing
$st = $pdo->query("SELECT COUNT(*) AS c FROM worker_queue WHERE status IN ('open','claimed')");
$row = $st->fetch();
if ($row && (int)$row['c'] > 0) {
  echo "OK skip (job already open/claimed)\n";
  exit(0);
}

// Select next target via existing selector
$selOut = [];
$rc = 0;
exec('php ' . escapeshellarg(__DIR__ . '/james_tick_select.php') . ' 2>/dev/null', $selOut, $rc);
$txt = trim(implode("\n", $selOut));
if ($rc !== 0 || $txt === '' || str_contains($txt, 'NO_TODO')) {
  echo "OK no target\n";
  exit(0);
}

// selector prints: TARGET\n{json}
$lines = preg_split('/\R/', $txt);
$json = '';
for ($i=0; $i<count($lines); $i++) {
  if (trim($lines[$i]) === 'TARGET' && isset($lines[$i+1])) {
    $json = trim(implode("\n", array_slice($lines, $i+1)));
    break;
  }
}
$meta = $json ? json_decode($json, true) : null;
if (!is_array($meta) || empty($meta['id'])) {
  echo "ERR selector parse\n";
  exit(2);
}
$nodeId = (int)$meta['id'];

// Build a compact prompt (final worker prompt, persisted)
$st = $pdo->prepare('SELECT id,parent_id,title,worker_status,blocked_until,blocked_by_node_id,created_at,updated_at FROM nodes WHERE id=?');
$st->execute([$nodeId]);
$node = $st->fetch();
if (!$node) {
  echo "ERR node missing\n";
  exit(3);
}

$blockedUntil = (string)($node['blocked_until'] ?? '');
$blockedBy = (int)($node['blocked_by_node_id'] ?? 0);

// parent chain (up to root)
$chain = [];
$cur = $nodeId;
for ($i=0; $i<40; $i++) {
  $st = $pdo->prepare('SELECT id,parent_id,title FROM nodes WHERE id=?');
  $st->execute([$cur]);
  $r = $st->fetch();
  if (!$r) break;
  $chain[] = '#' . (int)$r['id'] . ' ' . (string)$r['title'];
  if ($r['parent_id'] === null) break;
  $cur = (int)$r['parent_id'];
}
$chain = array_reverse($chain);

$prompt = "# COOS Worker Job (from queue)\n\n";
$prompt .= "JOB_ID={JOB_ID}\n";
$prompt .= "TARGET_NODE_ID={$nodeId}\n";
$prompt .= "TITLE=" . (string)$node['title'] . "\n\n";
$prompt .= "Chain:\n- " . implode("\n- ", $chain) . "\n\n";
$prompt .= "Context:\n";
if ($blockedBy > 0) $prompt .= "- BLOCKED_BY_NODE_ID={$blockedBy}\n";
if ($blockedUntil !== '' && strtotime($blockedUntil)) $prompt .= "- BLOCKED_UNTIL={$blockedUntil}\n";
$prompt .= "\n";

$prompt .= "How to write (MANDATORY):\n";
$prompt .= "- NO raw SQL writes. Use the CLI wrapper: php /home/deploy/projects/coos/scripts/worker_api_cli.php ...\n";
$prompt .= "- Example: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=ping\n";
$prompt .= "\nAllowed actions:\n";
$prompt .= "- prepend_update (headline, body)\n";
$prompt .= "- set_status (worker_status=todo_james|todo_oliver|done)\n";
$prompt .= "- set_blocked_by (blocked_by_node_id)\n";
$prompt .= "- set_blocked_until (blocked_until=YYYY-MM-DD HH:MM)\n";
$prompt .= "- add_children (titles = newline separated, max 6)\n";
$prompt .= "- add_attachment (path, display_name optional)\n";
$prompt .= "- job_done / job_fail (job_id, reason optional)\n";
$prompt .= "\nExpected outcome (pick one):\n";
$prompt .= "A) DONE: prepend_update + add_attachment(s) (if any) + set_status done + job_done\n";
$prompt .= "B) SPLIT: add_children (4–6) + prepend_update (plan) + job_done\n";
$prompt .= "C) BLOCK: set_blocked_until OR set_blocked_by + prepend_update (why) + job_done\n";

$prompt .= "\nAttachment rule:\n";
$prompt .= "- If you generate any file (PDF/CSV/JSON/TXT/etc.): ALWAYS attach it via add_attachment, and reference only the attachment/link in the update (no server paths).\n";
$prompt .= "- If the node already has relevant attachments: mention them briefly as input.\n";

$prompt .= "\nRules:\n";
$prompt .= "- Always verify runs before marking done.\n";
$prompt .= "- If you cannot proceed: call job_fail with a short reason. After 3 fails the system will block it.\n";
$prompt .= "- Tool KB: if you successfully use/install a tool, update /home/deploy/clawd/TOOLS.md + /home/deploy/clawd/tools/<tool>.md (keep it short).\n";

$stIns = $pdo->prepare('INSERT INTO worker_queue (status, node_id, prompt_text, selector_meta) VALUES (\'open\', ?, ?, ?)');
$stIns->execute([$nodeId, $prompt, json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
$jobId = (int)$pdo->lastInsertId();

// replace placeholder in stored prompt
$promptFinal = str_replace('{JOB_ID}', (string)$jobId, $prompt);
$pdo->prepare('UPDATE worker_queue SET prompt_text=? WHERE id=?')->execute([$promptFinal, $jobId]);

echo "OK queued job_id={$jobId} node_id={$nodeId}\n";

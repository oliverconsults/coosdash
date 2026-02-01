<?php
// Produce a worker_queue job (open) if none is currently open/claimed.
// Intended to run via Linux cron.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();

// UI toggle (James sleeps):
// When OFF, we still enqueue the NEXT job (so Oliver can inspect the effective prompt),
// but worker_main will not consume it (no LLM call).
$statePath = '/var/www/coosdash/shared/data/james_state.json';
$enabled = 0;
if (is_file($statePath)) {
  $raw = @file_get_contents($statePath);
  $j = $raw ? json_decode($raw, true) : null;
  if (is_array($j) && !empty($j['enabled'])) $enabled = 1;
}

// Ensure table exists
require_once __DIR__ . '/migrate_worker_queue.php';

// If there is already an open/claimed job, do nothing
$st = $pdo->query("SELECT COUNT(*) AS c FROM worker_queue WHERE status IN ('open','claimed')");
$row = $st->fetch();
if ($row && (int)$row['c'] > 0) {
  echo date('Y-m-d H:i:s') . "  OK skip (job already open/claimed)\n";
  exit(0);
}

// Select next target via existing selector
$selOut = [];
$rc = 0;
exec('php ' . escapeshellarg(__DIR__ . '/james_tick_select.php') . ' 2>/dev/null', $selOut, $rc);
$txt = trim(implode("\n", $selOut));
if ($rc !== 0 || $txt === '' || str_contains($txt, 'NO_TODO')) {
  echo date('Y-m-d H:i:s') . "  OK no target\n";
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
$st = $pdo->prepare('SELECT id,parent_id,title,description,worker_status,blocked_until,blocked_by_node_id,created_at,updated_at FROM nodes WHERE id=?');
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

$prompt = "# COOS Worker Job (aus Queue)\n\n";
$prompt .= "JOB_ID={JOB_ID}\n";
$prompt .= "TARGET_NODE_ID={$nodeId}\n";
$prompt .= "TITLE=" . (string)$node['title'] . "\n\n";

// Task type marker (Umsetzung)
$desc = (string)($node['description'] ?? '');
$isUmsetzung = (strpos($desc, '##UMSETZUNG##') !== false);
if ($isUmsetzung) {
  $prompt .= "AUFGABENTYP=UMSETZUNG (hart)\n";
  $prompt .= "- Erwartung: Endergebnis liefern und abschließen (nicht nur planen).\n";
  $prompt .= "- add_children nur im echten Notfall und nur wenn depth < 8 (kurz begründen).\n\n";
}

$prompt .= "Sprache / Ton (hart):\n";
$prompt .= "- Schreibe komplett auf Deutsch (keine englischen Labels wie SPLIT/DONE/etc.).\n";
$prompt .= "- Sprich Oliver mit 'du' an (kurz, klar, technisch).\n\n";
$prompt .= "Kette (Parent-Chain):\n- " . implode("\n- ", $chain) . "\n\n";
$prompt .= "Kontext:\n";
if ($blockedBy > 0) $prompt .= "- BLOCKED_BY_NODE_ID={$blockedBy}\n";
if ($blockedUntil !== '' && strtotime($blockedUntil)) $prompt .= "- BLOCKED_UNTIL={$blockedUntil}\n";
$prompt .= "\n";

// Node text (description) – required to actually do the job
$nodeText = trim((string)($node['description'] ?? ''));
if ($nodeText !== '') {
  // keep prompt compact
  if (mb_strlen($nodeText) > 3500) $nodeText = mb_substr($nodeText, 0, 3500) . "\n…(truncated)";
  $prompt .= "NODE_TEXT (description):\n";
  $prompt .= $nodeText . "\n\n";
}

// Attachments (list only, keep small)
try {
  $stA = $pdo->prepare('SELECT token, stored_name, orig_name, created_at FROM node_attachments WHERE node_id=? ORDER BY id');
  $stA->execute([$nodeId]);
  $atts = $stA->fetchAll(PDO::FETCH_ASSOC);
  if ($atts) {
    $prompt .= "ATTACHMENTS (read via /att/...):\n";
    $n = 0;
    foreach ($atts as $a) {
      if ($n >= 10) { $prompt .= "- …(more)\n"; break; }
      $tok = (string)($a['token'] ?? '');
      $fn = (string)($a['stored_name'] ?? '');
      if ($tok === '' || $fn === '') continue;
      $orig = (string)($a['orig_name'] ?? $fn);
      $url = '/att/' . $tok . '/' . $fn;
      $prompt .= "- {$orig} ({$url})\n";
      $n++;
    }
    $prompt .= "\n";
  }
} catch (Throwable $e) {
  // ignore
}

// Project env (always include if node is under Projekte)
$env = project_env_text_for_node($pdo, $nodeId);
if ($env !== '') {
  $prompt .= "PROJEKT_UMGEBUNG (immer beachten):\n";
  $prompt .= $env . "\n\n";
}

$prompt .= prompt_require('worker_rules_block');

$prompt .= "\nOperational (English):\n";
$prompt .= "- Quick healthcheck: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=ping\n";

// Regeln are part of worker_rules_block (editable in Setup)

$stIns = $pdo->prepare('INSERT INTO worker_queue (status, node_id, prompt_text, selector_meta) VALUES (\'open\', ?, ?, ?)');
$stIns->execute([$nodeId, $prompt, json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
$jobId = (int)$pdo->lastInsertId();

// replace placeholder in stored prompt
$promptFinal = str_replace('{JOB_ID}', (string)$jobId, $prompt);
$pdo->prepare('UPDATE worker_queue SET prompt_text=? WHERE id=?')->execute([$promptFinal, $jobId]);

$mode = $enabled ? 'enabled' : 'disabled';
echo date('Y-m-d H:i:s') . "  OK queued job_id={$jobId} node_id={$nodeId} (james={$mode})\n";

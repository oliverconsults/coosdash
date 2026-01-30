<?php
// Minimal Worker API (server-side writes) – to keep LLM away from raw SQL.
// For now: only used by future worker queue consumer.

require_once __DIR__ . '/functions.inc.php';

// Very simple shared secret (optional): can be added later.
// For now, allow only local CLI calls (no web exposure expected).

$action = (string)($_REQUEST['action'] ?? '');
$nodeId = (int)($_REQUEST['node_id'] ?? 0);

$pdo = db();

function out($ok, $msg, $extra=[]): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'ping') out(true, 'pong');

if ($nodeId <= 0) out(false, 'missing node_id');

// validate node exists
$st = $pdo->prepare('SELECT id FROM nodes WHERE id=?');
$st->execute([$nodeId]);
if (!$st->fetch()) out(false, 'node not found');

$ts = date('d.m.Y H:i');

if ($action === 'set_blocked_by') {
  $bid = (int)($_REQUEST['blocked_by_node_id'] ?? 0);
  if ($bid <= 0 || $bid === $nodeId) out(false, 'invalid blocked_by_node_id');
  $st = $pdo->prepare('SELECT worker_status FROM nodes WHERE id=?');
  $st->execute([$bid]);
  if (!$st->fetch()) out(false, 'blocker not found');

  $pdo->prepare('UPDATE nodes SET blocked_by_node_id=? WHERE id=?')->execute([$bid, $nodeId]);
  $line = "[james] {$ts} Blocker: wartet auf #{$bid}\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
  out(true, 'blocked_by set');
}

if ($action === 'set_blocked_until') {
  $raw = trim((string)($_REQUEST['blocked_until'] ?? ''));
  if ($raw === '') out(false, 'missing blocked_until');
  $raw = str_replace('T',' ',$raw);
  $dt = DateTime::createFromFormat('Y-m-d H:i', $raw) ?: DateTime::createFromFormat('Y-m-d H:i:s', $raw);
  if (!$dt) out(false, 'invalid datetime');
  $val = $dt->format('Y-m-d H:i:00');

  $pdo->prepare('UPDATE nodes SET blocked_until=? WHERE id=?')->execute([$val, $nodeId]);
  $line = "[james] {$ts} Blocker: bis " . $dt->format('d.m.Y H:i') . "\n\n";
  $pdo->prepare('UPDATE nodes SET description=CONCAT(?, COALESCE(description,\'\')) WHERE id=?')->execute([$line, $nodeId]);
  out(true, 'blocked_until set');
}

out(false, 'unknown action');

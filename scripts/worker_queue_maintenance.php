<?php
// Worker queue maintenance
// - Reset stale claimed jobs back to open if claimed_at older than 10 minutes.

require_once __DIR__ . '/../public/functions_v3.inc.php';
$pdo = db();

// Ensure table exists
require_once __DIR__ . '/migrate_worker_queue.php';

$st = $pdo->prepare("SELECT id,node_id,claimed_at,claimed_by FROM worker_queue WHERE status='claimed' AND claimed_at IS NOT NULL AND claimed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY claimed_at ASC LIMIT 50");
$st->execute();
$rows = $st->fetchAll();

$fixed = 0;
foreach ($rows as $r) {
  $id = (int)$r['id'];
  $nid = (int)$r['node_id'];
  $who = (string)($r['claimed_by'] ?? '');
  $ca = (string)($r['claimed_at'] ?? '');

  $pdo->prepare("UPDATE worker_queue SET status='open', claimed_by=NULL, claimed_at=NULL WHERE id=? AND status='claimed'")->execute([$id]);
  $fixed += 1;

  // log to maintenance log (and worker log for visibility)
  $tsLine = date('Y-m-d H:i:s');
  $tsHuman = date('d.m.Y H:i');
  $msg = "{$tsLine} queue_reset job={$id} node={$nid} claimed_by={$who} claimed_at={$ca}\n";
  @file_put_contents('/var/www/coosdash/shared/logs/worker_queue_maintenance.log', $msg, FILE_APPEND);
  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$nid}  [auto] {$tsHuman} Queue reset: claimed job #{$id} stale (>10m)\n", FILE_APPEND);
}

echo "OK reset={$fixed}\n";

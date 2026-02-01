<?php
// Produce summary+cleanup jobs for completed parent nodes.
// Intended to run via cron (e.g., every 10–30 minutes).
//
// Behavior:
// - Only under Root "Projekte"
// - Find parent nodes where all direct children are done
// - Extra guards:
//   - stability: subtree updated_at older than 10 minutes
//   - no open/claimed worker_queue jobs for subtree
// - Enqueue a worker_queue job that asks the LLM to write a concise summary,
//   then calls worker_api action=cleanup_done_subtree.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();
require_once __DIR__ . '/migrate_worker_queue.php';

function rootId(PDO $pdo, string $title): int {
  $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id IS NULL AND title=? LIMIT 1');
  $st->execute([$title]);
  $r = $st->fetch();
  return $r ? (int)$r['id'] : 0;
}

function isUnderRoot(PDO $pdo, int $nodeId, int $rootId): bool {
  return depthUnderRoot($pdo, $nodeId, $rootId) !== null;
}

// Returns depth below root (root child = 1). null if not under root.
function depthUnderRoot(PDO $pdo, int $nodeId, int $rootId): ?int {
  $cur = $nodeId;
  for ($depth=0; $depth<80; $depth++) {
    $st = $pdo->prepare('SELECT parent_id FROM nodes WHERE id=?');
    $st->execute([$cur]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['parent_id'] === null) return null;
    $pid = (int)$row['parent_id'];
    if ($pid === $rootId) return $depth + 1;
    $cur = $pid;
  }
  return null;
}

$projectsId = rootId($pdo, 'Projekte');
if (!$projectsId) {
  fwrite(STDERR, date('Y-m-d H:i:s') . "  Missing root 'Projekte'\n");
  exit(2);
}

$minTs = date('Y-m-d H:i:s', time() - 10*60);
$tsHuman = date('d.m.Y H:i');
$tsLine = date('Y-m-d H:i:s');

$MAX = 10;
$enq = 0;

// Candidate parents: any node under Projekte that is not done and has children.
$st = $pdo->query('SELECT id,title,worker_status FROM nodes WHERE worker_status <> "done"');
$parents = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($parents as $p) {
  if ($enq >= $MAX) break;
  $pid = (int)$p['id'];
  if ($pid <= 0) continue;
  $depth = depthUnderRoot($pdo, $pid, $projectsId);
  if ($depth === null) continue;

  // Only activate summary+cleanup for depth > 3 (keep Projekte > A > AA > AAA)
  if ($depth <= 3) {
    continue;
  }

  // has children?
  $stC = $pdo->prepare('SELECT id,title,worker_status,updated_at FROM nodes WHERE parent_id=? ORDER BY id');
  $stC->execute([$pid]);
  $kids = $stC->fetchAll(PDO::FETCH_ASSOC);
  if (!$kids) continue;

  $allDone = true;
  foreach ($kids as $k) {
    if ((string)$k['worker_status'] !== 'done') { $allDone = false; break; }
  }
  if (!$allDone) continue;

  // avoid double-enqueue: if there is already a queue job for this parent
  $stQ = $pdo->prepare("SELECT COUNT(*) FROM worker_queue WHERE node_id=? AND status IN ('open','claimed')");
  $stQ->execute([$pid]);
  if ((int)$stQ->fetchColumn() > 0) continue;

  // collect subtree ids (parent + descendants)
  $desc = [];
  $stack = [$pid];
  while ($stack) {
    $cur = array_pop($stack);
    $stK = $pdo->prepare('SELECT id, updated_at FROM nodes WHERE parent_id=?');
    $stK->execute([$cur]);
    foreach ($stK->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $id = (int)$r['id'];
      $desc[] = $id;
      $stack[] = $id;
    }
  }
  $desc = array_values(array_unique($desc));

  // stability: all descendants must be older than minTs
  if ($desc) {
    $in = implode(',', array_fill(0, count($desc), '?'));
    $stS = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE id IN ($in) AND updated_at >= ?");
    $args = $desc;
    $args[] = $minTs;
    $stS->execute($args);
    if ((int)$stS->fetchColumn() > 0) continue;

    // queue guard: no open/claimed jobs for descendants
    $in2 = implode(',', array_fill(0, 1+count($desc), '?'));
    $idsForJobs = array_merge([$pid], $desc);
    $stJ = $pdo->prepare("SELECT COUNT(*) FROM worker_queue WHERE node_id IN ($in2) AND status IN ('open','claimed')");
    $stJ->execute($idsForJobs);
    if ((int)$stJ->fetchColumn() > 0) continue;
  }

  // Build prompt
  $prompt = "# COOS Zusammenfassung+Cleanup Job\n\n";
  $prompt .= "JOB_ID={JOB_ID}\n";
  $prompt .= "TARGET_NODE_ID={$pid}\n";
  $prompt .= "TITLE=" . (string)$p['title'] . "\n\n";

  $prompt .= "Kinder (direkt):\n";
  foreach ($kids as $k) {
    $prompt .= "- #" . (int)$k['id'] . " " . (string)$k['title'] . "\n";
  }
  $prompt .= "\n";

  if ($desc) {
    $prompt .= "Unterkinder gesamt: " . count($desc) . " (rekursiv)\n\n";
  }

  $defaultInstr = "Aufgabe: Erstelle eine kurze, praegnante Zusammenfassung (3-6 Bullets) aus den Notizen/Notes/Attachments der obigen Kinder/Unterkinder.\n"
    . "- Schreibe komplett auf Deutsch, du-Ansprache ist ok.\n"
    . "- Keine langen Logs, keine Wiederholungen. Fokus: Ergebnis + Links/Artefakte (nur referenzieren).\n"
    . "- Referenziere IDs in Klammern, wenn hilfreich (z.B. \"(aus #362)\").\n\n"
    . "Vorgehen (PFLICHT):\n"
    . "1) Lies Parent (#{$pid}) + alle Descendants (description + node_notes + node_attachments).\n"
    . "2) Erzeuge SUMMARY Text (ohne Markdown-Overkill).\n"
    . "3) Rufe dann genau EINEN API Call auf (nutze base64, um Encoding/Shell-Probleme zu vermeiden):\n"
    . "   Tipp: printf '%s' \"<ZUSAMMENFASSUNG>\" | php /home/deploy/projects/coos/scripts/b64_stdin.php\n"
    . "   dann: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=cleanup_done_subtree node_id={$pid} job_id={JOB_ID} summary_b64=\"<BASE64>\"\n\n"
    . "Wichtig: Wenn der API Call fehlschlaegt: NICHTS loeschen/veraendern, sondern job_fail.\n";

  $instr = prompt_get('summary_cleanup_instructions', $defaultInstr);
  // Allow template placeholders
  $instr = str_replace('{TARGET_NODE_ID}', (string)$pid, $instr);

  $prompt .= $instr;

  $pdo->prepare("INSERT INTO worker_queue (status, node_id, prompt_text, selector_meta) VALUES ('open', ?, ?, ?)")
      ->execute([$pid, $prompt, json_encode(['type'=>'summary_cleanup'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  $jobId = (int)$pdo->lastInsertId();
  $promptFinal = str_replace('{JOB_ID}', (string)$jobId, $prompt);
  $pdo->prepare('UPDATE worker_queue SET prompt_text=? WHERE id=?')->execute([$promptFinal, $jobId]);

  @file_put_contents('/var/www/coosdash/shared/logs/worker.log', $tsLine . "  #{$pid}  [auto] {$tsHuman} Summary-Cleanup queued job_id={$jobId}\n", FILE_APPEND);

  $enq++;
}

$logDir = '/var/www/coosdash/shared/logs';
@mkdir($logDir, 0775, true);
file_put_contents($logDir . '/worker_summary_produce.log', $tsLine . " queued={$enq}\n", FILE_APPEND);

echo date('Y-m-d H:i:s') . "  OK queued={$enq}\n";

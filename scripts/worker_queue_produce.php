<?php
// Produce a worker_queue job (open) if none is currently open/claimed.
// Intended to run via Linux cron.

require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();

// Respect UI toggle (James sleeps)
$statePath = '/var/www/coosdash/shared/data/james_state.json';
$enabled = 0;
if (is_file($statePath)) {
  $raw = @file_get_contents($statePath);
  $j = $raw ? json_decode($raw, true) : null;
  if (is_array($j) && !empty($j['enabled'])) $enabled = 1;
}
if (!$enabled) {
  echo date('Y-m-d H:i:s') . "  OK james sleeps (no enqueue)\n";
  exit(0);
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

$prompt .= "Wie du Änderungen machst (PFLICHT):\n";
$prompt .= "- KEINE direkten SQL-Writes in der **cooscrm** DB. Für cooscrm ausschließlich den CLI-Wrapper nutzen: php /home/deploy/projects/coos/scripts/worker_api_cli.php ...\n";
$prompt .= "- Eigene Projekt-Datenbank (z.B. *_rv/*_test): direkte SQL-Writes sind erlaubt, wenn nötig (vorsichtig, nachvollziehbar).\n";
$prompt .= "- Beispiel: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=ping\n";
$prompt .= "\nErlaubte Aktionen:\n";
$prompt .= "- prepend_update (headline, body) [oder headline_b64/body_b64]\n";
$prompt .= "- set_status (worker_status=todo_james|todo_oliver|done)\n";
$prompt .= "- set_blocked_by (blocked_by_node_id)\n";
$prompt .= "- set_blocked_until (blocked_until=YYYY-MM-DD HH:MM)\n";
$prompt .= "- add_children (titles = newline separated, max 6)\n";
$prompt .= "- add_attachment (path, display_name optional)\n";
$prompt .= "- job_done / job_fail (job_id, reason optional)\n";
$prompt .= "\nErwartetes Ergebnis (wähle genau eins):\n";
$prompt .= "A) FERTIG: prepend_update + add_attachment(s) (falls vorhanden) + set_status done + job_done\n";
$prompt .= "B) ZERLEGT: add_children (4–6) + prepend_update (Plan) + job_done\n";
$prompt .= "C) BLOCKIERT: set_blocked_until ODER set_blocked_by + prepend_update (Begründung) + job_done\n";
$prompt .= "D) FRAGE AN OLIVER (Delegation): prepend_update (konkrete Frage + was du brauchst) + set_status todo_oliver + job_done\n";

$prompt .= "\nAttachment-Regel:\n";
$prompt .= "- Wenn du irgendeine Datei erzeugst (PDF/CSV/JSON/TXT/etc.): IMMER via add_attachment anhängen und im Update nur den Attachment-Link referenzieren (keine Serverpfade).\n";
$prompt .= "- Wenn der Node schon relevante Attachments hat: kurz als Input erwähnen.\n";

$prompt .= "\nTools:\n";
$prompt .= "- Du darfst Shell/Files/Browser/Tools nutzen (Scope klein halten).\n";
$prompt .= "- Du darfst kleine Helper-Skripte schreiben (PHP/Python/SQL) für repetitive Arbeit.\n";
$prompt .= "- Keine externen/public Aktionen (Postings/E-Mail/neue Integrationen) ohne OK von Oliver.\n";

$prompt .= "\nHygiene (wenn FERTIG):\n";
$prompt .= "- Check: Verifikation/Run? QA/Edge Cases? Integration/Deploy/Monitoring? Docs/How-to?\n";
$prompt .= "- Wenn etwas fehlt: 1–4 Subtasks unter demselben Parent (max 4) + kurzer Grund.\n";

$prompt .= "\nConstraints:\n";
$prompt .= "- Neue Task-Titel <= 40 Zeichen.\n";

$prompt .= "\nRegeln:\n";
$prompt .= "- Vor done immer Runs/Ergebnis verifizieren.\n";
$prompt .= "- Wichtig (Encoding): wenn du Umlaute/Sonderzeichen oder mehrere Zeilen schreibst, nutze *_b64 Parameter (headline_b64/body_b64).\n";
$prompt .= "  Base64-Helfer (keine node -e Hacks): printf '%s' \"TEXT\" | php /home/deploy/projects/coos/scripts/b64_stdin.php\n";
$prompt .= "- WICHTIG: Sobald du set_status todo_oliver setzt (Delegation an Oliver), ist der Job für dich beendet: KEINE weiteren Aktionen wie add_children / set_blocked_* / attachments danach. Direkt job_done.\n";
$prompt .= "- Delegation ist NUR im Notfall erlaubt: Stelle GENAU 1 präzise Frage an Oliver (max. 2 Zeilen), dann set_status todo_oliver, dann job_done.\n";
$prompt .= "- Bevor du delegierst: führe mindestens 2 konkrete Recon-Schritte durch (z.B. grep nach Entrypoint, runtime-tree check, cron/scripts check, Attachments/ENV check) und erwähne kurz was du geprüft hast.\n";
$prompt .= "- Wenn dir Info fehlt: nutze D) FRAGE AN OLIVER (Delegation).\n";
$prompt .= "- Wenn du nicht weiterkommst: job_fail mit kurzem Grund. Nach 3 fails blockt das System den Task.\n";
$prompt .= "- Tool-KB: Wenn du ein Tool erfolgreich nutzt/installierst: /home/deploy/clawd/TOOLS.md + /home/deploy/clawd/tools/<tool>.md kurz updaten.\n";

$stIns = $pdo->prepare('INSERT INTO worker_queue (status, node_id, prompt_text, selector_meta) VALUES (\'open\', ?, ?, ?)');
$stIns->execute([$nodeId, $prompt, json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
$jobId = (int)$pdo->lastInsertId();

// replace placeholder in stored prompt
$promptFinal = str_replace('{JOB_ID}', (string)$jobId, $prompt);
$pdo->prepare('UPDATE worker_queue SET prompt_text=? WHERE id=?')->execute([$promptFinal, $jobId]);

echo date('Y-m-d H:i:s') . "  OK queued job_id={$jobId} node_id={$nodeId}\n";

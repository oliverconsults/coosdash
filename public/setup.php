<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Defaults
$defaultWorkerRules = "Wie du Änderungen machst (PFLICHT):\n"
  . "- KEINE direkten SQL-Writes in der **cooscrm** DB. Für cooscrm ausschließlich den CLI-Wrapper nutzen: php /home/deploy/projects/coos/scripts/worker_api_cli.php ...\n"
  . "- Eigene Projekt-Datenbank (z.B. *_rv/*_test): direkte SQL-Writes sind erlaubt, wenn nötig (vorsichtig, nachvollziehbar).\n"
  . "- Beispiel: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=ping\n"
  . "\nErlaubte Aktionen:\n"
  . "- prepend_update (headline, body) [oder headline_b64/body_b64]\n"
  . "- set_status (worker_status=todo_james|todo_oliver|done)\n"
  . "- set_blocked_by (blocked_by_node_id)\n"
  . "- set_blocked_until (blocked_until=YYYY-MM-DD HH:MM)\n"
  . "- add_children (titles = newline separated, max 6)\n"
  . "- add_attachment (path, display_name optional)\n"
  . "- job_done / job_fail (job_id, reason optional)\n"
  . "\nErwartetes Ergebnis (wähle genau eins):\n"
  . "A) FERTIG: prepend_update + add_attachment(s) (falls vorhanden) + set_status done + job_done\n"
  . "B) ZERLEGT: add_children (4–6) + prepend_update (Plan) + job_done\n"
  . "C) BLOCKIERT: set_blocked_until ODER set_blocked_by + prepend_update (Begründung) + job_done\n"
  . "D) FRAGE AN OLIVER (Delegation): prepend_update (konkrete Frage + was du brauchst) + set_status todo_oliver + job_done\n"
  . "\nAttachment-Regel:\n"
  . "- Wenn du irgendeine Datei erzeugst (PDF/CSV/JSON/TXT/etc.): IMMER via add_attachment anhängen und im Update nur den Attachment-Link referenzieren (keine Serverpfade).\n"
  . "- Wenn der Node schon relevante Attachments hat: kurz als Input erwähnen.\n"
  . "\nTools:\n"
  . "- Du darfst Shell/Files/Browser/Tools nutzen (Scope klein halten).\n"
  . "- Du darfst kleine Helper-Skripte schreiben (PHP/Python/SQL) für repetitive Arbeit.\n"
  . "- Keine externen/public Aktionen (Postings/E-Mail/neue Integrationen) ohne OK von Oliver.\n"
  . "\nHygiene (wenn FERTIG):\n"
  . "- Check: Verifikation/Run? QA/Edge Cases? Integration/Deploy/Monitoring? Docs/How-to?\n"
  . "- Wenn etwas fehlt: 1–4 Subtasks unter demselben Parent (max 4) + kurzer Grund.\n"
  . "\nConstraints:\n"
  . "- Neue Task-Titel <= 40 Zeichen.\n";

$defaultSummaryInstr = "Aufgabe: Erstelle eine kurze, praegnante Zusammenfassung (3-6 Bullets) aus den Notizen/Notes/Attachments der obigen Kinder/Unterkinder.\n"
  . "- Schreibe komplett auf Deutsch, du-Ansprache ist ok.\n"
  . "- Keine langen Logs, keine Wiederholungen. Fokus: Ergebnis + Links/Artefakte (nur referenzieren).\n"
  . "- Referenziere IDs in Klammern, wenn hilfreich (z.B. \"(aus #362)\").\n\n"
  . "Vorgehen (PFLICHT):\n"
  . "1) Lies Parent (#{TARGET_NODE_ID}) + alle Descendants (description + node_notes + node_attachments).\n"
  . "2) Erzeuge SUMMARY Text (ohne Markdown-Overkill).\n"
  . "3) Rufe dann genau EINEN API Call auf (nutze base64, um Encoding/Shell-Probleme zu vermeiden):\n"
  . "   Tipp: printf '%s' \"<ZUSAMMENFASSUNG>\" | php /home/deploy/projects/coos/scripts/b64_stdin.php\n"
  . "   dann: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=cleanup_done_subtree node_id={TARGET_NODE_ID} job_id={JOB_ID} summary_b64=\"<BASE64>\"\n\n"
  . "Wichtig: Wenn der API Call fehlschlaegt: NICHTS loeschen/veraendern, sondern job_fail.\n";

$defaultWrapperTpl = "# James Queue Consumer (worker_main)\n\n"
  . "You are James. Execute exactly ONE queued job that is already claimed.\n\n"
  . "Job: id={JOB_ID} node_id={NODE_ID}\n\n"
  . "Instructions (job.prompt_text):\n{JOB_PROMPT}\n\n"
  . "Rules:\n"
  . "- cooscrm DB: KEINE direkten SQL-Writes. Alle Änderungen an cooscrm ausschließlich via: php /home/deploy/projects/coos/scripts/worker_api_cli.php ...\n"
  . "- Eigene Projekt-DB (z.B. *_rv/*_test): direkte SQL-Writes sind erlaubt, wenn nötig (vorsichtig, nachvollziehbar).\n"
  . "- If you need Oliver to decide/confirm something: (1) prepend_update explaining the question + options, (2) set_status to todo_oliver, THEN (3) mark the job done.\n"
  . "  Example:\n"
  . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=prepend_update node_id={NODE_ID} headline=\"Frage an Oliver\" body=\"...\"\n"
  . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=set_status node_id={NODE_ID} worker_status=todo_oliver\n"
  . "  php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_done job_id={JOB_ID} node_id={NODE_ID}\n"
  . "- On success: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_done job_id={JOB_ID} node_id={NODE_ID}\n"
  . "- On failure: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=job_fail job_id={JOB_ID} node_id={NODE_ID} reason=\"...\"\n"
  . "- Always end by calling job_done or job_fail (never exit without closing the job).\n"
  . "- Keep it concise. Prefer verification before marking done.\n";

$defaultProjectSetupPrompt = "# COOS Project Setup (LLM)\n\n"
  . "Input:\n"
  . "- title: {TITLE}\n"
  . "- slug: {SLUG}\n"
  . "- description: {DESCRIPTION}\n\n"
  . "Output (JSON only):\n"
  . "{\n"
  . "  \"children\": [\"...\"],\n"
  . "  \"quality_title\": \"Qualitätskontrolle\"\n"
  . "}\n\n"
  . "Rules:\n"
  . "- children: 4 bis 6 kurze direkte Subtasks (<= 40 Zeichen), deutsch.\n"
  . "- Letztes Kind ist immer quality_title.\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');

  if ($action === 'restore_history') {
    $key = (string)($_POST['key'] ?? '');
    $idx = (int)($_POST['restore_idx'] ?? -1);
    if ($key === '') {
      flash_set('Missing key.', 'err');
      header('Location: /setup.php');
      exit;
    }

    $hist = prompt_history_list($key, 100);
    $row = $hist[$idx] ?? null;
    if (!is_array($row)) {
      flash_set('Ungültige History-Auswahl.', 'err');
      header('Location: /setup.php?p=' . rawurlencode($key));
      exit;
    }

    $old = (string)($row['old'] ?? '');
    $ok = prompt_set($key, $old);
    flash_set($ok ? 'Version wiederhergestellt.' : 'Fehler beim Restore.', $ok ? 'info' : 'err');
    header('Location: /setup.php?p=' . rawurlencode($key));
    exit;
  }

  // default: save edits
  $workerRules = (string)($_POST['worker_rules_block'] ?? '');
  $summaryInstr = (string)($_POST['summary_cleanup_instructions'] ?? '');
  $wrapperTpl = (string)($_POST['wrapper_prompt_template'] ?? '');
  $setupPrompt = (string)($_POST['project_setup_prompt'] ?? '');

  $ok = true;
  if ($workerRules !== '') $ok = $ok && prompt_set('worker_rules_block', $workerRules);
  if ($summaryInstr !== '') $ok = $ok && prompt_set('summary_cleanup_instructions', $summaryInstr);
  if ($wrapperTpl !== '') $ok = $ok && prompt_set('wrapper_prompt_template', $wrapperTpl);
  if ($setupPrompt !== '') $ok = $ok && prompt_set('project_setup_prompt', $setupPrompt);

  flash_set($ok ? 'Setup gespeichert.' : 'Fehler beim Speichern.', $ok ? 'info' : 'err');
  header('Location: /setup.php?p=' . rawurlencode((string)($_GET['p'] ?? 'worker_rules_block')));
  exit;
}

// Bootstrap: if missing, write defaults once so prompts.json becomes the single source of truth.
if (!is_file(prompts_path())) {
  prompt_set('worker_rules_block', $defaultWorkerRules);
  prompt_set('summary_cleanup_instructions', $defaultSummaryInstr);
  prompt_set('wrapper_prompt_template', $defaultWrapperTpl);
  prompt_set('project_setup_prompt', $defaultProjectSetupPrompt);
}

// Ensure the new key exists as well
$all = prompts_load();
if (!isset($all['project_setup_prompt']) || !is_string($all['project_setup_prompt']) || trim($all['project_setup_prompt'])==='') {
  prompt_set('project_setup_prompt', $defaultProjectSetupPrompt);
}

$workerRulesCur = prompt_require('worker_rules_block');
$summaryInstrCur = prompt_require('summary_cleanup_instructions');
$wrapperTplCur = prompt_require('wrapper_prompt_template');
$projectSetupCur = prompt_require('project_setup_prompt');

renderHeader('Setup');
?>

<div class="card">
  <h2>Setup: LLM Prompts</h2>
  <div class="meta">Diese Texte sind die <b>Source of Truth</b>. Sie werden immer verwendet.</div>

  <?php
    $sel = (string)($_GET['p'] ?? 'worker_rules_block');
    $options = [
      'worker_rules_block' => 'Worker Prompt – Rules Block',
      'summary_cleanup_instructions' => 'Summary+Cleanup Prompt – Instructions Block',
      'wrapper_prompt_template' => 'Wrapper Prompt Template (worker_main)',
      'project_setup_prompt' => 'Neues Projekt – Setup LLM Script',
    ];
    if (!isset($options[$sel])) $sel = 'worker_rules_block';
  ?>

  <form method="post" action="/setup.php?p=<?php echo h($sel); ?>" onsubmit="return confirm('Wirklich speichern? (History wird automatisch geführt)');">

    <label>Prompt auswählen</label>
    <div class="row" style="align-items:center;">
      <select name="prompt_select" onchange="location.href='/setup.php?p='+encodeURIComponent(this.value);" style="max-width:520px;">
        <?php foreach ($options as $k => $label): ?>
          <option value="<?php echo h($k); ?>" <?php echo $k === $sel ? 'selected' : ''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <span class="meta" style="white-space:nowrap;">Key: <?php echo h($sel); ?></span>
    </div>

    <?php if ($sel === 'worker_rules_block'): ?>
      <label><?php echo h($options[$sel]); ?> (wird an den Job-Prompt angehängt)</label>
      <textarea name="worker_rules_block" style="min-height:260px;"><?php echo h($workerRulesCur); ?></textarea>
    <?php elseif ($sel === 'summary_cleanup_instructions'): ?>
      <label><?php echo h($options[$sel]); ?></label>
      <textarea name="summary_cleanup_instructions" style="min-height:260px;"><?php echo h($summaryInstrCur); ?></textarea>
    <?php elseif ($sel === 'project_setup_prompt'): ?>
      <label><?php echo h($options[$sel]); ?></label>
      <div class="meta">Placeholders: {TITLE}, {SLUG}, {DESCRIPTION}</div>
      <textarea name="project_setup_prompt" style="min-height:260px;"><?php echo h($projectSetupCur); ?></textarea>
    <?php else: ?>
      <label><?php echo h($options[$sel]); ?></label>
      <div class="meta">Placeholders: {JOB_ID}, {NODE_ID}, {JOB_PROMPT}</div>
      <textarea name="wrapper_prompt_template" style="min-height:260px;"><?php echo h($wrapperTplCur); ?></textarea>
    <?php endif; ?>

    <div class="row" style="margin-top:10px">
      <button class="btn btn-gold" type="submit">Speichern</button>
      <a class="btn" href="/">Zurück</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <h2>History (<?php echo h($sel); ?>)</h2>
  <div class="meta">Letzte 100 Versionen, restore mit Bestätigung.</div>

  <?php $hist = prompt_history_list($sel, 100); ?>
  <?php if (!$hist): ?>
    <div class="meta">Noch keine History vorhanden.</div>
  <?php else: ?>
    <form method="post" action="/setup.php?p=<?php echo h($sel); ?>" onsubmit="return confirm('Diese Version wirklich wiederherstellen?');">
      <input type="hidden" name="action" value="restore_history">
      <input type="hidden" name="key" value="<?php echo h($sel); ?>">
      <label>Restore Version</label>
      <select name="restore_idx" style="max-width:720px;">
        <?php foreach ($hist as $idx => $hrow): ?>
          <?php
            $label = (string)($hrow['ts'] ?? '') . ' · user=' . (string)($hrow['user'] ?? '-') . ' · sha=' . substr((string)($hrow['new_sha1'] ?? ''), 0, 8);
          ?>
          <option value="<?php echo (int)$idx; ?>"><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="row" style="margin-top:10px;">
        <button class="btn" type="submit">Restore</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php
  // Preview: show a real, composed worker prompt for the newest todo_james node (best effort).
  $preview = '';
  try {
    $pdo = db();
    $st = $pdo->query("SELECT id,parent_id,title,description,blocked_until,blocked_by_node_id FROM nodes WHERE worker_status='todo_james' ORDER BY updated_at DESC, id DESC LIMIT 1");
    $node = $st->fetch();
    if ($node) {
      $nodeId = (int)$node['id'];
      $blockedUntil = (string)($node['blocked_until'] ?? '');
      $blockedBy = (int)($node['blocked_by_node_id'] ?? 0);

      // parent chain
      $chain = [];
      $cur = $nodeId;
      for ($i=0; $i<40; $i++) {
        $st2 = $pdo->prepare('SELECT id,parent_id,title FROM nodes WHERE id=?');
        $st2->execute([$cur]);
        $r = $st2->fetch();
        if (!$r) break;
        $chain[] = '#' . (int)$r['id'] . ' ' . (string)$r['title'];
        if ($r['parent_id'] === null) break;
        $cur = (int)$r['parent_id'];
      }
      $chain = array_reverse($chain);

      $desc = (string)($node['description'] ?? '');
      $isUmsetzung = (strpos($desc, '##UMSETZUNG##') !== false);

      $preview .= "# COOS Worker Job (aus Queue)\n\n";
      $preview .= "JOB_ID=12345\n";
      $preview .= "TARGET_NODE_ID={$nodeId}\n";
      $preview .= "TITLE=" . (string)$node['title'] . "\n\n";

      if ($isUmsetzung) {
        $preview .= "AUFGABENTYP=UMSETZUNG (hart)\n";
        $preview .= "- Erwartung: Endergebnis liefern und abschließen (nicht nur planen).\n";
        $preview .= "- add_children nur im echten Notfall und nur wenn depth < 8 (kurz begründen).\n\n";
      }

      $preview .= "Sprache / Ton (hart):\n";
      $preview .= "- Schreibe komplett auf Deutsch (keine englischen Labels wie SPLIT/DONE/etc.).\n";
      $preview .= "- Sprich Oliver mit 'du' an (kurz, klar, technisch).\n\n";
      $preview .= "Kette (Parent-Chain):\n- " . implode("\n- ", $chain) . "\n\n";
      $preview .= "Kontext:\n";
      if ($blockedBy > 0) $preview .= "- BLOCKED_BY_NODE_ID={$blockedBy}\n";
      if ($blockedUntil !== '' && strtotime($blockedUntil)) $preview .= "- BLOCKED_UNTIL={$blockedUntil}\n";
      $preview .= "\n";

      // Project env (matches worker_queue_produce)
      $env = project_env_text_for_node($pdo, $nodeId);
      if ($env !== '') {
        $preview .= "PROJEKT_UMGEBUNG (immer beachten):\n";
        $preview .= $env . "\n\n";
      }

      $preview .= $workerRulesCur . "\n";

      // Mirror worker_queue_produce.php (effective prompt)
      $preview .= "\nOperational (English):\n";
      $preview .= "- Quick healthcheck: php /home/deploy/projects/coos/scripts/worker_api_cli.php action=ping\n";

      $preview .= "\nRegeln:\n";
      $preview .= "- Vor done immer Runs/Ergebnis verifizieren.\n";
      $preview .= "- Wichtig (Encoding): wenn du Umlaute/Sonderzeichen oder mehrere Zeilen schreibst, nutze *_b64 Parameter (headline_b64/body_b64).\n";
      $preview .= "  Base64-Helfer (keine node -e Hacks): printf '%s' \"TEXT\" | php /home/deploy/projects/coos/scripts/b64_stdin.php\n";
      $preview .= "- WICHTIG: Sobald du set_status todo_oliver setzt (Delegation an Oliver), ist der Job für dich beendet: KEINE weiteren Aktionen wie add_children / set_blocked_* / attachments danach. Direkt job_done.\n";
      $preview .= "- Delegation ist NUR im Notfall erlaubt: Stelle GENAU 1 präzise Frage an Oliver (max. 2 Zeilen), dann set_status todo_oliver, dann job_done.\n";
      $preview .= "- Bevor du delegierst: führe mindestens 2 konkrete Recon-Schritte durch (z.B. grep nach Entrypoint, runtime-tree check, cron/scripts check, Attachments/ENV check) und erwähne kurz was du geprüft hast.\n";
      $preview .= "- Wenn dir Info fehlt: nutze D) FRAGE AN OLIVER (Delegation).\n";
      $preview .= "- Wenn du nicht weiterkommst: job_fail mit kurzem Grund. Nach 3 fails blockt das System den Task.\n";
      $preview .= "- Tool-KB: Wenn du ein Tool erfolgreich nutzt/installierst: /home/deploy/clawd/TOOLS.md + /home/deploy/clawd/tools/<tool>.md kurz updaten.\n";
    }
  } catch (Throwable $e) {
    $preview = '';
  }
?>

<?php if ($sel === 'worker_rules_block'): ?>
  <?php
    // Preview: pick a *real* past job under "Projekte" that has at least one .md attachment.
    // Then render the exact wrapped prompt that was sent.
    $wrapperPreview = '';
    $sample = null;
    try {
      $pdo = db();
      $projectsId = projects_root_id($pdo);
      if ($projectsId > 0) {
        $sql = "SELECT w.id AS job_id, w.node_id, w.prompt_text
                FROM worker_queue w
                JOIN node_attachments a ON a.node_id = w.node_id
                WHERE a.stored_name LIKE '%.md'
                  AND w.prompt_text IS NOT NULL AND w.prompt_text <> ''
                ORDER BY RAND()
                LIMIT 1";
        $stS = $pdo->query($sql);
        $sample = $stS ? $stS->fetch(PDO::FETCH_ASSOC) : null;
      }
    } catch (Throwable $e) {
      $sample = null;
    }

    if ($sample && $wrapperTplCur !== '') {
      $jid = (string)((int)($sample['job_id'] ?? 0));
      $nid = (string)((int)($sample['node_id'] ?? 0));
      $jp = (string)($sample['prompt_text'] ?? '');
      $wrapperPreview = str_replace(['{JOB_ID}','{NODE_ID}','{JOB_PROMPT}'], [$jid, $nid, $jp], $wrapperTplCur);
    }
  ?>

  <div class="card" style="margin-top:14px;">
    <h2>Preview: Effektiver LLM Prompt (Wrapper + Worker)</h2>
    <div class="meta">
      Realer Beispiel-Call aus <code>worker_queue</code> (random Job mit .md Attachment).<?php if ($sample): ?>
        &nbsp;job_id=<?php echo (int)$sample['job_id']; ?> · node_id=<a href="/?id=<?php echo (int)$sample['node_id']; ?>">#<?php echo (int)$sample['node_id']; ?></a>
      <?php endif; ?>
    </div>

    <?php if ($wrapperPreview !== ''): ?>
      <textarea readonly style="min-height:300px; opacity:0.95;"><?php echo h($wrapperPreview); ?></textarea>
    <?php else: ?>
      <div class="meta">Kein geeignetes Beispiel gefunden (benötigt mindestens einen Job mit .md Attachment) oder Wrapper-Template fehlt.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
  // Preview: summary+cleanup prompt (most recent queued summary job)
  $summaryPreview = '';
  try {
    $pdo = db();
    $st = $pdo->query("SELECT id,node_id,prompt_text,created_at FROM worker_queue WHERE selector_meta LIKE '%summary_cleanup%' ORDER BY id DESC LIMIT 1");
    $row = $st->fetch();
    if ($row) {
      $summaryPreview = (string)($row['prompt_text'] ?? '');
    }
  } catch (Throwable $e) {
    $summaryPreview = '';
  }
?>

<?php if ($sel === 'wrapper_prompt_template'): ?>
  <!-- Preview removed: effective preview is shown under Worker Prompt – Rules Block -->
<?php endif; ?>

<?php if ($sel === 'summary_cleanup_instructions'): ?>
  <div class="card" style="margin-top:14px;">
    <h2>Preview: Summary+Cleanup Prompt</h2>
    <div class="meta">Letzter gequeue-ter Summary+Cleanup Job aus <code>worker_queue</code> (best effort).</div>
    <?php if ($summaryPreview !== ''): ?>
      <textarea readonly style="min-height:260px; opacity:0.95;"><?php echo h($summaryPreview); ?></textarea>
    <?php else: ?>
      <div class="meta">Kein Summary+Cleanup Preview verfügbar (noch kein Job in worker_queue gefunden).</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php renderFooter();

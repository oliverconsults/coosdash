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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $workerRules = (string)($_POST['worker_rules_block'] ?? '');
  $summaryInstr = (string)($_POST['summary_cleanup_instructions'] ?? '');
  $wrapperTpl = (string)($_POST['wrapper_prompt_template'] ?? '');

  // save
  $ok = true;
  $ok = $ok && prompt_set('worker_rules_block', $workerRules);
  $ok = $ok && prompt_set('summary_cleanup_instructions', $summaryInstr);
  $ok = $ok && prompt_set('wrapper_prompt_template', $wrapperTpl);

  flash_set($ok ? 'Setup gespeichert.' : 'Fehler beim Speichern.', $ok ? 'info' : 'err');
  header('Location: /setup.php');
  exit;
}

// Bootstrap: if missing, write defaults once so prompts.json becomes the single source of truth.
if (!is_file(prompts_path())) {
  prompt_set('worker_rules_block', $defaultWorkerRules);
  prompt_set('summary_cleanup_instructions', $defaultSummaryInstr);
  prompt_set('wrapper_prompt_template', $defaultWrapperTpl);
}

$workerRulesCur = prompt_require('worker_rules_block');
$summaryInstrCur = prompt_require('summary_cleanup_instructions');
$wrapperTplCur = prompt_require('wrapper_prompt_template');

renderHeader('Setup');
?>

<div class="card">
  <h2>Setup: LLM Prompts</h2>
  <div class="meta">Diese Texte sind die <b>Source of Truth</b>. Sie werden immer verwendet.</div>

  <form method="post" action="/setup.php">

    <?php
      $sel = (string)($_GET['p'] ?? 'worker_rules_block');
      $options = [
        'worker_rules_block' => 'Worker Prompt – Rules Block',
        'summary_cleanup_instructions' => 'Summary+Cleanup Prompt – Instructions Block',
        'wrapper_prompt_template' => 'Wrapper Prompt Template (worker_main)',
      ];
      if (!isset($options[$sel])) $sel = 'worker_rules_block';
    ?>

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

      $preview .= $workerRulesCur . "\n";
      $preview .= "\n… (restliche statische Teile: erwartetes Ergebnis / attachments / tools / hygiene / constraints / regeln)\n";
    }
  } catch (Throwable $e) {
    $preview = '';
  }
?>

<div class="card" style="margin-top:14px;">
  <h2>Preview: Worker Prompt (best effort)</h2>
  <div class="meta">Beispiel-Prompt mit echter Parent-Chain aus dem aktuellsten todo_james Node. (JOB_ID ist Dummy)</div>
  <?php if ($preview !== ''): ?>
    <textarea readonly style="min-height:260px; opacity:0.95;"><?php echo h($preview); ?></textarea>
  <?php else: ?>
    <div class="meta">Kein Preview verfügbar (keine todo_james Nodes gefunden).</div>
  <?php endif; ?>
</div>

<?php
  // Preview: wrapper prompt (template filled with sample values)
  $wrapperPreview = '';
  if ($wrapperTplCur !== '') {
    $wrapperPreview = str_replace(
      ['{JOB_ID}','{NODE_ID}','{JOB_PROMPT}'],
      ['12345', ($nodeId ?? 0) ? (string)$nodeId : '0', ($preview !== '' ? $preview : '[no worker prompt preview available]')],
      $wrapperTplCur
    );
  }

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

<div class="card" style="margin-top:14px;">
  <h2>Preview: Wrapper Prompt (worker_main)</h2>
  <div class="meta">Template gefüllt mit Dummy JOB_ID=12345 + sample NODE_ID + Worker-Prompt als {JOB_PROMPT}.</div>
  <?php if ($wrapperPreview !== ''): ?>
    <textarea readonly style="min-height:260px; opacity:0.95;"><?php echo h($wrapperPreview); ?></textarea>
  <?php else: ?>
    <div class="meta">Kein Wrapper-Template gesetzt.</div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:14px;">
  <h2>Preview: Summary+Cleanup Prompt</h2>
  <div class="meta">Letzter gequeue-ter Summary+Cleanup Job aus <code>worker_queue</code> (best effort).</div>
  <?php if ($summaryPreview !== ''): ?>
    <textarea readonly style="min-height:260px; opacity:0.95;"><?php echo h($summaryPreview); ?></textarea>
  <?php else: ?>
    <div class="meta">Kein Summary+Cleanup Preview verfügbar (noch kein Job in worker_queue gefunden).</div>
  <?php endif; ?>
</div>

<?php renderFooter();

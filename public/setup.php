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

$workerRulesCur = prompt_get('worker_rules_block', $defaultWorkerRules);
$summaryInstrCur = prompt_get('summary_cleanup_instructions', $defaultSummaryInstr);
$wrapperTplCur = prompt_get('wrapper_prompt_template', $defaultWrapperTpl);

renderHeader('Setup');
?>

<div class="card">
  <h2>Setup: LLM Prompts</h2>
  <div class="meta">Diese Texte sind Overrides. Wenn leer, greifen Defaults aus Code.</div>

  <form method="post" action="/setup.php">

    <label>Worker Prompt – Rules Block (wird an den Job-Prompt angehängt)</label>
    <textarea name="worker_rules_block"><?php echo h($workerRulesCur); ?></textarea>

    <label>Summary+Cleanup Prompt – Instructions Block</label>
    <textarea name="summary_cleanup_instructions"><?php echo h($summaryInstrCur); ?></textarea>

    <label>Wrapper Prompt Template (worker_main)</label>
    <div class="meta">Placeholders: {JOB_ID}, {NODE_ID}, {JOB_PROMPT}</div>
    <textarea name="wrapper_prompt_template"><?php echo h($wrapperTplCur); ?></textarea>

    <div class="row" style="margin-top:10px">
      <button class="btn btn-gold" type="submit">Speichern</button>
      <a class="btn" href="/">Zurück</a>
    </div>
  </form>
</div>

<?php renderFooter();

<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Prompt key for the setup LLM (stored in prompts.json)
$defaultSetupPrompt = "# COOS Project Setup (LLM)\n\n"
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

// Ensure prompt exists once (source of truth)
if (!is_file(prompts_path())) {
  prompt_set('project_setup_prompt', $defaultSetupPrompt);
}
if (is_file(prompts_path())) {
  $all = prompts_load();
  if (!isset($all['project_setup_prompt']) || !is_string($all['project_setup_prompt']) || trim($all['project_setup_prompt'])==='') {
    prompt_set('project_setup_prompt', $defaultSetupPrompt);
  }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
  $desc = trim((string)($_POST['description'] ?? ''));

  if ($title === '' || $slug === '' || $desc === '') {
    $err = 'Bitte alle Felder ausfüllen.';
  } elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{1,40}$/', $slug)) {
    $err = 'Slug ungültig. Erlaubt: a-z, 0-9, -, Länge 2–41.';
  } else {
    $pdo = db();

    // Find Projekte root
    $st = $pdo->prepare("SELECT id FROM nodes WHERE parent_id IS NULL AND title='Projekte' LIMIT 1");
    $st->execute();
    $projectsId = (int)($st->fetchColumn() ?: 0);
    if ($projectsId <= 0) {
      $err = 'Root "Projekte" nicht gefunden.';
    } else {
      // Create parent project node
      $ts = date('d.m.Y H:i');
      $createdBy = (string)($_SESSION['username'] ?? 'oliver');
      $baseDesc = "[oliver] {$ts} Projektbeschreibung\n\n" . $desc . "\n\n";
      $baseDesc .= "[setup] slug={$slug}\n";
      $baseDesc .= "[setup] url=https://t.coos.eu/{$slug}/\n";
      $baseDesc .= "[setup] env=/var/www/t/{$slug}/shared/env.md\n\n";

      $stIns = $pdo->prepare('INSERT INTO nodes (parent_id,title,description,created_by,worker_status,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
      $stIns->execute([$projectsId, $title, $baseDesc, $createdBy, 'todo_james']);
      $parentId = (int)$pdo->lastInsertId();

      // Children: deterministic starter set (LLM integration later)
      $children = [
        'Ziele & Scope schärfen',
        'Technisches Setup (Nginx/DB)',
        'Implementierung Kernfunktionen',
        'Deploy + Smoke-Test',
        'Qualitätskontrolle',
      ];

      foreach ($children as $ct) {
        $stIns->execute([$parentId, $ct, '', $createdBy, 'todo_james']);
      }

      // Enqueue provisioning request for deploy-side cron
      $queuePath = '/var/www/coosdash/shared/data/project_setup_queue.jsonl';
      @mkdir(dirname($queuePath), 0775, true);
      $req = [
        'ts' => date('Y-m-d H:i:s'),
        'slug' => $slug,
        'title' => $title,
        'description' => $desc,
        'node_id' => $parentId,
        'requested_by' => $createdBy,
      ];
      @file_put_contents($queuePath, json_encode($req, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

      flash_set('Projekt angelegt (#' . $parentId . '). Provisioning läuft per Cron.', 'info');
      header('Location: /?id=' . $parentId);
      exit;
    }
  }
}

renderHeader('Neues Projekt');
?>

<div class="card">
  <h2>Neues Projekt</h2>
  <div class="meta">Erstellt einen Projekt-Node unter "Projekte" + Standard-Subtasks + queued Provisioning (t.coos.eu + DB + env.md) via Cron.</div>

  <?php if ($err !== ''): ?>
    <div class="flash err"><?php echo h($err); ?></div>
  <?php endif; ?>

  <form method="post" action="/new_project.php" onsubmit="return confirm('Projekt wirklich anlegen?');">
    <label>Projektname</label>
    <input name="title" value="<?php echo h((string)($_POST['title'] ?? '')); ?>" required maxlength="80" />

    <label>Slug (für URL: t.coos.eu/&lt;slug&gt;/)</label>
    <input name="slug" value="<?php echo h((string)($_POST['slug'] ?? '')); ?>" required maxlength="40" placeholder="z.B. game" />

    <label>Projektbeschreibung</label>
    <textarea name="description" required style="min-height:220px;"><?php echo h((string)($_POST['description'] ?? '')); ?></textarea>

    <div class="row" style="margin-top:10px;">
      <button class="btn btn-gold" type="submit">Projekt anlegen</button>
      <a class="btn" href="/setup.php">Setup</a>
    </div>
  </form>
</div>

<?php renderFooter();

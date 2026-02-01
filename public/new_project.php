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
  . "  \"quality_title\": \"Qualitätskontrolle\",\n"
  . "  \"quality_children\": [\"...\"]\n"
  . "}\n\n"
  . "Rules:\n"
  . "- Analysiere das Projekt und erstelle wirklich passende Subtasks (keine generischen Platzhalter).\n"
  . "- children: 4 bis 6 kurze direkte Subtasks (<= 40 Zeichen), deutsch.\n"
  . "- Letztes Kind ist immer quality_title.\n"
  . "- quality_children: 5 bis 10 Unterpunkte (<= 40 Zeichen), die genau fuer dieses Projekt wichtig sind.\n"
  . "- quality_children sind Kinder von quality_title (Qualitätskontrolle).\n"
  . "- JSON only, keine Erklaerungen, kein Markdown.\n";

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

// Optional: convert an existing "Ideen" node into a project under "Projekte"
$fromNodeId = isset($_REQUEST['from_node']) ? (int)$_REQUEST['from_node'] : 0;
$prefill = ['title'=>'','description'=>''];
if ($fromNodeId > 0) {
  try {
    $pdo = db();
    $st = $pdo->prepare('SELECT id,title,description FROM nodes WHERE id=?');
    $st->execute([$fromNodeId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $prefill['title'] = (string)$r['title'];
      $prefill['description'] = (string)$r['description'];
    }
  } catch (Throwable $e) {
    // ignore
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
  $desc = trim((string)($_POST['description'] ?? ''));
  $fromNodeId = isset($_POST['from_node']) ? (int)$_POST['from_node'] : 0;
  $confirmPurge = !empty($_POST['confirm_purge']);

  if ($title === '' || $slug === '' || $desc === '') {
    $err = 'Bitte alle Felder ausfüllen.';
  } elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{1,40}$/', $slug)) {
    $err = 'Slug ungültig. Erlaubt: a-z, 0-9, -, Länge 2–41.';
  } else {
    $pdo = db();

    // --- Slug safety check (prevent reusing slugs with leftover data) ---
    projects_migrate($pdo);
    $deletedRootId = (int)$pdo->query("SELECT id FROM nodes WHERE parent_id IS NULL AND title='Gelöscht' LIMIT 1")->fetchColumn();

    // There may be multiple entries for the same slug (no unique index).
    $st = $pdo->prepare('SELECT node_id FROM projects WHERE slug=? ORDER BY node_id DESC');
    $st->execute([$slug]);
    $existingNodeIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'node_id'));

    $slugDir = '/var/www/t/' . $slug;
    $slugDirExists = is_dir($slugDir);

    // Determine whether any existing node for this slug is active vs. deleted
    $activeExistingIds = [];
    $deletedExistingIds = [];

    $isUnderDeleted = function(int $nid) use ($pdo, $deletedRootId): bool {
      if ($nid <= 0 || $deletedRootId <= 0) return false;
      $cur = $nid;
      for ($i=0; $i<120; $i++) {
        $st = $pdo->prepare('SELECT parent_id FROM nodes WHERE id=?');
        $st->execute([$cur]);
        $pid = $st->fetchColumn();
        if ($pid === false || $pid === null) return false;
        $pid = (int)$pid;
        if ($pid === $deletedRootId) return true;
        $cur = $pid;
      }
      return false;
    };

    foreach ($existingNodeIds as $eid) {
      if ($eid <= 0) continue;
      if ($isUnderDeleted($eid)) $deletedExistingIds[] = $eid;
      else $activeExistingIds[] = $eid;
    }

    // Hard rule: if slug is already used by an active project, always block (even if confirm_purge is checked).
    if (!empty($activeExistingIds)) {
      $err = 'Slug ist bereits vergeben (aktives Projekt #' . (int)$activeExistingIds[0] . '). Bitte anderen Slug wählen.';
    }

    // If slug exists only as deleted project and/or leftover dir: require explicit confirmation.
    if ($err === '' && ((count($deletedExistingIds) > 0) || $slugDirExists) && !$confirmPurge) {
      $err = 'Slug ist bereits vorhanden (altes Projekt/Dateien). Bitte bestätigen, dass alles alte endgültig gelöscht werden soll.';
    }

    // If confirmed: purge old deleted project(s) + runtime directory before creating the new project.
    if ($err === '' && $confirmPurge && ((count($deletedExistingIds) > 0) || $slugDirExists)) {
      $deleteSubtree = function(int $nodeId) use (&$deleteSubtree, $pdo): int {
        $count = 0;
        $st = $pdo->prepare('SELECT id FROM nodes WHERE parent_id=?');
        $st->execute([$nodeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $count += $deleteSubtree((int)$row['id']);
        }
        // clean related tables
        $pdo->prepare('DELETE FROM worker_queue WHERE node_id=?')->execute([$nodeId]);
        try { $pdo->prepare('DELETE FROM node_attachments WHERE node_id=?')->execute([$nodeId]); } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM node_notes WHERE node_id=?')->execute([$nodeId]);
        $pdo->prepare('DELETE FROM nodes WHERE id=?')->execute([$nodeId]);
        return $count + 1;
      };

      if (count($deletedExistingIds) > 0) {
        $pdo->beginTransaction();
        try {
          foreach ($deletedExistingIds as $eid) {
            $deleteSubtree($eid);
            $pdo->prepare('DELETE FROM projects WHERE node_id=?')->execute([$eid]);
          }
          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
          $err = 'Konnte altes Projekt nicht löschen: ' . $e->getMessage();
        }
      }

      // delete runtime dir /var/www/t/<slug> (best-effort)
      if ($err === '' && $slugDirExists) {
        $rr = function(string $p) use (&$rr): void {
          if (is_link($p)) { @unlink($p); return; }
          if (is_file($p)) { @unlink($p); return; }
          if (!is_dir($p)) return;
          foreach (@scandir($p) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $rr($p . '/' . $f);
          }
          @rmdir($p);
        };
        $rr($slugDir);
      }

      // also remove convenience symlink if present
      $linkPath = '/home/deploy/projects/t/' . $slug;
      if (is_link($linkPath)) {
        @unlink($linkPath);
      }

      // Drop project DB + user (guard: NEVER touch coosdash/cooscrm db)
      if ($err === '') {
        $dbName = 'coos_' . str_replace('-', '_', $slug);
        $dbUser = $dbName . '_app';

        $cfg = @require '/var/www/coosdash/shared/config.local.php';
        $coosDashDb = '';
        if (is_array($cfg) && isset($cfg['db']['name'])) $coosDashDb = (string)$cfg['db']['name'];

        if ($dbName === '' || $dbName === $coosDashDb || $dbName === 'cooscrm') {
          $err = 'Safety: Refusing to drop protected DB.';
        } else {
          // best-effort mysql drop
          $mysql = '/usr/bin/mysql';
          $cmds = "DROP DATABASE IF EXISTS `{$dbName}`; DROP USER IF EXISTS `{$dbUser}`@`localhost`; FLUSH PRIVILEGES;";
          @exec($mysql . ' -e ' . escapeshellarg($cmds) . ' 2>/dev/null');
        }
      }
    }

    if ($err !== '') {
      // fall through to render form
    } else {

    // Build project setup prompt (will be executed by worker_main as deploy user)
    $setupPromptTpl = prompt_require('project_setup_prompt');
    $setupPrompt = str_replace(
      ['{TITLE}','{SLUG}','{DESCRIPTION}'],
      [$title, $slug, $desc],
      $setupPromptTpl
    );

    // Log effective prompt to llm_file.php viewer (response will be written by worker_main)
    $llmDir = '/var/www/coosdash/shared/llm';
    @mkdir($llmDir, 0775, true);
    $reqId = 'project_setup_' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9\-]+/','', $slug);
    $promptFile = $llmDir . '/' . $reqId . '_prompt.txt';
    @file_put_contents($promptFile, $setupPrompt);

    $llmJson = null; // will be produced async by worker queue

    // Find Projekte root
    $st = $pdo->prepare("SELECT id FROM nodes WHERE parent_id IS NULL AND title='Projekte' LIMIT 1");
    $st->execute();
    $projectsId = (int)($st->fetchColumn() ?: 0);
    if ($projectsId <= 0) {
      $err = 'Root "Projekte" nicht gefunden.';
    } else {
      $ts = date('d.m.Y H:i');
      $createdBy = (string)($_SESSION['username'] ?? 'oliver');

      $baseDesc = "[oliver] {$ts} Projektbeschreibung\n\n" . $desc . "\n\n";
      $baseDesc .= "[setup] slug={$slug}\n";
      $baseDesc .= "[setup] url=https://t.coos.eu/{$slug}/\n";
      $baseDesc .= "[setup] env=/var/www/t/{$slug}/shared/env.md\n";
      $baseDesc .= "[setup] status=warte_auf_llm_setup\n\n";

      // Either create a new project node, or re-use an existing Ideen-node (move it under Projekte).
      if ($fromNodeId > 0) {
        // Verify source exists
        $st = $pdo->prepare('SELECT id,parent_id,title,description FROM nodes WHERE id=?');
        $st->execute([$fromNodeId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if (!$src) {
          $err = 'Source-Node nicht gefunden.';
        } else {
          // Move under Projekte + set status=done (wait for LLM setup before James starts)
          $pdo->prepare('UPDATE nodes SET parent_id=?, title=?, worker_status=?, updated_at=NOW() WHERE id=?')
              ->execute([$projectsId, $title, 'done', $fromNodeId]);

          // Prepend new project description + setup markers, keep old text below for context
          $oldDesc = (string)($src['description'] ?? '');
          $newDesc = $baseDesc . $oldDesc;
          $pdo->prepare('UPDATE nodes SET description=? WHERE id=?')->execute([$newDesc, $fromNodeId]);

          $parentId = $fromNodeId;
        }
      } else {
        // Create parent project node
        $stIns = $pdo->prepare('INSERT INTO nodes (parent_id,title,description,created_by,worker_status,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
        // Mark as done initially: we wait for project-setup LLM to generate subtasks.
        $stIns->execute([$projectsId, $title, $baseDesc, $createdBy, 'done']);
        $parentId = (int)$pdo->lastInsertId();
      }

      if ($err !== '') {
        // fall through to render form
      } else {
        // Children are generated asynchronously by worker_main (project setup job).

        // Persist project metadata (env.md path etc.)
        projects_migrate($pdo);
        $envPath = '/var/www/t/' . $slug . '/shared/env.md';
        $pdo->prepare('INSERT INTO projects (node_id, slug, env_path) VALUES (?,?,?) ON DUPLICATE KEY UPDATE slug=VALUES(slug), env_path=VALUES(env_path)')
            ->execute([$parentId, $slug, $envPath]);

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

        // Create a markdown meta file (for audit / context).
        $metaMd = "# Projekt Setup (LLM)\n\n";
        $metaMd .= "- Node: #{$parentId}\n";
        $metaMd .= "- Title: {$title}\n";
        $metaMd .= "- Slug: {$slug}\n";
        $metaMd .= "- URL: https://t.coos.eu/{$slug}/\n";
        $metaMd .= "- Env: /var/www/t/{$slug}/shared/env.md\n";
        $metaMd .= "- Prompt: /llm_file.php?f=" . basename($promptFile) . "\n";
        $metaMd .= "- Response: /llm_file.php?f=" . basename($reqId . '_response.txt') . "\n\n";
        $metaMd .= "## Beschreibung\n\n" . $desc . "\n";
        $metaPath = $llmDir . '/' . $reqId . '_meta.md';
        @file_put_contents($metaPath, $metaMd);

        // Enqueue a project-setup job for worker_main (runs as deploy, can call clawdbot agent)
        require_once __DIR__ . '/../scripts/migrate_worker_queue.php';
        $pdo->prepare("INSERT INTO worker_queue (status,node_id,prompt_text,selector_meta) VALUES ('open', ?, ?, ?)")
            ->execute([$parentId, $setupPrompt, json_encode(['type'=>'project_setup','req_id'=>$reqId,'slug'=>$slug], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        flash_set(($fromNodeId > 0 ? 'Projekt aus Idee erstellt/verschoben (#' : 'Projekt angelegt (#') . $parentId . '). Setup-Subtasks werden jetzt via LLM erzeugt (Worker). Provisioning läuft per Cron.', 'info');
        header('Location: /?id=' . $parentId);
        exit;
      }
    }

    // close slug-check else-block
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
    <?php if ($fromNodeId > 0): ?>
      <input type="hidden" name="from_node" value="<?php echo (int)$fromNodeId; ?>">
      <div class="meta">Aus Idee-Node: #<?php echo (int)$fromNodeId; ?> (wird nach "Projekte" verschoben)</div>
    <?php endif; ?>

    <label style="margin-top:0">Slug-Reuse (Sicherheitscheck)</label>
    <div class="meta">Wenn ein Slug schon mal existierte (z.B. im Bereich „Gelöscht“ oder als Verzeichnis unter /var/www/t/&lt;slug&gt;), muss das vorher endgültig bereinigt werden.</div>
    <label style="margin-top:8px; display:flex; align-items:center; gap:8px;">
      <input type="checkbox" name="confirm_purge" value="1" style="width:auto;" <?php echo !empty($_POST['confirm_purge']) ? 'checked' : ''; ?> />
      <span>Ja, altes Projekt/Dateien für diesen Slug endgültig löschen (falls vorhanden)</span>
    </label>

    <label>Projektname</label>
    <input name="title" value="<?php echo h((string)($_POST['title'] ?? ($prefill['title'] ?? ''))); ?>" required maxlength="80" />

    <label>Slug (für URL: t.coos.eu/&lt;slug&gt;/)</label>
    <input name="slug" value="<?php echo h((string)($_POST['slug'] ?? '')); ?>" required maxlength="40" placeholder="z.B. game" />

    <label>Projektbeschreibung</label>
    <textarea name="description" required style="min-height:220px;"><?php echo h((string)($_POST['description'] ?? ($prefill['description'] ?? ''))); ?></textarea>

    <div class="row" style="margin-top:10px;">
      <button class="btn btn-gold" type="submit">Projekt anlegen</button>
      <a class="btn" href="/setup.php">Setup</a>
    </div>
  </form>
</div>

<?php renderFooter();

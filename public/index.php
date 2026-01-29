<?php
session_start();

// Minimal Idea-to-Cash dashboard (v0)
// Storage: JSON file in /var/www/coos/shared/data/ideas.json

$sharedPath = '/var/www/coos/shared/data/ideas.json';
$ideas = [];
if (is_file($sharedPath)) {
  $raw = file_get_contents($sharedPath);
  $ideas = json_decode($raw, true) ?: [];
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>COOS — Idea-to-Cash Dashboard</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:24px; max-width:1100px}
    h1{margin:0 0 12px 0}
    .muted{color:#666}
    .card{border:1px solid #ddd;border-radius:12px;padding:14px;margin:12px 0;background:#fafafa}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
    code{background:#f1f1f1;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <h1>Idea-to-Cash Dashboard</h1>
  <div class="muted">v0 • Storage: <code><?php echo htmlspecialchars($sharedPath); ?></code></div>

  <div class="card">
    <strong>Heute Nacht</strong><br>
    James schreibt hier künftig 3–5 neue Konzepte/Experimente rein.
  </div>

  <div class="card">
    <strong>Backlog</strong>
    <?php if (!$ideas): ?>
      <p class="muted">Noch keine Einträge. (Heute: nur Grundgerüst.)</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Titel</th><th>Impact</th><th>Effort</th><th>Next step</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ideas as $it): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($it['title'] ?? ''); ?></strong><br><span class="muted"><?php echo nl2br(htmlspecialchars($it['problem'] ?? '')); ?></span></td>
            <td><?php echo htmlspecialchars($it['impact'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($it['effort'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($it['next_action'] ?? ''); ?></td>
            <td class="muted"><?php echo htmlspecialchars($it['created_at'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>

<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

renderHeader('Warteliste');

// Read waitlist from cooslanding DB
$rows = [];
$leads = [];
$error = '';
try {
  $cfg = require '/var/www/t/cooslanding/shared/config.local.php';
  $db = $cfg['db'] ?? [];
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'] ?? '127.0.0.1',
    (int)($db['port'] ?? 3306),
    $db['name'] ?? '',
    $db['charset'] ?? 'utf8mb4'
  );
  $pdo2 = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  $rows = $pdo2->query("SELECT id, created_at, updated_at, email, name, note, status, ip, user_agent FROM waitlist_signups ORDER BY id DESC LIMIT 200")
    ->fetchAll();
  $leads = $pdo2->query("SELECT id, created_at, source, email, name, note, ip FROM leads WHERE source='waitlist' ORDER BY id DESC LIMIT 200")
    ->fetchAll();
} catch (Throwable $e) {
  $error = $e->getMessage();
}

$short = function(?string $s, int $n=120): string {
  $s = (string)($s ?? '');
  $s = trim($s);
  if ($s === '') return '';
  if (mb_strlen($s) > $n) return mb_substr($s, 0, $n-1) . '…';
  return $s;
};
?>

<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">Warteliste (cooslanding)</h2>
    <div class="row">
      <a class="btn btn-md" href="/">Dashboard</a>
      <a class="btn btn-md" href="/workerlog.php">Worker Log</a>
    </div>
  </div>
  <div class="meta" style="margin-top:6px;">Quelle: DB <code>coos_cooslanding</code> · Tabelle <code>waitlist_signups</code></div>

  <?php if ($error !== ''): ?>
    <div class="flash err" style="margin-top:10px;">Fehler: <?php echo h($error); ?></div>
  <?php endif; ?>

  <div style="height:10px"></div>

  <h3 style="margin:0 0 10px 0;">Anmeldungen</h3>
  <?php if (empty($rows)): ?>
    <div class="meta">Keine Einträge.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Status</th>
          <th>E-Mail</th>
          <th>Name</th>
          <th>Notiz</th>
          <th>Created</th>
          <th>Updated</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo (int)($r['id'] ?? 0); ?></td>
            <td><?php echo h((string)($r['status'] ?? '')); ?></td>
            <td><code><?php echo h((string)($r['email'] ?? '')); ?></code></td>
            <td><?php echo h($short((string)($r['name'] ?? ''), 60)); ?></td>
            <td><?php echo h($short((string)($r['note'] ?? ''), 140)); ?></td>
            <td class="meta"><?php echo h((string)($r['created_at'] ?? '')); ?></td>
            <td class="meta"><?php echo h((string)($r['updated_at'] ?? '')); ?></td>
            <td class="meta"><?php echo h((string)($r['ip'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="height:18px"></div>

  <h3 style="margin:0 0 10px 0;">Leads-Stream (source=waitlist)</h3>
  <?php if (empty($leads)): ?>
    <div class="meta">Keine Leads.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Created</th>
          <th>E-Mail</th>
          <th>Name</th>
          <th>Notiz</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $r): ?>
          <tr>
            <td><?php echo (int)($r['id'] ?? 0); ?></td>
            <td class="meta"><?php echo h((string)($r['created_at'] ?? '')); ?></td>
            <td><code><?php echo h((string)($r['email'] ?? '')); ?></code></td>
            <td><?php echo h($short((string)($r['name'] ?? ''), 60)); ?></td>
            <td><?php echo h($short((string)($r['note'] ?? ''), 140)); ?></td>
            <td class="meta"><?php echo h((string)($r['ip'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php renderFooter();

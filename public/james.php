<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

if (!isset($_GET['toggle'])) {
  header('Location: /');
  exit;
}

$pdo = db();

$cur = james_enabled();
$next = !$cur;
if (!james_set_enabled($next)) {
  flash_set('Konnte James-Status nicht speichern.', 'err');
  header('Location: /');
  exit;
}

// If turning off: clear any pre-produced open/claimed jobs (so queue is empty while sleeping)
if (!$next) {
  try {
    $pdo->exec("UPDATE worker_queue SET status='canceled' WHERE status IN ('open','claimed')");
  } catch (Throwable $e) {
    // ignore
  }
}

flash_set($next ? 'James aktiv.' : 'James sleeps.', 'info');

$ref = $_SERVER['HTTP_REFERER'] ?? '/';
if (!is_string($ref) || $ref === '') $ref = '/';
header('Location: ' . $ref);
exit;

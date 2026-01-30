<?php
require_once __DIR__ . '/functions.inc.php';
requireLogin();

if (!isset($_GET['toggle'])) {
  header('Location: /');
  exit;
}

$cur = james_enabled();
$next = !$cur;
if (!james_set_enabled($next)) {
  flash_set('Konnte James-Status nicht speichern.', 'err');
  header('Location: /');
  exit;
}

flash_set($next ? 'James aktiv.' : 'James sleeps.', 'info');

$ref = $_SERVER['HTTP_REFERER'] ?? '/';
if (!is_string($ref) || $ref === '') $ref = '/';
header('Location: ' . $ref);
exit;

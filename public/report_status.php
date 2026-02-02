<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
require_once __DIR__ . '/../scripts/migrate_project_reports.php';

$rid = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
if ($rid <= 0) {
  echo json_encode(['ok'=>false,'msg'=>'missing report_id']);
  exit;
}

$st = $pdo->prepare('SELECT id,status,html_file FROM project_reports WHERE id=?');
$st->execute([$rid]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
  echo json_encode(['ok'=>false,'msg'=>'not found']);
  exit;
}

echo json_encode([
  'ok' => true,
  'status' => (string)($r['status'] ?? ''),
  'html_file' => (string)($r['html_file'] ?? ''),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

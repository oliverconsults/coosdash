<?php
// Migration: add task metrics columns to nodes table
// Run: php scripts/migrate_20260131_task_metrics.php

require __DIR__ . '/../public/functions.inc.php';
$pdo = db();

// Detect existing columns
$cols = $pdo->query("SHOW COLUMNS FROM nodes")->fetchAll(PDO::FETCH_COLUMN, 0);
$have = array_fill_keys($cols, true);

$adds = [];
if (!isset($have['token_in']))  $adds[] = "ADD COLUMN token_in BIGINT UNSIGNED NOT NULL DEFAULT 0";
if (!isset($have['token_out'])) $adds[] = "ADD COLUMN token_out BIGINT UNSIGNED NOT NULL DEFAULT 0";
if (!isset($have['worktime']))  $adds[] = "ADD COLUMN worktime INT UNSIGNED NOT NULL DEFAULT 0";

if (!$adds) {
  echo "OK: columns already present\n";
  exit(0);
}

$sql = "ALTER TABLE nodes\n  " . implode(",\n  ", $adds);
$pdo->exec($sql);

echo "OK: migrated\n";

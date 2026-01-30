<?php
// Create cooscrm.worker_queue table (idempotent)
require_once __DIR__ . '/../public/functions.inc.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS worker_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  node_id BIGINT UNSIGNED NOT NULL,
  prompt_text MEDIUMTEXT NOT NULL,
  selector_meta JSON NULL,
  fail_count INT NOT NULL DEFAULT 0,
  claimed_by VARCHAR(64) NULL,
  claimed_at DATETIME NULL,
  done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at),
  KEY idx_node_status (node_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "OK worker_queue table ready\n";

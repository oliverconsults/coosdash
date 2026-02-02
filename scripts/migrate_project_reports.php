<?php
// Create project_reports table if missing.
require_once __DIR__ . '/../public/functions_v3.inc.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS project_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_node_id INT NOT NULL,
  slug VARCHAR(80) DEFAULT NULL,
  project_title VARCHAR(255) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  req_id VARCHAR(120) DEFAULT NULL,
  prompt_file VARCHAR(255) DEFAULT NULL,
  response_file VARCHAR(255) DEFAULT NULL,
  html_file VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_at DATETIME DEFAULT NULL,
  INDEX idx_project (project_node_id),
  INDEX idx_created (created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// (silent)

-- coosdash CRM database schema (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id BIGINT UNSIGNED NULL,

  type ENUM('idea','project','task','research') NOT NULL DEFAULT 'idea',
  status ENUM('new','accepted','deferred','rejected','active','done') NOT NULL DEFAULT 'new',

  title VARCHAR(40) NOT NULL,
  description TEXT NULL,

  priority TINYINT NULL,
  created_by ENUM('oliver','james') NOT NULL DEFAULT 'james',

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_parent (parent_id),
  KEY idx_type_status (type, status),
  KEY idx_priority (priority),

  CONSTRAINT fk_nodes_parent
    FOREIGN KEY (parent_id) REFERENCES nodes(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS node_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  node_id BIGINT UNSIGNED NOT NULL,
  author ENUM('oliver','james') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note TEXT NOT NULL,

  PRIMARY KEY (id),
  KEY idx_node_time (node_id, created_at),

  CONSTRAINT fk_node_notes_node
    FOREIGN KEY (node_id) REFERENCES nodes(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments: James can publish artifacts (PDF/CSV/images/etc.) via token URLs
CREATE TABLE IF NOT EXISTS node_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  node_id BIGINT UNSIGNED NOT NULL,
  token CHAR(32) NOT NULL,
  orig_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  created_by ENUM('oliver','james') NOT NULL DEFAULT 'james',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_token_file (token, stored_name),
  KEY idx_node_time (node_id, created_at),

  CONSTRAINT fk_node_attachments_node
    FOREIGN KEY (node_id) REFERENCES nodes(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

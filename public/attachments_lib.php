<?php
// Attachment helpers for COOS CRM.

function attachments_allowed_exts(): array {
  return [
    'pdf','png','jpg','jpeg','webp','gif',
    'doc','docx','xls','xlsx','ppt','pptx','csv',
    // optional: plain text
    'txt'
  ];
}

function attachments_store_upload(PDO $pdo, int $nodeId, array $file, string $createdBy): ?array {
  if ($nodeId <= 0) return null;
  if (empty($file) || !isset($file['error'])) return null;
  $errCode = (int)$file['error'];
  if ($errCode === UPLOAD_ERR_NO_FILE) return null;
  if ($errCode !== UPLOAD_ERR_OK) {
    $map = [
      UPLOAD_ERR_INI_SIZE => 'Datei zu groß (PHP upload_max_filesize).',
      UPLOAD_ERR_FORM_SIZE => 'Datei zu groß (Form-Limit).',
      UPLOAD_ERR_PARTIAL => 'Upload unvollständig.',
      UPLOAD_ERR_NO_TMP_DIR => 'Upload fehlgeschlagen (tmp dir fehlt).',
      UPLOAD_ERR_CANT_WRITE => 'Upload fehlgeschlagen (cannot write).',
      UPLOAD_ERR_EXTENSION => 'Upload blockiert (PHP extension).',
    ];
    $msg = $map[$errCode] ?? ('Upload fehlgeschlagen (code ' . $errCode . ').');
    return ['err' => $msg];
  }

  $orig = (string)($file['name'] ?? '');
  $orig = trim($orig);
  if ($orig === '') $orig = 'file';

  // basic filename sanitation
  $stored = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
  $stored = trim($stored, '._');
  if ($stored === '') $stored = 'file';

  $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
  if ($ext === '' || !in_array($ext, attachments_allowed_exts(), true)) {
    return ['err' => 'Dateityp nicht erlaubt.'];
  }

  $size = (int)($file['size'] ?? 0);
  // 25MB limit (server may enforce lower)
  if ($size > 25 * 1024 * 1024) {
    return ['err' => 'Datei zu groß (max 25MB).'];
  }

  $token = bin2hex(random_bytes(16));

  // Prefer project-local artifacts storage for nodes under "Projekte".
  $baseDir = '/var/www/coosdash/shared/att';
  try {
    $slug = project_slug_for_node($pdo, $nodeId);
    if (is_string($slug) && $slug !== '') {
      $baseDir = '/var/www/t/' . $slug . '/shared/artifacts/att';
    }
  } catch (Throwable $e) {
    // fallback to central att
  }

  $destDir = $baseDir . '/' . $token;
  if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
    return ['err' => 'Upload fehlgeschlagen (mkdir).'];
  }

  $dest = $destDir . '/' . $stored;
  if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
    return ['err' => 'Upload fehlgeschlagen (move).'];
  }
  @chmod($dest, 0664);

  $mime = null;
  if (function_exists('mime_content_type')) {
    $m = @mime_content_type($dest);
    if (is_string($m) && $m !== '') $mime = $m;
  }

  $pdo->prepare('INSERT INTO node_attachments (node_id, token, orig_name, stored_name, mime, size_bytes, created_by) VALUES (?,?,?,?,?,?,?)')
      ->execute([$nodeId, $token, $orig, $stored, $mime, $size ?: null, $createdBy]);

  return ['ok' => true, 'url' => '/att/' . $token . '/' . $stored, 'orig' => $orig];
}

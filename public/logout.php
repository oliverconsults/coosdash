<?php
require_once __DIR__ . '/functions_v3.inc.php';

$u = (string)($_SESSION['username'] ?? '');
if ($u !== '') {
  loginlog_append('logout', $u, true);
}

$_SESSION = [];
session_destroy();

header('Location: /login.php');
exit;

<?php
require_once __DIR__ . '/functions_v3.inc.php';

$_SESSION = [];
session_destroy();

header('Location: /login.php');
exit;

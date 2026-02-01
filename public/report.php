<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Report view wrapper (reuses index.php layout)
$_GET['view'] = 'report';
require __DIR__ . '/index.php';

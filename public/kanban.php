<?php
require_once __DIR__ . '/functions_v3.inc.php';
requireLogin();

// Kanban view wrapper (reuses index.php layout)
$_GET['view'] = 'kanban';
require __DIR__ . '/index.php';

<?php
// Legacy entrypoint kept for compatibility.
// Live is no longer a top-level view; redirect to Work.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qs = ['view' => 'work'];
if ($id) $qs['id'] = $id;
if (!empty($_GET['open'])) $qs['open'] = (string)$_GET['open'];
if (!empty($_GET['q'])) $qs['q'] = (string)$_GET['q'];
header('Location: /?' . http_build_query($qs));
exit;

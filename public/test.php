<?php
// Convenience entrypoint for the "Test" view.
// Keeps the existing dashboard navigation/tree and swaps only the right panel.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qs = ['view' => 'test'];
if ($id) $qs['id'] = $id;
if (!empty($_GET['open'])) $qs['open'] = (string)$_GET['open'];
if (!empty($_GET['q'])) $qs['q'] = (string)$_GET['q'];
header('Location: /?' . http_build_query($qs));
exit;

<?php
/**
 * admin/logout.php — Destroys the admin session and redirects to login.
 * No path changes needed here — this file never requires db.php or
 * config files, so moving folders doesn't affect it.
 */
session_start();
$_SESSION = [];
session_destroy();
header('Location: login.php'); // same folder — admin/login.php
exit;

<?php
/**
 * config/auth-guard.php — Reusable admin auth check.
 *
 * Include this at the very top of EVERY admin-only page, before any output
 * and before require-ing db.php, like so:
 *
 *   session_start();
 *   require __DIR__ . '/config/auth-guard.php';
 *   require __DIR__ . '/config/db.php';
 *
 * If no admin is logged in, this immediately redirects to login.php and
 * stops execution — nothing below it on the page ever runs.
 *
 * Depends on: session_start() having already been called by the including
 * page (this file does not call it itself, to avoid a "session already
 * started" notice if the page also needs session_start() for its own reasons
 * before this include).
 */

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

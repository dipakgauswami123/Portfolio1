<?php
/**
 * config/db.php — Database connection for PortfolioForge.
 *
 * This file must live at: <project-root>/config/db.php
 * (i.e. alongside home.php's "config" folder, per its require path).
 *
 * It defines a single PDO instance, $pdo, that every page includes.
 *
 * ------------------------------------------------------------------
 * UPDATE THESE FOUR VALUES for your environment:
 * ------------------------------------------------------------------
 * - DB_HOST: usually "localhost" for local dev or a shared host's DB server
 * - DB_NAME: your database name (set to "student" per your setup)
 * - DB_USER: your MySQL username (defaults to "root" — common on XAMPP/MAMP;
 *            if you're on shared/college hosting, check your hosting control
 *            panel or ask your instructor for the actual DB username)
 * - DB_PASS: your MySQL password (defaults to "" — empty — which matches a
 *            fresh local MySQL/XAMPP install; shared hosting will require one)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'student');
define('DB_USER', 'student');
define('DB_PASS', 'student');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions on query errors instead of silent failures
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // return rows as associative arrays by default
            PDO::ATTR_EMULATE_PREPARES   => false,                 // use real prepared statements (safer against SQL injection)
        ]
    );
} catch (PDOException $e) {
    // In development, showing the message helps you debug connection issues.
    // Before submitting/deploying, replace this with a generic error page —
    // never show raw DB errors to a real visitor.
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

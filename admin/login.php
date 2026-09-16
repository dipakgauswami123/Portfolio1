<?php
/**
 * admin/login.php — PortfolioForge Admin Login page.
 *
 * MOVED: this file now lives in the admin/ subfolder, not the project root.
 * All require paths below use '../config/...' accordingly, since __DIR__
 * now resolves to .../admin, and config/ is one level up.
 *
 * Built against the ACTUAL schema in the `student` database:
 *   admins(id, username, email, password_hash, created_at, last_login_at)
 *   admin_login_attempts(id, ip_address, attempted_at, was_successful)
 */

session_start();
require __DIR__ . '/../config/db.php'; // one level up: config/ lives at the project root

const LOCKOUT_MAX_ATTEMPTS   = 5;
const LOCKOUT_WINDOW_MINUTES = 15;

$visitorIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php'); // same folder — admin/dashboard.php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $failCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM admin_login_attempts
         WHERE ip_address = :ip AND was_successful = 0
           AND attempted_at > NOW() - INTERVAL :window MINUTE"
    );
    $failCountStmt->bindValue(':ip', $visitorIp);
    $failCountStmt->bindValue(':window', LOCKOUT_WINDOW_MINUTES, PDO::PARAM_INT);
    $failCountStmt->execute();
    $recentFailures = (int) $failCountStmt->fetchColumn();

    if ($recentFailures >= LOCKOUT_MAX_ATTEMPTS) {
        $_SESSION['login_error'] = 'Too many failed attempts. Please wait a while before trying again.';
        header('Location: login.php');
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $userStmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = :username LIMIT 1");
    $userStmt->execute([':username' => $username]);
    $admin = $userStmt->fetch(PDO::FETCH_ASSOC);

    $isValid = $admin && password_verify($password, $admin['password_hash']);

    $logStmt = $pdo->prepare(
        "INSERT INTO admin_login_attempts (ip_address, was_successful) VALUES (:ip, :success)"
    );
    $logStmt->execute([':ip' => $visitorIp, ':success' => $isValid ? 1 : 0]);

    if (!$isValid) {
        $_SESSION['login_error'] = 'Incorrect username or password.';
        header('Location: login.php');
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = $admin['id'];

    $updateStmt = $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id");
    $updateStmt->execute([':id' => $admin['id']]);

    header('Location: dashboard.php');
    exit;
}

$loginError = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — PortfolioForge</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --ink: #1C2321; --muted: #8B958F; --rule: #3A443E;
    --accent: #5FAE82; --accent-soft: #24312A; --error: #D97A6C; --error-soft: #3A2420;
    --surface: #202623;
    --serif: 'Source Serif 4', Georgia, serif;
    --mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--ink); color: #E7E9E4; font-family: var(--serif); }
  .login-card { width: 100%; max-width: 360px; background: var(--surface); border: 1px solid var(--rule); border-radius: 6px; padding: 2.5rem 2rem; }
  .login-card .brand { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 1.75rem; }
  .login-card h1 { font-size: 1.4rem; font-weight: 600; margin: 0 0 1.75rem; }
  .flash { font-family: var(--mono); font-size: 0.8rem; padding: 0.7rem 0.9rem; border-radius: 4px; background: var(--error-soft); color: var(--error); margin-bottom: 1.5rem; }
  form { display: flex; flex-direction: column; gap: 1.1rem; }
  .field label { display: block; font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 0.4rem; }
  .field input { width: 100%; font-family: var(--mono); font-size: 0.95rem; padding: 0.55rem 0.65rem; border: 1px solid var(--rule); border-radius: 4px; background: var(--ink); color: #E7E9E4; }
  .field input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  button.submit-btn { margin-top: 0.5rem; font-family: var(--mono); font-size: 0.9rem; padding: 0.6rem; border: 1px solid var(--accent); background: var(--accent-soft); color: var(--accent); border-radius: 4px; cursor: pointer; }
  button.submit-btn:hover { background: var(--accent); color: var(--ink); }
  .back-link { display: block; margin-top: 1.5rem; font-family: var(--mono); font-size: 0.75rem; color: var(--muted); text-decoration: none; }
  .back-link:hover { color: var(--accent); }
</style>
</head>
<body>
  <div class="login-card">
    <p class="brand">portfolioforge / admin</p>
    <h1>Sign in</h1>
    <?php if ($loginError): ?><div class="flash"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="post" action="login.php">
      <div class="field">
        <label for="username">username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="submit-btn">sign in</button>
    </form>
    <a class="back-link" href="../home.php">← back to site</a>
  </div>
</body>
</html>

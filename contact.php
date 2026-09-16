<?php
/**
 * contact.php — PortfolioForge public Contact page.
 *
 * Built against the ACTUAL schema in the `student` database:
 *   contact_messages(id, name, email, message, ip_address, is_read,
 *                     is_archived, created_at)
 *   contact_submission_attempts(id, ip_address, attempted_at, was_blocked)
 *
 * RATE LIMIT DESIGN:
 * contact_submission_attempts logs EVERY attempt — successful, invalid, or
 * blocked — before contact_messages is ever touched. This means the rate
 * limiter counts against attempts, not successful sends, so someone
 * spamming junk input still gets throttled instead of getting free retries.
 *
 * Flow per request:
 *   1. Count attempts from this IP in the last RATE_LIMIT_WINDOW_MINUTES.
 *   2. If over RATE_LIMIT_MAX_ATTEMPTS: log this attempt as blocked, reject.
 *   3. Otherwise: log this attempt as not blocked, then validate the form.
 *      - Honeypot filled in -> treat as a bot, reject silently (no hint why).
 *      - Validation fails -> show a field error, nothing written to
 *        contact_messages (the attempt is still logged either way).
 *      - Validation passes -> insert into contact_messages, show success.
 *   4. Redirect back to this same page (PRG pattern) so refreshing never
 *      re-submits the form.
 */

session_start();
require __DIR__ . '/config/db.php'; // expects $pdo (PDO instance)

const RATE_LIMIT_MAX_ATTEMPTS   = 3;  // max attempts allowed per window
const RATE_LIMIT_WINDOW_MINUTES = 10; // rolling window, in minutes

$visitorIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/** Logs one attempt row, always, regardless of outcome. */
function logAttempt(PDO $pdo, string $ip, bool $wasBlocked): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO contact_submission_attempts (ip_address, was_blocked) VALUES (:ip, :blocked)"
    );
    $stmt->execute([':ip' => $ip, ':blocked' => $wasBlocked ? 1 : 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Step 1: has this IP already hit the limit? -----------------------
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM contact_submission_attempts
         WHERE ip_address = :ip AND attempted_at > NOW() - INTERVAL :window MINUTE"
    );
    $countStmt->bindValue(':ip', $visitorIp);
    $countStmt->bindValue(':window', RATE_LIMIT_WINDOW_MINUTES, PDO::PARAM_INT);
    $countStmt->execute();
    $recentAttempts = (int) $countStmt->fetchColumn();

    if ($recentAttempts >= RATE_LIMIT_MAX_ATTEMPTS) {
        logAttempt($pdo, $visitorIp, true);
        $_SESSION['contact_status'] = [
            'type' => 'rate_limited',
            'message' => "You've sent a few messages already — please wait a bit before trying again.",
        ];
        header('Location: contact.php');
        exit;
    }

    // --- Step 2: honeypot check (hidden field a real visitor never fills) --
    $honeypot = trim($_POST['website'] ?? '');
    if ($honeypot !== '') {
        logAttempt($pdo, $visitorIp, true); // treated as blocked; no hint given to the bot
        header('Location: contact.php');
        exit;
    }

    // --- Step 3: validate the actual fields --------------------------------
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    $errors = [];
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Please enter a name (under 150 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($message === '') {
        $errors[] = 'Please enter a message.';
    }

    logAttempt($pdo, $visitorIp, false); // logged as a real (non-blocked) attempt either way

    if (!empty($errors)) {
        $_SESSION['contact_status'] = [
            'type' => 'error',
            'message' => implode(' ', $errors),
        ];
        // Preserve what they typed so they don't have to retype it.
        $_SESSION['contact_old_input'] = ['name' => $name, 'email' => $email, 'message' => $message];
        header('Location: contact.php');
        exit;
    }

    // --- Step 4: passed validation, store the message ----------------------
    $insertStmt = $pdo->prepare(
        "INSERT INTO contact_messages (name, email, message, ip_address)
         VALUES (:name, :email, :message, :ip)"
    );
    $insertStmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':message' => $message,
        ':ip' => $visitorIp,
    ]);

    $_SESSION['contact_status'] = [
        'type' => 'success',
        'message' => "Thanks — your message is in. I'll get back to you soon.",
    ];
    header('Location: contact.php');
    exit;
}

// --- GET: read and clear any flash status/old input from the last POST ---
$status = $_SESSION['contact_status'] ?? null;
$oldInput = $_SESSION['contact_old_input'] ?? ['name' => '', 'email' => '', 'message' => ''];
unset($_SESSION['contact_status'], $_SESSION['contact_old_input']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact — PortfolioForge</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --paper: #EDEEEA; --ink: #1C2321; --muted: #5B645F; --rule: #C9CCC5;
    --accent: #3F7D5C; --accent-soft: #E4EBE6; --error: #A6473B; --error-soft: #F3E4E1;
    --serif: 'Source Serif 4', Georgia, serif;
    --mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--serif); line-height: 1.55; }
  a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  a:focus-visible, input:focus-visible, textarea:focus-visible, button:focus-visible {
    outline: 2px solid var(--accent); outline-offset: 3px;
  }

  .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }

  .sidebar {
    position: sticky; top: 0; height: 100vh;
    padding: 2.5rem 1.75rem;
    border-right: 1px solid var(--rule);
  }
  .sidebar h1 { font-size: 1.3rem; font-weight: 600; margin: 0 0 0.25rem; }
  .sidebar p.role { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin: 0 0 2rem; }
  nav ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.9rem; }
  nav a { font-family: var(--mono); font-size: 0.85rem; border-bottom: none; color: var(--muted); }
  nav a:hover, nav a.active { color: var(--accent); }

  main { padding: 3.5rem 3rem 4rem; max-width: 560px; }
  main h2.page-title { font-size: 1.6rem; font-weight: 600; margin: 0 0 0.5rem; }
  main p.page-sub { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin: 0 0 2rem; }

  .flash {
    font-family: var(--mono);
    font-size: 0.85rem;
    padding: 0.8rem 1rem;
    border-radius: 4px;
    margin-bottom: 1.75rem;
  }
  .flash.success { background: var(--accent-soft); color: var(--accent); }
  .flash.error, .flash.rate_limited { background: var(--error-soft); color: var(--error); }

  form.contact-form { display: flex; flex-direction: column; gap: 1.25rem; }

  .field label {
    display: block;
    font-family: var(--mono);
    font-size: 0.78rem;
    color: var(--muted);
    margin-bottom: 0.4rem;
  }

  .field input, .field textarea {
    width: 100%;
    font-family: var(--serif);
    font-size: 1rem;
    padding: 0.6rem 0.7rem;
    border: 1px solid var(--rule);
    border-radius: 4px;
    background: #fff;
    color: var(--ink);
  }

  .field textarea { min-height: 140px; resize: vertical; }

  /* Honeypot: hidden from real visitors, but present in the DOM for bots to fill */
  .hp-field { position: absolute; left: -9999px; top: -9999px; }

  button.submit-btn {
    align-self: flex-start;
    font-family: var(--mono);
    font-size: 0.9rem;
    padding: 0.6rem 1.4rem;
    border: 1px solid var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 4px;
    cursor: pointer;
  }
  button.submit-btn:hover { background: var(--accent); color: var(--paper); }

  @media (max-width: 780px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar { position: relative; height: auto; border-right: none; border-bottom: 1px solid var(--rule); padding: 1.5rem; }
    main { padding: 2rem 1.5rem 3rem; }
  }
</style>
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <h1>Dipak Gauswami</h1>
    <p class="role">software developer</p>
    <nav>
      <ul>
        <li><a href="home.php">home</a></li>
        <li><a href="projects.php">projects</a></li>
        <li><a href="skills.php">skills</a></li>
        <li><a href="contact.php" class="active">contact</a></li>
      </ul>
    </nav>
  </aside>

  <main>
    <h2 class="page-title">Get in touch</h2>
    <p class="page-sub">usually reply within a day or two</p>

    <?php if ($status): ?>
      <div class="flash <?= htmlspecialchars($status['type']) ?>">
        <?= htmlspecialchars($status['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$status || $status['type'] !== 'success'): ?>
      <form class="contact-form" method="post" action="contact.php">
        <div class="field">
          <label for="name">name</label>
          <input type="text" id="name" name="name" maxlength="150" value="<?= htmlspecialchars($oldInput['name']) ?>" required>
        </div>

        <div class="field">
          <label for="email">email</label>
          <input type="email" id="email" name="email" maxlength="255" value="<?= htmlspecialchars($oldInput['email']) ?>" required>
        </div>

        <div class="field">
          <label for="message">message</label>
          <textarea id="message" name="message" required><?= htmlspecialchars($oldInput['message']) ?></textarea>
        </div>

        <!-- Honeypot field: real visitors never see or fill this. Leave name as "website". -->
        <div class="field hp-field" aria-hidden="true">
          <label for="website">website</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="submit-btn">send message</button>
      </form>
    <?php endif; ?>
  </main>

</div>
</body>
</html>

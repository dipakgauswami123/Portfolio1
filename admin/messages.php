<?php
/**
 * admin/messages.php — Admin Contact Inbox: list + view + read/unread + archive + delete.
 *
 * Built against the ACTUAL schema:
 *   contact_messages(id, name, email, message, ip_address, is_read,
 *                    is_archived, created_at)
 *
 * Two boxes, switched with ?box=inbox (default) or ?box=archived:
 *   inbox    -> is_archived = 0
 *   archived -> is_archived = 1
 *
 * Opening a message (?view=ID) automatically marks it as read. It can be
 * marked unread again afterwards from the detail panel.
 *
 * All changes are POST + redirect (PRG), same pattern as skills.php.
 * Delete is a hard delete of the single message row (no dependent tables).
 */

session_start();
require __DIR__ . '/../config/auth-guard.php';
require __DIR__ . '/../config/db.php';

// --- Which box are we looking at? ------------------------------------------
$box = (isset($_GET['box']) && $_GET['box'] === 'archived') ? 'archived' : 'inbox';

// --- Handle POST actions (toggle read, archive/unarchive, delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $redirectBox = (isset($_POST['box']) && $_POST['box'] === 'archived') ? 'archived' : 'inbox';

    if ($id > 0) {
        switch ($_POST['action']) {
            case 'mark_read':
                $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = :id")->execute([':id' => $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Marked as read.'];
                break;
            case 'mark_unread':
                $pdo->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = :id")->execute([':id' => $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Marked as unread.'];
                break;
            case 'archive':
                $pdo->prepare("UPDATE contact_messages SET is_archived = 1 WHERE id = :id")->execute([':id' => $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Message archived.'];
                break;
            case 'unarchive':
                $pdo->prepare("UPDATE contact_messages SET is_archived = 0 WHERE id = :id")->execute([':id' => $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Message moved back to inbox.'];
                break;
            case 'delete':
                $pdo->prepare("DELETE FROM contact_messages WHERE id = :id")->execute([':id' => $id]);
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Message deleted.'];
                break;
        }
    }
    header('Location: messages.php?box=' . $redirectBox);
    exit;
}

// --- View mode: load a single message if ?view=ID is present ---------------
$viewing = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare(
        "SELECT id, name, email, message, ip_address, is_read, is_archived, created_at
         FROM contact_messages WHERE id = :id"
    );
    $stmt->execute([':id' => (int) $_GET['view']]);
    $viewing = $stmt->fetch(PDO::FETCH_ASSOC);

    // Opening a message marks it as read automatically.
    if ($viewing && !$viewing['is_read']) {
        $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = :id")->execute([':id' => $viewing['id']]);
        $viewing['is_read'] = 1;
    }
    // Keep the list's box in sync with the message being viewed.
    if ($viewing) {
        $box = $viewing['is_archived'] ? 'archived' : 'inbox';
    }
}

// --- Counts for the tabs and the sidebar badge -----------------------------
$countStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) AS inbox_total,
        SUM(CASE WHEN is_archived = 0 AND is_read = 0 THEN 1 ELSE 0 END) AS inbox_unread,
        SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS archived_total
     FROM contact_messages"
);
$countStmt->execute();
$counts = $countStmt->fetch(PDO::FETCH_ASSOC);
$inboxTotal    = (int) $counts['inbox_total'];
$inboxUnread   = (int) $counts['inbox_unread'];
$archivedTotal = (int) $counts['archived_total'];

// --- List messages for the current box, newest first ------------------------
$listStmt = $pdo->prepare(
    "SELECT id, name, email, message, is_read, created_at
     FROM contact_messages
     WHERE is_archived = :archived
     ORDER BY created_at DESC"
);
$listStmt->bindValue(':archived', $box === 'archived' ? 1 : 0, PDO::PARAM_INT);
$listStmt->execute();
$messages = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

function formatRelativeTime(?string $timestamp): string
{
    if (!$timestamp) return 'never';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — PortfolioForge Admin</title>
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
  body { margin: 0; background: var(--ink); color: #E7E9E4; font-family: var(--serif); line-height: 1.55; }
  a { color: #E7E9E4; text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  .layout { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }
  .sidebar { position: sticky; top: 0; height: 100vh; padding: 2rem 1.5rem; border-right: 1px solid var(--rule); }
  .sidebar .brand { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 2rem; }
  nav ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.9rem; }
  nav a { font-family: var(--mono); font-size: 0.85rem; border-bottom: none; color: var(--muted); }
  nav a:hover, nav a.active { color: var(--accent); }

  main { padding: 2.5rem 3rem 4rem; max-width: 900px; }
  main h2 { font-size: 1.5rem; font-weight: 600; margin: 0 0 1.5rem; }

  .flash { font-family: var(--mono); font-size: 0.82rem; padding: 0.7rem 0.9rem; border-radius: 4px; margin-bottom: 1.5rem; }
  .flash.success { background: var(--accent-soft); color: var(--accent); }
  .flash.error { background: var(--error-soft); color: var(--error); }

  .tabs { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--rule); font-family: var(--mono); font-size: 0.82rem; }
  .tabs a { border-bottom: 2px solid transparent; padding-bottom: 0.6rem; color: var(--muted); }
  .tabs a.active { color: var(--accent); border-bottom-color: var(--accent); }
  .tabs a:hover { color: var(--accent); }

  /* Detail panel */
  .detail {
    background: var(--surface);
    border: 1px solid var(--rule);
    border-radius: 6px;
    padding: 1.5rem;
    margin-bottom: 2rem;
  }
  .detail .meta { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 1rem; line-height: 1.8; }
  .detail .meta strong { color: #E7E9E4; font-weight: 500; }
  .detail .body { font-size: 1rem; white-space: normal; margin-bottom: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--rule); }
  .detail .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
  .detail .actions form { display: inline; }
  .btn {
    font-family: var(--mono); font-size: 0.78rem; padding: 0.4rem 0.9rem;
    border: 1px solid var(--accent); background: var(--accent-soft); color: var(--accent);
    border-radius: 4px; cursor: pointer;
  }
  .btn:hover { background: var(--accent); color: var(--ink); }
  .btn.danger { border-color: var(--error); background: var(--error-soft); color: var(--error); }
  .btn.danger:hover { background: var(--error); color: var(--ink); }
  .detail .close-link { font-family: var(--mono); font-size: 0.78rem; color: var(--muted); }

  /* List */
  table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  th { text-align: left; font-family: var(--mono); font-size: 0.72rem; color: var(--muted); padding: 0.6rem 0.5rem; border-bottom: 1px solid var(--rule); }
  td { padding: 0.75rem 0.5rem; border-bottom: 1px solid var(--rule); vertical-align: top; }
  tr.unread td.sender, tr.unread td.preview { font-weight: 600; }
  .unread-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--accent); margin-right: 0.5rem; }
  .read-dot { display: inline-block; width: 7px; height: 7px; margin-right: 0.5rem; }
  td.preview { color: var(--muted); }
  tr.unread td.preview { color: #E7E9E4; }
  td.when { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); white-space: nowrap; }

  .empty-note { font-family: var(--mono); font-size: 0.85rem; color: var(--muted); }
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <p class="brand">portfolioforge / admin</p>
    <nav>
      <ul>
        <li><a href="dashboard.php">dashboard</a></li>
        <li><a href="projects.php">projects</a></li>
        <li><a href="skills.php">skills</a></li>
        <li><a href="messages.php" class="active">messages<?= $inboxUnread > 0 ? " ($inboxUnread)" : '' ?></a></li>
        <li><a href="../home.php" target="_blank">view site ↗</a></li>
        <li><a href="logout.php">logout</a></li>
      </ul>
    </nav>
  </aside>

  <main>
    <h2>Messages</h2>

    <?php if ($flash): ?>
      <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <div class="tabs">
      <a href="messages.php?box=inbox" class="<?= $box === 'inbox' ? 'active' : '' ?>">
        inbox (<?= $inboxTotal ?><?= $inboxUnread > 0 ? ", $inboxUnread unread" : '' ?>)
      </a>
      <a href="messages.php?box=archived" class="<?= $box === 'archived' ? 'active' : '' ?>">
        archived (<?= $archivedTotal ?>)
      </a>
    </div>

    <?php if ($viewing): ?>
      <div class="detail">
        <div class="meta">
          <strong>from:</strong> <?= htmlspecialchars($viewing['name']) ?>
            &lt;<a href="mailto:<?= htmlspecialchars($viewing['email']) ?>"><?= htmlspecialchars($viewing['email']) ?></a>&gt;<br>
          <strong>received:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($viewing['created_at']))) ?>
            (<?= htmlspecialchars(formatRelativeTime($viewing['created_at'])) ?>)<br>
          <strong>ip address:</strong> <?= htmlspecialchars($viewing['ip_address']) ?>
        </div>

        <div class="body"><?= nl2br(htmlspecialchars($viewing['message'])) ?></div>

        <div class="actions">
          <form method="post" action="messages.php">
            <input type="hidden" name="action" value="mark_unread">
            <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
            <input type="hidden" name="box" value="<?= $box ?>">
            <button type="submit" class="btn">mark unread</button>
          </form>

          <form method="post" action="messages.php">
            <input type="hidden" name="action" value="<?= $viewing['is_archived'] ? 'unarchive' : 'archive' ?>">
            <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
            <input type="hidden" name="box" value="<?= $box ?>">
            <button type="submit" class="btn"><?= $viewing['is_archived'] ? 'move to inbox' : 'archive' ?></button>
          </form>

          <form method="post" action="messages.php" onsubmit="return confirm('Delete this message permanently? This cannot be undone.');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
            <input type="hidden" name="box" value="<?= $box ?>">
            <button type="submit" class="btn danger">delete</button>
          </form>

          <a class="close-link" href="messages.php?box=<?= $box ?>">close</a>
        </div>
      </div>
    <?php elseif (isset($_GET['view'])): ?>
      <div class="flash error">That message no longer exists.</div>
    <?php endif; ?>

    <?php if (empty($messages)): ?>
      <p class="empty-note"><?= $box === 'archived' ? 'No archived messages.' : 'Your inbox is empty.' ?></p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>from</th><th>message</th><th>received</th></tr>
        </thead>
        <tbody>
          <?php foreach ($messages as $msg): ?>
            <tr class="<?= $msg['is_read'] ? '' : 'unread' ?>">
              <td class="sender">
                <span class="<?= $msg['is_read'] ? 'read-dot' : 'unread-dot' ?>"></span>
                <a href="messages.php?view=<?= $msg['id'] ?>"><?= htmlspecialchars($msg['name']) ?></a>
              </td>
              <td class="preview"><?= htmlspecialchars(mb_strimwidth($msg['message'], 0, 90, '…')) ?></td>
              <td class="when"><?= htmlspecialchars(formatRelativeTime($msg['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
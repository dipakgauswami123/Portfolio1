<?php
/**
 * admin/dashboard.php — PortfolioForge Admin Dashboard.
 *
 * MOVED: lives in admin/ now. Paths to config/ use '../config/...'.
 * Nav links to other admin pages (projects.php, skills.php, messages.php)
 * no longer need an "admin-" prefix, since the folder itself provides that
 * namespacing now — admin/projects.php is a completely different file from
 * the public root's projects.php, with no naming collision.
 */

session_start();
require __DIR__ . '/../config/auth-guard.php';
require __DIR__ . '/../config/db.php';

const DECAY_LAMBDA = 0.099;

$adminStmt = $pdo->prepare("SELECT username, last_login_at FROM admins WHERE id = :id");
$adminStmt->execute([':id' => $_SESSION['admin_id']]);
$currentAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT
        p.id, p.title, p.slug, p.is_published,
        COALESCE(SUM(
            CASE ee.event_type WHEN 'like' THEN 3 ELSE 1 END
            * EXP(-:lambda * TIMESTAMPDIFF(DAY, ee.created_at, NOW()))
        ), 0) AS trending_score
     FROM projects p
     LEFT JOIN engagement_events ee ON ee.project_id = p.id
     GROUP BY p.id, p.title, p.slug, p.is_published
     ORDER BY trending_score DESC, p.id DESC
     LIMIT 5"
);
$stmt->bindValue(':lambda', DECAY_LAMBDA);
$stmt->execute();
$trendingProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT
        s.id, s.name,
        COALESCE(SUM(
            CASE ee.event_type WHEN 'like' THEN 3 ELSE 1 END
            * EXP(-:lambda * TIMESTAMPDIFF(DAY, ee.created_at, NOW()))
        ), 0) AS trending_score
     FROM skills s
     LEFT JOIN project_skills ps ON ps.skill_id = s.id
     LEFT JOIN engagement_events ee ON ee.project_id = ps.project_id
     GROUP BY s.id, s.name
     ORDER BY trending_score DESC
     LIMIT 5"
);
$stmt->bindValue(':lambda', DECAY_LAMBDA);
$stmt->execute();
$trendingSkills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0 AND is_archived = 0");
$unreadStmt->execute();
$unreadCount = (int) $unreadStmt->fetchColumn();

$githubStmt = $pdo->prepare(
    "SELECT COUNT(*) AS repo_count, COALESCE(SUM(commit_count), 0) AS total_commits,
            MAX(last_fetched_at) AS last_fetched_at, SUM(fetch_failed) AS failed_count
     FROM github_cache"
);
$githubStmt->execute();
$githubSummary = $githubStmt->fetch(PDO::FETCH_ASSOC);

$loginActivityStmt = $pdo->prepare(
    "SELECT ip_address, attempted_at, was_successful FROM admin_login_attempts ORDER BY attempted_at DESC LIMIT 8"
);
$loginActivityStmt->execute();
$loginActivity = $loginActivityStmt->fetchAll(PDO::FETCH_ASSOC);

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
<title>Dashboard — PortfolioForge Admin</title>
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
  .sidebar { position: sticky; top: 0; height: 100vh; padding: 2rem 1.5rem; border-right: 1px solid var(--rule); display: flex; flex-direction: column; justify-content: space-between; }
  .sidebar .brand { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 2rem; }
  nav ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.9rem; }
  nav a { font-family: var(--mono); font-size: 0.85rem; border-bottom: none; color: var(--muted); }
  nav a:hover, nav a.active { color: var(--accent); }
  .sidebar .admin-info { font-family: var(--mono); font-size: 0.72rem; color: var(--muted); border-top: 1px solid var(--rule); padding-top: 1rem; }
  main { padding: 2.5rem 3rem 4rem; max-width: 900px; }
  main h2.page-title { font-size: 1.5rem; font-weight: 600; margin: 0 0 2rem; }
  .stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2.5rem; }
  .stat-card { background: var(--surface); border: 1px solid var(--rule); border-radius: 6px; padding: 1.1rem 1.25rem; }
  .stat-card .label { font-family: var(--mono); font-size: 0.72rem; color: var(--muted); margin-bottom: 0.4rem; }
  .stat-card .value { font-size: 1.5rem; font-weight: 600; }
  .stat-card .sub { font-family: var(--mono); font-size: 0.7rem; color: var(--muted); margin-top: 0.3rem; }
  .stat-card .value.warn { color: var(--error); }
  .panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2.5rem; }
  @media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } .stat-row { grid-template-columns: 1fr; } }
  .panel { background: var(--surface); border: 1px solid var(--rule); border-radius: 6px; padding: 1.25rem 1.5rem; }
  .panel h3 { font-size: 0.95rem; font-weight: 600; margin: 0 0 1rem; }
  .rank-list { list-style: none; margin: 0; padding: 0; }
  .rank-list li { display: flex; justify-content: space-between; align-items: baseline; padding: 0.5rem 0; border-bottom: 1px solid var(--rule); font-size: 0.9rem; }
  .rank-list li:last-child { border-bottom: none; }
  .rank-list .draft-tag { font-family: var(--mono); font-size: 0.65rem; color: var(--error); background: var(--error-soft); padding: 0.1rem 0.4rem; border-radius: 3px; margin-left: 0.5rem; }
  .rank-list .score { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); }
  .activity-list { list-style: none; margin: 0; padding: 0; font-family: var(--mono); font-size: 0.78rem; }
  .activity-list li { display: flex; justify-content: space-between; padding: 0.4rem 0; border-bottom: 1px solid var(--rule); }
  .activity-list li:last-child { border-bottom: none; }
  .activity-list .ok { color: var(--accent); }
  .activity-list .fail { color: var(--error); }
  .empty-note { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); }
</style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div>
      <p class="brand">portfolioforge / admin</p>
      <nav>
        <ul>
          <li><a href="dashboard.php" class="active">dashboard</a></li>
          <li><a href="projects.php">projects</a></li>
          <li><a href="skills.php">skills</a></li>
          <li><a href="messages.php">messages<?= $unreadCount > 0 ? " ($unreadCount)" : '' ?></a></li>
          <li><a href="../home.php" target="_blank">view site ↗</a></li>
          <li><a href="logout.php">logout</a></li>
        </ul>
      </nav>
    </div>
    <?php if ($currentAdmin): ?>
      <div class="admin-info">
        signed in as <?= htmlspecialchars($currentAdmin['username']) ?><br>
        last login: <?= htmlspecialchars(formatRelativeTime($currentAdmin['last_login_at'])) ?>
      </div>
    <?php endif; ?>
  </aside>

  <main>
    <h2 class="page-title">Dashboard</h2>
    <div class="stat-row">
      <div class="stat-card">
        <div class="label">unread messages</div>
        <div class="value <?= $unreadCount > 0 ? 'warn' : '' ?>"><?= $unreadCount ?></div>
      </div>
      <div class="stat-card">
        <div class="label">github sync</div>
        <div class="value"><?= (int) $githubSummary['repo_count'] ?> repos</div>
        <div class="sub">
          last synced <?= htmlspecialchars(formatRelativeTime($githubSummary['last_fetched_at'])) ?>
          <?php if ($githubSummary['failed_count'] > 0): ?>
            &middot; <span style="color: var(--error);"><?= (int) $githubSummary['failed_count'] ?> failed</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="label">total commits tracked</div>
        <div class="value"><?= (int) $githubSummary['total_commits'] ?></div>
      </div>
    </div>

    <div class="panel-grid">
      <div class="panel">
        <h3>Top trending projects</h3>
        <?php if (empty($trendingProjects)): ?>
          <p class="empty-note">No engagement data yet.</p>
        <?php else: ?>
          <ul class="rank-list">
            <?php foreach ($trendingProjects as $p): ?>
              <li>
                <span><?= htmlspecialchars($p['title']) ?><?php if (!$p['is_published']): ?><span class="draft-tag">draft</span><?php endif; ?></span>
                <span class="score"><?= number_format((float) $p['trending_score'], 2) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="panel">
        <h3>Top trending skills</h3>
        <?php if (empty($trendingSkills)): ?>
          <p class="empty-note">No engagement data yet.</p>
        <?php else: ?>
          <ul class="rank-list">
            <?php foreach ($trendingSkills as $s): ?>
              <li><span><?= htmlspecialchars($s['name']) ?></span><span class="score"><?= number_format((float) $s['trending_score'], 2) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Recent login activity</h3>
      <?php if (empty($loginActivity)): ?>
        <p class="empty-note">No login attempts recorded yet.</p>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($loginActivity as $attempt): ?>
            <li>
              <span class="<?= $attempt['was_successful'] ? 'ok' : 'fail' ?>"><?= $attempt['was_successful'] ? 'success' : 'failed' ?></span>
              <span><?= htmlspecialchars($attempt['ip_address']) ?></span>
              <span><?= htmlspecialchars(formatRelativeTime($attempt['attempted_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>

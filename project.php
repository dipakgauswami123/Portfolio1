<?php
/**
 * project.php — PortfolioForge public Project Detail page (/projects/{slug}).
 *
 * Built against the ACTUAL schema in the `student` database:
 *   projects(id, title, slug, description, repo_url, live_url,
 *            github_repo_full_name, is_published, created_at, updated_at)
 *   skills(id, name, slug, created_at)
 *   project_skills(project_id, skill_id)
 *   github_cache(id, project_id, repo_full_name, stars_count, forks_count,
 *                commit_count, top_languages_json, last_fetched_at, fetch_failed)
 *   engagement_events(id, project_id, event_type, visitor_key, created_at)
 *
 * ROUTING: this file expects the slug as ?slug=... . To get clean URLs like
 * /projects/task-tracker-api, add this to your .htaccess (Apache) — see the
 * project.htaccess snippet provided alongside this file:
 *
 *   RewriteEngine On
 *   RewriteRule ^projects/([a-zA-Z0-9\-]+)/?$ project.php?slug=$1 [L,QSA]
 *
 * FLAG: no image column exists on `projects` in this schema — same
 * limitation noted on the listing page. This page is text + links only.
 *
 * NOTE ON github_cache: unlike the Home page (which had to aggregate across
 * every row to fake a sitewide summary), this page is the natural home for
 * github_cache — it's stored per project, so it maps directly to "this
 * project's own repo stats," no aggregation needed.
 */

session_start();
require __DIR__ . '/config/db.php'; // expects $pdo (PDO instance)

// A stable, non-identifying visitor key derived from the session id.
// Used to prevent one visitor from stacking duplicate likes, and to avoid
// counting a rapid page-refresh as a fresh view every time.
$visitorKey = hash('sha256', session_id());

// --- Resolve the project by slug -----------------------------------------
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') {
    http_response_code(404);
    die('Project not found.');
}

$stmt = $pdo->prepare(
    "SELECT id, title, slug, description, repo_url, live_url,
            github_repo_full_name, created_at, updated_at
     FROM projects
     WHERE slug = :slug AND is_published = 1
     LIMIT 1"
);
$stmt->execute([':slug' => $slug]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    http_response_code(404);
    die('Project not found.');
}

$projectId = (int) $project['id'];

// --- Handle a like submission (POST), then redirect (PRG pattern) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'like') {
    // Prevent duplicate likes from the same visitor on the same project.
    $checkStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM engagement_events
         WHERE project_id = :pid AND event_type = 'like' AND visitor_key = :vkey"
    );
    $checkStmt->execute([':pid' => $projectId, ':vkey' => $visitorKey]);
    $alreadyLiked = (int) $checkStmt->fetchColumn() > 0;

    if (!$alreadyLiked) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO engagement_events (project_id, event_type, visitor_key)
             VALUES (:pid, 'like', :vkey)"
        );
        $insertStmt->execute([':pid' => $projectId, ':vkey' => $visitorKey]);
    }

    // Redirect back to the same page (GET) so refreshing never re-submits the like.
    header('Location: project.php?slug=' . urlencode($project['slug']));
    exit;
}

// --- Log a view event, but only once per visitor per 30-minute window -----
// This stops a visitor mindlessly refreshing from inflating the trending score.
$viewCheckStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM engagement_events
     WHERE project_id = :pid AND event_type = 'view' AND visitor_key = :vkey
       AND created_at > NOW() - INTERVAL 30 MINUTE"
);
$viewCheckStmt->execute([':pid' => $projectId, ':vkey' => $visitorKey]);
$recentlyViewed = (int) $viewCheckStmt->fetchColumn() > 0;

if (!$recentlyViewed) {
    $logViewStmt = $pdo->prepare(
        "INSERT INTO engagement_events (project_id, event_type, visitor_key)
         VALUES (:pid, 'view', :vkey)"
    );
    $logViewStmt->execute([':pid' => $projectId, ':vkey' => $visitorKey]);
}

// --- Current like count + whether this visitor has already liked ---------
$likeCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM engagement_events WHERE project_id = :pid AND event_type = 'like'"
);
$likeCountStmt->execute([':pid' => $projectId]);
$likeCount = (int) $likeCountStmt->fetchColumn();

$hasLikedStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM engagement_events
     WHERE project_id = :pid AND event_type = 'like' AND visitor_key = :vkey"
);
$hasLikedStmt->execute([':pid' => $projectId, ':vkey' => $visitorKey]);
$hasLiked = (int) $hasLikedStmt->fetchColumn() > 0;

// --- Tags for this project, via project_skills ----------------------------
$tagStmt = $pdo->prepare(
    "SELECT s.name, s.slug
     FROM project_skills ps
     JOIN skills s ON s.id = ps.skill_id
     WHERE ps.project_id = :pid
     ORDER BY s.name"
);
$tagStmt->execute([':pid' => $projectId]);
$tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

// --- This project's own GitHub cache row (per-project, per this schema) --
$githubStmt = $pdo->prepare(
    "SELECT repo_full_name, stars_count, forks_count, commit_count,
            top_languages_json, last_fetched_at, fetch_failed
     FROM github_cache
     WHERE project_id = :pid
     LIMIT 1"
);
$githubStmt->execute([':pid' => $projectId]);
$githubData = $githubStmt->fetch(PDO::FETCH_ASSOC);

$topLanguages = [];
if ($githubData && !empty($githubData['top_languages_json'])) {
    $topLanguages = json_decode($githubData['top_languages_json'], true) ?: [];
}

function formatRelativeTime(?string $timestamp): string
{
    if (!$timestamp) {
        return 'never';
    }
    $diffSeconds = time() - strtotime($timestamp);
    if ($diffSeconds < 60) return $diffSeconds . 's ago';
    if ($diffSeconds < 3600) return floor($diffSeconds / 60) . 'm ago';
    if ($diffSeconds < 86400) return floor($diffSeconds / 3600) . 'h ago';
    return floor($diffSeconds / 86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($project['title']) ?> — PortfolioForge</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --paper: #EDEEEA; --ink: #1C2321; --muted: #5B645F; --rule: #C9CCC5;
    --accent: #3F7D5C; --accent-soft: #E4EBE6;
    --serif: 'Source Serif 4', Georgia, serif;
    --mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--serif); line-height: 1.6; }
  a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  a:focus-visible, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }

  .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
  .sidebar {
    position: sticky; top: 0; height: 100vh; padding: 2.5rem 1.75rem;
    border-right: 1px solid var(--rule);
  }
  .sidebar h1 { font-size: 1.3rem; font-weight: 600; margin: 0 0 0.25rem; }
  .sidebar p.role { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin: 0 0 2rem; }
  nav ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.9rem; }
  nav a { font-family: var(--mono); font-size: 0.85rem; border-bottom: none; color: var(--muted); }
  nav a:hover { color: var(--accent); }

  main { padding: 3.5rem 3rem 4rem; max-width: 700px; }

  .breadcrumb { font-family: var(--mono); font-size: 0.78rem; color: var(--muted); margin-bottom: 1.5rem; }
  .breadcrumb a { border-bottom: none; }

  h2.project-title { font-size: 1.8rem; font-weight: 600; margin: 0 0 1rem; }

  .tech-tags { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1.75rem; }
  .tech-tags span {
    font-family: var(--mono); font-size: 0.75rem; color: var(--accent);
    background: var(--accent-soft); padding: 0.15rem 0.55rem; border-radius: 3px;
  }

  .description { font-size: 1.05rem; margin-bottom: 2rem; }

  .action-row { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 2.5rem; flex-wrap: wrap; }

  .link-group { display: flex; gap: 1.25rem; font-family: var(--mono); font-size: 0.85rem; }

  .like-form button {
    font-family: var(--mono);
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
    border: 1px solid var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 3px;
    cursor: pointer;
  }
  .like-form button:hover:not(:disabled) { background: var(--accent); color: var(--paper); }
  .like-form button:disabled { opacity: 0.6; cursor: default; }
  .like-count { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin-left: 0.5rem; }

  section.github-box {
    border: 1px solid var(--rule);
    border-radius: 4px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
    font-family: var(--mono);
    font-size: 0.82rem;
  }
  section.github-box h3 {
    font-size: 0.85rem; font-family: var(--serif); font-weight: 600;
    margin: 0 0 0.9rem;
  }
  .github-stat-row { display: flex; justify-content: space-between; padding: 0.15rem 0; color: var(--muted); }
  .github-stat-row .value { color: var(--ink); }
  .status-dot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: var(--accent); margin-right: 0.4rem;
  }
  .status-dot.failed { background: #A6473B; }

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
    <h1>Your Name</h1>
    <p class="role">software developer</p>
    <nav>
      <ul>
        <li><a href="projects.php">projects</a></li>
        <li><a href="skills.php">skills</a></li>
        <li><a href="contact.php">contact</a></li>
      </ul>
    </nav>
  </aside>

  <main>
    <div class="breadcrumb"><a href="projects.php">← all projects</a></div>

    <h2 class="project-title"><?= htmlspecialchars($project['title']) ?></h2>

    <?php if (!empty($tags)): ?>
      <div class="tech-tags">
        <?php foreach ($tags as $tag): ?>
          <span><?= htmlspecialchars($tag['name']) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="description"><?= nl2br(htmlspecialchars($project['description'])) ?></p>

    <div class="action-row">
      <div class="link-group">
        <?php if (!empty($project['repo_url'])): ?>
          <a href="<?= htmlspecialchars($project['repo_url']) ?>" target="_blank" rel="noopener">view repo</a>
        <?php endif; ?>
        <?php if (!empty($project['live_url'])): ?>
          <a href="<?= htmlspecialchars($project['live_url']) ?>" target="_blank" rel="noopener">live demo</a>
        <?php endif; ?>
      </div>

      <form class="like-form" method="post" action="project.php?slug=<?= urlencode($project['slug']) ?>">
        <input type="hidden" name="action" value="like">
        <button type="submit" <?= $hasLiked ? 'disabled' : '' ?>>
          <?= $hasLiked ? 'liked' : 'like this project' ?>
        </button>
        <span class="like-count"><?= $likeCount ?> like<?= $likeCount === 1 ? '' : 's' ?></span>
      </form>
    </div>

    <?php if ($githubData): ?>
      <section class="github-box">
        <h3>Repository stats</h3>
        <div class="github-stat-row">
          <span><span class="status-dot <?= $githubData['fetch_failed'] ? 'failed' : '' ?>"></span>last synced</span>
          <span class="value"><?= htmlspecialchars(formatRelativeTime($githubData['last_fetched_at'])) ?></span>
        </div>
        <div class="github-stat-row">
          <span>repo</span>
          <span class="value"><?= htmlspecialchars($githubData['repo_full_name']) ?></span>
        </div>
        <div class="github-stat-row">
          <span>stars</span>
          <span class="value"><?= (int) $githubData['stars_count'] ?></span>
        </div>
        <div class="github-stat-row">
          <span>forks</span>
          <span class="value"><?= (int) $githubData['forks_count'] ?></span>
        </div>
        <div class="github-stat-row">
          <span>commits</span>
          <span class="value"><?= (int) $githubData['commit_count'] ?></span>
        </div>
        <?php if (!empty($topLanguages)): ?>
          <div class="github-stat-row">
            <span>top language</span>
            <span class="value"><?= htmlspecialchars(array_key_first($topLanguages)) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($githubData['fetch_failed']): ?>
          <div class="github-stat-row"><span colspan="2" style="color:#A6473B;">last sync attempt failed — showing cached data</span></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

  </main>

</div>
</body>
</html>
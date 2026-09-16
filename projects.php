<?php
/**
 * projects.php — PortfolioForge public Projects Listing page (/projects).
 *
 * Built against the ACTUAL schema in the `student` database:
 *   projects(id, title, slug, description, repo_url, live_url,
 *            github_repo_full_name, is_published, created_at, updated_at)
 *   skills(id, name, slug, created_at)
 *   project_skills(project_id, skill_id)
 *   engagement_events(id, project_id, event_type, visitor_key, created_at)
 *
 * FLAG: `projects` has no image column in this schema, so project cards
 * below are text-only (title, description, tags, links). If you want cover
 * images, you'd need to add an `image_path` column to `projects` — nothing
 * currently in the schema supports it.
 *
 * Filtering is done via the project_skills join table (a real many-to-many
 * relationship), not string-parsing — this is more correct than the
 * original tech_stack-string plan and was possible because of how this
 * schema was actually built.
 */

require __DIR__ . '/config/db.php'; // expects $pdo (PDO instance)

const DECAY_LAMBDA = 0.099; // same decay constant used on the Home page — keep these in sync

// --- Read + validate query params (filter by skill, sort mode) -----------
$skillFilter = isset($_GET['skill']) ? trim($_GET['skill']) : '';
$sortMode    = isset($_GET['sort']) && $_GET['sort'] === 'trending' ? 'trending' : 'newest';

// --- Filter options: only skills actually attached to a published project
$skillOptionsStmt = $pdo->prepare(
    "SELECT DISTINCT s.slug, s.name
     FROM skills s
     JOIN project_skills ps ON ps.skill_id = s.id
     JOIN projects p ON p.id = ps.project_id
     WHERE p.is_published = 1
     ORDER BY s.name"
);
$skillOptionsStmt->execute();
$skillOptions = $skillOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Build the main project query, with optional skill filter ------------
$sql = "SELECT
            p.id, p.title, p.slug, p.description, p.repo_url, p.live_url, p.created_at,
            COALESCE(SUM(
                CASE ee.event_type WHEN 'like' THEN 3 ELSE 1 END
                * EXP(-:lambda * TIMESTAMPDIFF(DAY, ee.created_at, NOW()))
            ), 0) AS trending_score
        FROM projects p
        LEFT JOIN engagement_events ee ON ee.project_id = p.id";

$params = [':lambda' => DECAY_LAMBDA];

if ($skillFilter !== '') {
    $sql .= " INNER JOIN project_skills fps ON fps.project_id = p.id
              INNER JOIN skills fs ON fs.id = fps.skill_id AND fs.slug = :skillFilter";
    $params[':skillFilter'] = $skillFilter;
}

$sql .= " WHERE p.is_published = 1
          GROUP BY p.id, p.title, p.slug, p.description, p.repo_url, p.live_url, p.created_at";

$sql .= $sortMode === 'trending'
    ? " ORDER BY trending_score DESC, p.created_at DESC"
    : " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Tags for the visible projects, via project_skills -------------------
$projectTags = [];
if (!empty($projects)) {
    $ids = array_column($projects, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $pdo->prepare(
        "SELECT ps.project_id, s.name, s.slug
         FROM project_skills ps
         JOIN skills s ON s.id = ps.skill_id
         WHERE ps.project_id IN ($placeholders)"
    );
    $tagStmt->execute($ids);
    foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $projectTags[$row['project_id']][] = $row;
    }
}

/** Builds a query string preserving the other filter param, for the sort/filter links. */
function buildQuery(string $skill, string $sort): string
{
    $params = array_filter(['skill' => $skill, 'sort' => $sort], fn($v) => $v !== '' && $v !== 'newest');
    return $params ? '?' . http_build_query($params) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Projects — PortfolioForge</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --paper: #EDEEEA;
    --ink: #1C2321;
    --muted: #5B645F;
    --rule: #C9CCC5;
    --accent: #3F7D5C;
    --accent-soft: #E4EBE6;
    --serif: 'Source Serif 4', Georgia, serif;
    --mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--serif); line-height: 1.55; }
  a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  a:focus-visible, select:focus-visible, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }

  .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }

  .sidebar {
    position: sticky; top: 0; height: 100vh;
    padding: 2.5rem 1.75rem;
    border-right: 1px solid var(--rule);
    display: flex; flex-direction: column; justify-content: space-between;
  }
  .sidebar-top h1 { font-size: 1.3rem; font-weight: 600; margin: 0 0 0.25rem; }
  .sidebar-top p.role { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin: 0 0 2rem; }
  nav.primary-nav ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.9rem; }
  nav.primary-nav a { font-family: var(--mono); font-size: 0.85rem; border-bottom: none; color: var(--muted); }
  nav.primary-nav a:hover, nav.primary-nav a.active { color: var(--accent); }

  main { padding: 3.5rem 3rem 4rem; max-width: 760px; }

  main h2.page-title { font-size: 1.6rem; font-weight: 600; margin: 0 0 2rem; }

  .filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25rem;
    align-items: center;
    padding-bottom: 1.5rem;
    margin-bottom: 2rem;
    border-bottom: 1px solid var(--rule);
    font-family: var(--mono);
    font-size: 0.85rem;
  }
  .filter-bar label { color: var(--muted); margin-right: 0.5rem; }
  .filter-bar select {
    font-family: var(--mono);
    font-size: 0.85rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid var(--rule);
    background: var(--paper);
    color: var(--ink);
    border-radius: 3px;
  }
  .filter-bar button {
    font-family: var(--mono);
    font-size: 0.8rem;
    padding: 0.4rem 0.8rem;
    border: 1px solid var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 3px;
    cursor: pointer;
  }
  .filter-bar button:hover { background: var(--accent); color: var(--paper); }
  .filter-bar .clear-link { color: var(--muted); font-size: 0.8rem; }

  .project-grid { display: flex; flex-direction: column; gap: 0; }

  .project-card {
    display: grid;
    grid-template-columns: 2rem 1fr;
    gap: 0.75rem;
    padding: 1.4rem 0;
    border-bottom: 1px solid var(--rule);
  }
  .project-card:last-child { border-bottom: none; }

  .rank-badge { font-family: var(--mono); font-size: 0.85rem; color: var(--muted); padding-top: 0.2rem; }

  .project-card h3 { margin: 0 0 0.35rem; font-size: 1.1rem; font-weight: 600; }
  .project-card p.desc { margin: 0 0 0.6rem; color: var(--muted); font-size: 0.95rem; }

  .tech-tags { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.6rem; }
  .tech-tags a {
    font-family: var(--mono);
    font-size: 0.72rem;
    color: var(--accent);
    background: var(--accent-soft);
    padding: 0.15rem 0.5rem;
    border-radius: 3px;
    border-bottom: none;
  }
  .tech-tags a:hover { background: var(--accent); color: var(--paper); }

  .card-links { display: flex; gap: 1rem; font-family: var(--mono); font-size: 0.78rem; }

  .empty-state { color: var(--muted); font-family: var(--mono); font-size: 0.9rem; }

  @media (max-width: 780px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar {
      position: relative; height: auto;
      flex-direction: row; flex-wrap: wrap; justify-content: space-between; align-items: center;
      border-right: none; border-bottom: 1px solid var(--rule);
      padding: 1.5rem;
    }
    .sidebar-top { display: flex; align-items: baseline; gap: 1rem; }
    .sidebar-top p.role { margin: 0; }
    nav.primary-nav ul { flex-direction: row; gap: 1.25rem; }
    main { padding: 2rem 1.5rem 3rem; }
  }
  @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <div class="sidebar-top">
      <h1>Your Name</h1>
      <p class="role">software developer</p>
      <nav class="primary-nav">
        <ul>
        <li><a href="home.php">Home</a></li>
          <li><a href="projects.php" class="active">projects</a></li>
          <li><a href="skills.php">skills</a></li>
          <li><a href="contact.php">contact</a></li>
        </ul>
      </nav>
    </div>
  </aside>

  <main>
    <h2 class="page-title">Projects</h2>

    <form class="filter-bar" method="get" action="/projects">
      <div>
        <label for="skill">tag</label>
        <select id="skill" name="skill" onchange="this.form.submit()">
          <option value="">all</option>
          <?php foreach ($skillOptions as $opt): ?>
            <option value="<?= htmlspecialchars($opt['slug']) ?>" <?= $skillFilter === $opt['slug'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($opt['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="sort">sort</label>
        <select id="sort" name="sort" onchange="this.form.submit()">
          <option value="newest" <?= $sortMode === 'newest' ? 'selected' : '' ?>>newest</option>
          <option value="trending" <?= $sortMode === 'trending' ? 'selected' : '' ?>>trending</option>
        </select>
      </div>

      <noscript><button type="submit">apply</button></noscript>

      <?php if ($skillFilter !== '' || $sortMode !== 'newest'): ?>
        <a class="clear-link" href="/projects">clear filters</a>
      <?php endif; ?>
    </form>

    <div class="project-grid">
      <?php if (empty($projects)): ?>
        <p class="empty-state">No published projects match this filter.</p>
      <?php else: ?>
        <?php foreach ($projects as $i => $project): ?>
          <div class="project-card">
            <?php if ($sortMode === 'trending'): ?>
              <div class="rank-badge"><?= $i + 1 ?></div>
            <?php else: ?>
              <div class="rank-badge">—</div>
            <?php endif; ?>
            <div>
              <h3><a href="project.php?slug=<?= urlencode($project['slug']) ?>"><?= htmlspecialchars($project['title']) ?></a></h3>
              <p class="desc"><?= htmlspecialchars(mb_strimwidth($project['description'], 0, 160, '…')) ?></p>

              <?php if (!empty($projectTags[$project['id']])): ?>
                <div class="tech-tags">
                  <?php foreach ($projectTags[$project['id']] as $tag): ?>
                    <a href="<?= buildQuery($tag['slug'], $sortMode) ?>"><?= htmlspecialchars($tag['name']) ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="card-links">
                <?php if (!empty($project['repo_url'])): ?>
                  <a href="<?= htmlspecialchars($project['repo_url']) ?>" target="_blank" rel="noopener">repo</a>
                <?php endif; ?>
                <?php if (!empty($project['live_url'])): ?>
                  <a href="<?= htmlspecialchars($project['live_url']) ?>" target="_blank" rel="noopener">live</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>

</div>
</body>
</html>
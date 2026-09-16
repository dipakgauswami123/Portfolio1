<?php
/**
 * home.php — PortfolioForge public Home page.
 *
 * Rewritten against the ACTUAL schema in the `student` database:
 *   projects(id, title, slug, description, repo_url, live_url,
 *            github_repo_full_name, is_published, created_at, updated_at)
 *   skills(id, name, slug, created_at)
 *   project_skills(project_id, skill_id)                -- join table
 *   github_cache(id, project_id, repo_full_name, stars_count, forks_count,
 *                commit_count, top_languages_json, last_fetched_at, fetch_failed)
 *   engagement_events(id, project_id, event_type, visitor_key, created_at)
 *
 * IMPORTANT DIFFERENCE FROM THE ORIGINAL DESIGN:
 * This schema has no cached trending_score column, so trending is computed
 * LIVE on every request via a decay-weighted SUM over engagement_events.
 * That's fine at this project's scale, but if the dataset grows large,
 * this is the first place to add caching (a scheduled job writing a
 * trending_score column back onto projects/skills, as originally planned).
 *
 * Also: github_cache is stored PER PROJECT (one row per repo), not one
 * sitewide row. The sidebar "system status" block below aggregates across
 * all github_cache rows to approximate a sitewide summary.
 */

require __DIR__ . '/config/db.php'; // expects $pdo (PDO instance)

// Decay constant: half-life of 7 days. Tune this to change how fast old
// engagement stops counting. age_days = 0 -> weight 1.0; age_days = 7 -> weight 0.5.
const DECAY_LAMBDA = 0.099; // ln(2) / 7, precomputed

// --- Top trending projects (published only), score computed live ---------
$stmt = $pdo->prepare(
    "SELECT
        p.id, p.title, p.slug, p.description,
        COALESCE(SUM(
            CASE ee.event_type WHEN 'like' THEN 3 ELSE 1 END
            * EXP(-:lambda * TIMESTAMPDIFF(DAY, ee.created_at, NOW()))
        ), 0) AS trending_score
     FROM projects p
     LEFT JOIN engagement_events ee ON ee.project_id = p.id
     WHERE p.is_published = 1
     GROUP BY p.id, p.title, p.slug, p.description
     ORDER BY trending_score DESC, p.created_at DESC
     LIMIT 5"
);
$stmt->bindValue(':lambda', DECAY_LAMBDA);
$stmt->execute();
$trendingProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Tags for those projects, via the project_skills join table ----------
$projectTags = [];
if (!empty($trendingProjects)) {
    $ids = array_column($trendingProjects, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $pdo->prepare(
        "SELECT ps.project_id, s.name
         FROM project_skills ps
         JOIN skills s ON s.id = ps.skill_id
         WHERE ps.project_id IN ($placeholders)"
    );
    $tagStmt->execute($ids);
    foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $projectTags[$row['project_id']][] = $row['name'];
    }
}

// --- Trending skills: roll up engagement from each skill's linked projects
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
     LIMIT 12"
);
$stmt->bindValue(':lambda', DECAY_LAMBDA);
$stmt->execute();
$trendingSkills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maxSkillScore = 0.0;
foreach ($trendingSkills as $s) {
    $maxSkillScore = max($maxSkillScore, (float) $s['trending_score']);
}

// --- GitHub cache: aggregate across all per-project rows to approximate
//     a sitewide summary (schema caches GitHub data per project, not globally)
$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS repo_count,
        COALESCE(SUM(commit_count), 0) AS total_commits,
        COALESCE(SUM(stars_count), 0) AS total_stars,
        MAX(last_fetched_at) AS last_fetched_at,
        SUM(fetch_failed) AS failed_count
     FROM github_cache"
);
$stmt->execute();
$githubSummary = $stmt->fetch(PDO::FETCH_ASSOC);

/**
 * Formats a timestamp as a short relative string ("45m ago", "2d ago").
 */
function formatRelativeTime(?string $timestamp): string
{
    if (!$timestamp) {
        return 'never';
    }
    $diffSeconds = time() - strtotime($timestamp);
    if ($diffSeconds < 60) {
        return $diffSeconds . 's ago';
    }
    if ($diffSeconds < 3600) {
        return floor($diffSeconds / 60) . 'm ago';
    }
    if ($diffSeconds < 86400) {
        return floor($diffSeconds / 3600) . 'h ago';
    }
    return floor($diffSeconds / 86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PortfolioForge</title>
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

  body {
    margin: 0;
    background: var(--paper);
    color: var(--ink);
    font-family: var(--serif);
    line-height: 1.55;
  }

  a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  a:focus-visible, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }

  .layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    min-height: 100vh;
  }

  .sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    padding: 2.5rem 1.75rem;
    border-right: 1px solid var(--rule);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }

  .sidebar-top h1 { font-size: 1.3rem; font-weight: 600; margin: 0 0 0.25rem; }

  .sidebar-top p.role {
    font-family: var(--mono);
    font-size: 0.8rem;
    color: var(--muted);
    margin: 0 0 2rem;
  }

  nav.primary-nav ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
  }

  nav.primary-nav a {
    font-family: var(--mono);
    font-size: 0.85rem;
    border-bottom: none;
    color: var(--muted);
  }

  nav.primary-nav a:hover { color: var(--accent); }

  .status-block {
    font-family: var(--mono);
    font-size: 0.75rem;
    color: var(--muted);
    border-top: 1px solid var(--rule);
    padding-top: 1rem;
    margin-top: 2rem;
  }

  .status-block .status-line { display: flex; justify-content: space-between; padding: 0.15rem 0; }
  .status-block .status-line .value { color: var(--ink); }

  .status-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
    margin-right: 0.4rem;
  }
  .status-dot.failed { background: #A6473B; }

  main { padding: 3.5rem 3rem 4rem; max-width: 720px; }

  .hero p { font-size: 1.15rem; max-width: 62ch; margin: 0 0 3rem; }

  section { margin-bottom: 3.25rem; }

  section h2 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 1.25rem;
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--rule);
  }

  ol.project-list { list-style: none; margin: 0; padding: 0; counter-reset: rank; }

  ol.project-list li {
    counter-increment: rank;
    display: grid;
    grid-template-columns: 2rem 1fr;
    gap: 0.75rem;
    padding: 1.1rem 0;
    border-bottom: 1px solid var(--rule);
  }

  ol.project-list li:last-child { border-bottom: none; }

  ol.project-list li::before {
    content: counter(rank);
    font-family: var(--mono);
    font-size: 0.85rem;
    color: var(--muted);
    padding-top: 0.15rem;
  }

  .project-entry h3 { margin: 0 0 0.3rem; font-size: 1.05rem; font-weight: 600; }
  .project-entry p.desc { margin: 0 0 0.5rem; color: var(--muted); font-size: 0.95rem; }

  .tech-tags { display: flex; flex-wrap: wrap; gap: 0.4rem; }

  .tech-tags span {
    font-family: var(--mono);
    font-size: 0.72rem;
    color: var(--accent);
    background: var(--accent-soft);
    padding: 0.15rem 0.5rem;
    border-radius: 3px;
  }

  .skill-row { display: flex; flex-wrap: wrap; gap: 0.6rem 0.9rem; align-items: baseline; }
  .skill-row .skill { font-family: var(--mono); }

  footer.site-footer {
    font-family: var(--mono);
    font-size: 0.8rem;
    color: var(--muted);
    padding-top: 1.5rem;
    border-top: 1px solid var(--rule);
  }

  @media (max-width: 780px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar {
      position: relative;
      height: auto;
      flex-direction: row;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      border-right: none;
      border-bottom: 1px solid var(--rule);
      padding: 1.5rem;
    }
    .sidebar-top { display: flex; align-items: baseline; gap: 1rem; }
    .sidebar-top p.role { margin: 0; }
    nav.primary-nav ul { flex-direction: row; gap: 1.25rem; }
    .status-block { width: 100%; margin-top: 1rem; border-top: 1px solid var(--rule); }
    main { padding: 2rem 1.5rem 3rem; }
  }

  @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <div class="sidebar-top">
      <h1>Dipak Gauswami</h1>
      <p class="role">software developer</p>
      <nav class="primary-nav">
        <ul>
        <!-- <li><a href="home.php">Home</a></li> -->
          <li><a href="projects.php">projects</a></li>
          <li><a href="skills.php">skills</a></li>
          <li><a href="contact.php">contact</a></li>
        </ul>
      </nav>
    </div>

    <div class="status-block">
      <?php if ($githubSummary && $githubSummary['repo_count'] > 0): ?>
        <div class="status-line">
          <span><span class="status-dot <?= $githubSummary['failed_count'] > 0 ? 'failed' : '' ?>"></span>github sync</span>
          <span class="value"><?= htmlspecialchars(formatRelativeTime($githubSummary['last_fetched_at'])) ?></span>
        </div>
        <div class="status-line">
          <span>repos cached</span>
          <span class="value"><?= (int) $githubSummary['repo_count'] ?></span>
        </div>
        <div class="status-line">
          <span>commits</span>
          <span class="value"><?= (int) $githubSummary['total_commits'] ?></span>
        </div>
        <div class="status-line">
          <span>stars</span>
          <span class="value"><?= (int) $githubSummary['total_stars'] ?></span>
        </div>
      <?php else: ?>
        <div class="status-line"><span>github sync</span><span class="value">no data yet</span></div>
      <?php endif; ?>
    </div>
  </aside>

  <main>
    <div class="hero">
      <p>
        I build backend-heavy web applications and like the parts most people skip —
        auth, rate limiting, caching. This site's own admin panel, trending algorithm,
        and GitHub sync are the projects, not just a showcase for them.
      </p>
    </div>

    <section aria-labelledby="trending-heading">
      <h2 id="trending-heading">Trending projects</h2>
      <?php if (empty($trendingProjects)): ?>
        <p style="color: var(--muted);">No published projects yet.</p>
      <?php else: ?>
        <ol class="project-list">
          <?php foreach ($trendingProjects as $project): ?>
            <li>
              <div class="project-entry">
                <h3><a href="/projects/<?= urlencode($project['slug']) ?>"><?= htmlspecialchars($project['title']) ?></a></h3>
                <p class="desc"><?= htmlspecialchars(mb_strimwidth($project['description'], 0, 140, '…')) ?></p>
                <?php if (!empty($projectTags[$project['id']])): ?>
                  <div class="tech-tags">
                    <?php foreach ($projectTags[$project['id']] as $tag): ?>
                      <span><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>

    <section aria-labelledby="skills-heading">
      <h2 id="skills-heading">Skills</h2>
      <div class="skill-row">
        <?php foreach ($trendingSkills as $skill):
          $score = (float) $skill['trending_score'];
          $ratio = $maxSkillScore > 0 ? $score / $maxSkillScore : 0;
          $fontSize = 0.85 + ($ratio * 0.6);
          $color = $ratio > 0.5 ? 'var(--ink)' : 'var(--muted)';
        ?>
          <span class="skill" style="font-size: <?= round($fontSize, 2) ?>rem; color: <?= $color ?>;">
            <?= htmlspecialchars($skill['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </section>

    <footer class="site-footer">
      <a href="contact.php">get in touch</a>
    </footer>
  </main>

</div>
</body>
</html>

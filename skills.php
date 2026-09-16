<?php
/**
 * skills.php — PortfolioForge public Skills page.
 *
 * Built against the ACTUAL schema in the `student` database:
 *   skills(id, name, slug, created_at)                 -- no category, no trending_score column
 *   project_skills(project_id, skill_id)                -- join table
 *   projects(id, title, slug, is_published, ...)
 *   engagement_events(id, project_id, event_type, visitor_key, created_at)
 *
 * IMPORTANT SCHEMA NOTE:
 * engagement_events only tracks project_id — there is no skill-level event
 * logging anywhere in this schema. So a skill's trending score is entirely
 * DERIVED: it's the decayed sum of engagement across every published
 * project that skill is linked to via project_skills. This page leans into
 * that by showing which projects back each skill's score, since that's
 * exactly what the Home page's compact tag-cloud can't show.
 *
 * Each skill also links to projects.php?skill={slug}, reusing the filter
 * already built into the Projects listing page rather than duplicating it.
 */

require __DIR__ . '/config/db.php'; // expects $pdo (PDO instance)

const DECAY_LAMBDA = 0.099; // keep in sync with home.php / projects.php

// --- All skills, with a live-computed trending score and a published-project count
$stmt = $pdo->prepare(
    "SELECT
        s.id, s.name, s.slug,
        COUNT(DISTINCT CASE WHEN p.is_published = 1 THEN p.id END) AS project_count,
        COALESCE(SUM(
            CASE WHEN p.is_published = 1 THEN
                CASE ee.event_type WHEN 'like' THEN 3 ELSE 1 END
                * EXP(-:lambda * TIMESTAMPDIFF(DAY, ee.created_at, NOW()))
            ELSE 0 END
        ), 0) AS trending_score
     FROM skills s
     LEFT JOIN project_skills ps ON ps.skill_id = s.id
     LEFT JOIN projects p ON p.id = ps.project_id
     LEFT JOIN engagement_events ee ON ee.project_id = p.id
     GROUP BY s.id, s.name, s.slug
     ORDER BY trending_score DESC, s.name ASC"
);
$stmt->bindValue(':lambda', DECAY_LAMBDA);
$stmt->execute();
$skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$maxScore = 0.0;
foreach ($skills as $s) {
    $maxScore = max($maxScore, (float) $s['trending_score']);
}

// --- Published projects behind each skill (for the "used in" list) -------
$skillProjects = [];
if (!empty($skills)) {
    $ids = array_column($skills, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $projStmt = $pdo->prepare(
        "SELECT ps.skill_id, p.title, p.slug
         FROM project_skills ps
         JOIN projects p ON p.id = ps.project_id
         WHERE ps.skill_id IN ($placeholders) AND p.is_published = 1
         ORDER BY p.title"
    );
    $projStmt->execute($ids);
    foreach ($projStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $skillProjects[$row['skill_id']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Skills — PortfolioForge</title>
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
  body { margin: 0; background: var(--paper); color: var(--ink); font-family: var(--serif); line-height: 1.55; }
  a { color: var(--ink); text-decoration: none; border-bottom: 1px solid var(--rule); }
  a:hover { border-bottom-color: var(--accent); color: var(--accent); }
  a:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }

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
  main h2.page-title { font-size: 1.6rem; font-weight: 600; margin: 0 0 0.5rem; }
  main p.page-sub { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); margin: 0 0 2.25rem; }

  .skill-list { display: flex; flex-direction: column; }

  .skill-entry {
    padding: 1.3rem 0;
    border-bottom: 1px solid var(--rule);
  }
  .skill-entry:last-child { border-bottom: none; }

  .skill-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
  }

  .skill-head h3 { margin: 0; font-weight: 600; }

  .skill-meta {
    font-family: var(--mono);
    font-size: 0.75rem;
    color: var(--muted);
    white-space: nowrap;
  }

  .skill-meta a { border-bottom: none; color: var(--accent); }

  .used-in {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-family: var(--mono);
    font-size: 0.78rem;
  }

  .used-in a {
    color: var(--muted);
    background: var(--accent-soft);
    padding: 0.15rem 0.55rem;
    border-radius: 3px;
    border-bottom: none;
  }
  .used-in a:hover { background: var(--accent); color: var(--paper); }

  .no-projects { font-family: var(--mono); font-size: 0.78rem; color: var(--muted); font-style: italic; }

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
    .skill-head { flex-direction: column; gap: 0.25rem; }
  }
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
          <li><a href="home.php">home</a></li>
          <li><a href="projects.php">projects</a></li>
          <li><a href="skills.php" class="active">skills</a></li>
          <li><a href="contact.php">contact</a></li>
        </ul>
      </nav>
    </div>
  </aside>

  <main>
    <h2 class="page-title">Skills</h2>
    <p class="page-sub">ranked by recent activity across published projects</p>

    <?php if (empty($skills)): ?>
      <p class="empty-state">No skills recorded yet.</p>
    <?php else: ?>
      <div class="skill-list">
        <?php foreach ($skills as $skill):
          $score = (float) $skill['trending_score'];
          $ratio = $maxScore > 0 ? $score / $maxScore : 0;
          $fontSize = 1.0 + ($ratio * 0.35); // 1.0rem .. 1.35rem, subtler than the Home tag-cloud since this is a full list
        ?>
          <div class="skill-entry">
            <div class="skill-head">
              <h3 style="font-size: <?= round($fontSize, 2) ?>rem;"><?= htmlspecialchars($skill['name']) ?></h3>
              <span class="skill-meta">
                <?= (int) $skill['project_count'] ?> project<?= $skill['project_count'] == 1 ? '' : 's' ?>
                &middot;
                <a href="projects.php?skill=<?= urlencode($skill['slug']) ?>">view filtered</a>
              </span>
            </div>

            <?php if (!empty($skillProjects[$skill['id']])): ?>
              <div class="used-in">
                <?php foreach ($skillProjects[$skill['id']] as $proj): ?>
                  <a href="project.php?slug=<?= urlencode($proj['slug']) ?>"><?= htmlspecialchars($proj['title']) ?></a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="no-projects">not yet used in a published project</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

</div>
</body>
</html>

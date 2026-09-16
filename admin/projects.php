<?php
/**
 * admin/projects.php — Admin Project Management: list + delete.
 * (Create/edit is handled by admin/project-form.php.)
 *
 * Built against the ACTUAL schema:
 *   projects(id, title, slug, description, repo_url, live_url,
 *            github_repo_full_name, is_published, created_at, updated_at)
 *   project_skills(project_id, skill_id)
 *   engagement_events(id, project_id, ...)
 *   github_cache(id, project_id, ...)
 *
 * DELETE is a hard delete and cascades manually to dependent tables inside
 * a transaction, since no ON DELETE CASCADE was confirmed on these FKs:
 * project_skills, engagement_events, and github_cache rows for that
 * project_id are removed first, then the project row itself. If any step
 * fails, the whole delete rolls back rather than leaving orphaned rows.
 */

session_start();
require __DIR__ . '/../config/auth-guard.php';
require __DIR__ . '/../config/db.php';

// --- Handle delete (POST) -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $deleteId = (int) ($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM project_skills WHERE project_id = :id")->execute([':id' => $deleteId]);
            $pdo->prepare("DELETE FROM engagement_events WHERE project_id = :id")->execute([':id' => $deleteId]);
            $pdo->prepare("DELETE FROM github_cache WHERE project_id = :id")->execute([':id' => $deleteId]);
            $pdo->prepare("DELETE FROM projects WHERE id = :id")->execute([':id' => $deleteId]);
            $pdo->commit();
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Project deleted.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }
    header('Location: projects.php');
    exit;
}

// --- List all projects (published + drafts), with tag list ---------------
$stmt = $pdo->prepare(
    "SELECT id, title, slug, is_published, created_at, updated_at
     FROM projects
     ORDER BY updated_at DESC"
);
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectTags = [];
if (!empty($projects)) {
    $ids = array_column($projects, 'id');
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

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Projects — PortfolioForge Admin</title>
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

  main { padding: 2.5rem 3rem 4rem; max-width: 950px; }
  .page-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem; }
  .page-head h2 { font-size: 1.5rem; font-weight: 600; margin: 0; }
  .btn-new { font-family: var(--mono); font-size: 0.85rem; padding: 0.5rem 1rem; border: 1px solid var(--accent); background: var(--accent-soft); color: var(--accent); border-radius: 4px; }
  .btn-new:hover { background: var(--accent); color: var(--ink); }

  .flash { font-family: var(--mono); font-size: 0.82rem; padding: 0.7rem 0.9rem; border-radius: 4px; margin-bottom: 1.5rem; }
  .flash.success { background: var(--accent-soft); color: var(--accent); }
  .flash.error { background: var(--error-soft); color: var(--error); }

  table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  th { text-align: left; font-family: var(--mono); font-size: 0.72rem; color: var(--muted); padding: 0.6rem 0.5rem; border-bottom: 1px solid var(--rule); }
  td { padding: 0.8rem 0.5rem; border-bottom: 1px solid var(--rule); vertical-align: top; }

  .status-tag { font-family: var(--mono); font-size: 0.68rem; padding: 0.1rem 0.4rem; border-radius: 3px; }
  .status-tag.published { background: var(--accent-soft); color: var(--accent); }
  .status-tag.draft { background: var(--error-soft); color: var(--error); }

  .tag-list { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.3rem; }
  .tag-list span { font-family: var(--mono); font-size: 0.65rem; color: var(--muted); background: var(--surface); padding: 0.1rem 0.4rem; border-radius: 3px; }

  .row-actions { display: flex; gap: 0.8rem; font-family: var(--mono); font-size: 0.78rem; white-space: nowrap; }
  .row-actions form { display: inline; }
  .row-actions button { background: none; border: none; color: var(--error); font-family: var(--mono); font-size: 0.78rem; cursor: pointer; padding: 0; border-bottom: 1px solid var(--rule); }
  .row-actions button:hover { color: var(--error); border-bottom-color: var(--error); }

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
        <li><a href="projects.php" class="active">projects</a></li>
        <li><a href="skills.php">skills</a></li>
        <li><a href="messages.php">messages</a></li>
        <li><a href="../home.php" target="_blank">view site ↗</a></li>
        <li><a href="logout.php">logout</a></li>
      </ul>
    </nav>
  </aside>

  <main>
    <div class="page-head">
      <h2>Projects</h2>
      <a class="btn-new" href="project-form.php">+ new project</a>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (empty($projects)): ?>
      <p class="empty-note">No projects yet. Create your first one.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>title</th><th>status</th><th>updated</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $project): ?>
            <tr>
              <td>
                <?= htmlspecialchars($project['title']) ?>
                <?php if (!empty($projectTags[$project['id']])): ?>
                  <div class="tag-list">
                    <?php foreach ($projectTags[$project['id']] as $tag): ?><span><?= htmlspecialchars($tag) ?></span><?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-tag <?= $project['is_published'] ? 'published' : 'draft' ?>">
                  <?= $project['is_published'] ? 'published' : 'draft' ?>
                </span>
              </td>
              <td><?= htmlspecialchars(date('M j, Y', strtotime($project['updated_at']))) ?></td>
              <td>
                <div class="row-actions">
                  <a href="project-form.php?id=<?= $project['id'] ?>">edit</a>
                  <form method="post" action="projects.php" onsubmit="return confirm('Delete this project? This also removes its tags, engagement history, and cached GitHub stats. This cannot be undone.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $project['id'] ?>">
                    <button type="submit">delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</div>
</body>
</html>

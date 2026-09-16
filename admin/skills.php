<?php
/**
 * admin/skills.php — Admin Skills Management: list + create + edit + delete.
 *
 * Built against the ACTUAL schema:
 *   skills(id, name, slug, created_at)
 *   project_skills(project_id, skill_id)
 *
 * Unlike projects, skills only have one real field (name), so create/edit
 * are combined into one small inline form on this same page rather than a
 * separate form file — ?edit=5 switches the form into edit mode for skill 5.
 *
 * DELETE cascades manually to project_skills first (inside a transaction),
 * since deleting a skill still linked to projects would otherwise leave
 * orphaned project_skills rows pointing at a skill_id that no longer exists.
 */

session_start();
require __DIR__ . '/../config/auth-guard.php';
require __DIR__ . '/../config/db.php';

/** Turns "Node.js" into "node-js", ensuring uniqueness against other skills. */
function generateUniqueSkillSlug(PDO $pdo, string $name, ?int $excludeId): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    if ($base === '') {
        $base = 'skill';
    }
    $slug = $base;
    $suffix = 2;
    while (true) {
        $sql = "SELECT COUNT(*) FROM skills WHERE slug = :slug" . ($excludeId ? " AND id != :excludeId" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        if ($excludeId) {
            $stmt->bindValue(':excludeId', $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $suffix;
        $suffix++;
    }
}

$errors = [];

// --- Handle create/update (POST) ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create', 'update'], true)) {
    $name = trim($_POST['name'] ?? '');
    $editId = isset($_POST['id']) ? (int) $_POST['id'] : null;

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Skill name is required (under 100 characters).';
    }

    if (empty($errors)) {
        $slug = generateUniqueSkillSlug($pdo, $name, $editId);
        if ($_POST['action'] === 'update' && $editId) {
            $pdo->prepare("UPDATE skills SET name = :name, slug = :slug WHERE id = :id")
                ->execute([':name' => $name, ':slug' => $slug, ':id' => $editId]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Skill updated.'];
        } else {
            $pdo->prepare("INSERT INTO skills (name, slug) VALUES (:name, :slug)")
                ->execute([':name' => $name, ':slug' => $slug]);
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Skill added.'];
        }
        header('Location: skills.php');
        exit;
    }
}

// --- Handle delete (POST) --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $deleteId = (int) ($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM project_skills WHERE skill_id = :id")->execute([':id' => $deleteId]);
            $pdo->prepare("DELETE FROM skills WHERE id = :id")->execute([':id' => $deleteId]);
            $pdo->commit();
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Skill deleted.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }
    header('Location: skills.php');
    exit;
}

// --- Edit mode: load the skill being edited, if ?edit=id is present ------
$editingSkill = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM skills WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editingSkill = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- List all skills, with a count of PUBLISHED projects using each ------
$stmt = $pdo->prepare(
    "SELECT
        s.id, s.name, s.slug,
        COUNT(DISTINCT CASE WHEN p.is_published = 1 THEN p.id END) AS published_count,
        COUNT(DISTINCT ps.project_id) AS total_count
     FROM skills s
     LEFT JOIN project_skills ps ON ps.skill_id = s.id
     LEFT JOIN projects p ON p.id = ps.project_id
     GROUP BY s.id, s.name, s.slug
     ORDER BY s.name"
);
$stmt->execute();
$skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Skills — PortfolioForge Admin</title>
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

  main { padding: 2.5rem 3rem 4rem; max-width: 800px; }
  main h2 { font-size: 1.5rem; font-weight: 600; margin: 0 0 1.5rem; }

  .flash { font-family: var(--mono); font-size: 0.82rem; padding: 0.7rem 0.9rem; border-radius: 4px; margin-bottom: 1.5rem; }
  .flash.success { background: var(--accent-soft); color: var(--accent); }
  .flash.error { background: var(--error-soft); color: var(--error); }

  .add-form {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
    background: var(--surface);
    border: 1px solid var(--rule);
    border-radius: 6px;
    padding: 1.1rem 1.25rem;
    margin-bottom: 2rem;
  }
  .add-form .field { flex: 1; }
  .add-form label { display: block; font-family: var(--mono); font-size: 0.72rem; color: var(--muted); margin-bottom: 0.4rem; }
  .add-form input[type=text] {
    width: 100%; font-family: var(--serif); font-size: 0.95rem;
    padding: 0.5rem 0.65rem; border: 1px solid var(--rule); border-radius: 4px;
    background: var(--ink); color: #E7E9E4;
  }
  .add-form button {
    font-family: var(--mono); font-size: 0.85rem; padding: 0.55rem 1.1rem;
    border: 1px solid var(--accent); background: var(--accent-soft); color: var(--accent);
    border-radius: 4px; cursor: pointer; white-space: nowrap;
  }
  .add-form button:hover { background: var(--accent); color: var(--ink); }
  .add-form .cancel-edit { font-family: var(--mono); font-size: 0.78rem; color: var(--muted); align-self: center; }

  table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  th { text-align: left; font-family: var(--mono); font-size: 0.72rem; color: var(--muted); padding: 0.6rem 0.5rem; border-bottom: 1px solid var(--rule); }
  td { padding: 0.7rem 0.5rem; border-bottom: 1px solid var(--rule); }

  .usage-note { font-family: var(--mono); font-size: 0.75rem; color: var(--muted); }

  .row-actions { display: flex; gap: 0.8rem; font-family: var(--mono); font-size: 0.78rem; white-space: nowrap; }
  .row-actions form { display: inline; }
  .row-actions button {
    background: none; border: none; color: var(--error); font-family: var(--mono);
    font-size: 0.78rem; cursor: pointer; padding: 0; border-bottom: 1px solid var(--rule);
  }
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
        <li><a href="projects.php">projects</a></li>
        <li><a href="skills.php" class="active">skills</a></li>
        <li><a href="messages.php">messages</a></li>
        <li><a href="../home.php" target="_blank">view site ↗</a></li>
        <li><a href="logout.php">logout</a></li>
      </ul>
    </nav>
  </aside>

  <main>
    <h2>Skills</h2>

    <?php if ($flash): ?>
      <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="flash error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="add-form" method="post" action="skills.php">
      <?php if ($editingSkill): ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $editingSkill['id'] ?>">
      <?php else: ?>
        <input type="hidden" name="action" value="create">
      <?php endif; ?>

      <div class="field">
        <label for="name"><?= $editingSkill ? 'edit skill name' : 'add a new skill' ?></label>
        <input type="text" id="name" name="name" maxlength="100" required
               value="<?= htmlspecialchars($editingSkill['name'] ?? '') ?>" placeholder="e.g. PHP">
      </div>
      <button type="submit"><?= $editingSkill ? 'save' : 'add' ?></button>
      <?php if ($editingSkill): ?>
        <a class="cancel-edit" href="skills.php">cancel</a>
      <?php endif; ?>
    </form>

    <?php if (empty($skills)): ?>
      <p class="empty-note">No skills yet — add your first one above.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>name</th><th>used in</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($skills as $skill): ?>
            <tr>
              <td><?= htmlspecialchars($skill['name']) ?></td>
              <td class="usage-note">
                <?= (int) $skill['published_count'] ?> published
                <?php if ($skill['total_count'] > $skill['published_count']): ?>
                  (<?= (int) $skill['total_count'] ?> total incl. drafts)
                <?php endif; ?>
              </td>
              <td>
                <div class="row-actions">
                  <a href="skills.php?edit=<?= $skill['id'] ?>">edit</a>
                  <form method="post" action="skills.php" onsubmit="return confirm('Delete this skill? It will be removed from <?= (int) $skill['total_count'] ?> project(s) that currently use it. This cannot be undone.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $skill['id'] ?>">
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

<?php
/**
 * admin/project-form.php — Create/Edit a project.
 * No ?id= in the URL -> create mode. ?id=5 -> edit mode (loads project 5).
 *
 * Built against the ACTUAL schema:
 *   projects(id, title, slug, description, repo_url, live_url,
 *            github_repo_full_name, is_published, created_at, updated_at)
 *   project_skills(project_id, skill_id)
 *   skills(id, name, slug)
 *
 * SLUG HANDLING: if the slug field is left blank, one is auto-generated
 * from the title (lowercased, non-alphanumerics replaced with hyphens).
 * Uniqueness is enforced by appending -2, -3, etc. if the generated slug
 * collides with an existing project (excluding the project being edited).
 *
 * TAGS: skill checkboxes write directly to project_skills — on save, all
 * existing rows for this project are deleted and replaced with the
 * checked set, inside a transaction with the projects UPDATE/INSERT.
 */

session_start();
require __DIR__ . '/../config/auth-guard.php';
require __DIR__ . '/../config/db.php';

$editId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$isEdit = $editId !== null;

/** Turns "My Cool Project!" into "my-cool-project", ensuring it's unique. */
function generateUniqueSlug(PDO $pdo, string $title, ?int $excludeId): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    if ($base === '') {
        $base = 'project';
    }
    $slug = $base;
    $suffix = 2;
    while (true) {
        $sql = "SELECT COUNT(*) FROM projects WHERE slug = :slug" . ($excludeId ? " AND id != :excludeId" : "");
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

// --- All skills, for the checkbox list ------------------------------------
$allSkillsStmt = $pdo->prepare("SELECT id, name FROM skills ORDER BY name");
$allSkillsStmt->execute();
$allSkills = $allSkillsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Load existing project (edit mode) or defaults (create mode) ---------
$project = [
    'title' => '', 'slug' => '', 'description' => '', 'repo_url' => '',
    'live_url' => '', 'github_repo_full_name' => '', 'is_published' => 0,
];
$selectedSkillIds = [];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        die('Project not found.');
    }
    $project = $found;

    $skillIdStmt = $pdo->prepare("SELECT skill_id FROM project_skills WHERE project_id = :id");
    $skillIdStmt->execute([':id' => $editId]);
    $selectedSkillIds = array_map('intval', $skillIdStmt->fetchAll(PDO::FETCH_COLUMN));
}

$errors = [];

// --- Handle submission ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $slugInput   = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $repoUrl     = trim($_POST['repo_url'] ?? '');
    $liveUrl     = trim($_POST['live_url'] ?? '');
    $githubRepo  = trim($_POST['github_repo_full_name'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $selectedSkillIds = array_map('intval', $_POST['skills'] ?? []);

    if ($title === '' || mb_strlen($title) > 150) {
        $errors[] = 'Title is required (under 150 characters).';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }

    // Keep the form's values in $project so re-rendering on error shows what was typed.
    $project = [
        'title' => $title, 'slug' => $slugInput, 'description' => $description,
        'repo_url' => $repoUrl, 'live_url' => $liveUrl,
        'github_repo_full_name' => $githubRepo, 'is_published' => $isPublished,
    ];

    if (empty($errors)) {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($slugInput)), '-');
        if ($slug === '') {
            $slug = generateUniqueSlug($pdo, $title, $editId);
        }

        // If they typed a manual slug that collides with another project, fall back to auto-generating.
        $collisionSql = "SELECT COUNT(*) FROM projects WHERE slug = :slug" . ($isEdit ? " AND id != :excludeId" : "");
        $collisionStmt = $pdo->prepare($collisionSql);
        $collisionStmt->bindValue(':slug', $slug);
        if ($isEdit) {
            $collisionStmt->bindValue(':excludeId', $editId, PDO::PARAM_INT);
        }
        $collisionStmt->execute();
        if ((int) $collisionStmt->fetchColumn() > 0) {
            $slug = generateUniqueSlug($pdo, $title, $editId);
        }

        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                $pdo->prepare(
                    "UPDATE projects SET title = :title, slug = :slug, description = :description,
                            repo_url = :repo_url, live_url = :live_url,
                            github_repo_full_name = :github_repo, is_published = :is_published
                     WHERE id = :id"
                )->execute([
                    ':title' => $title, ':slug' => $slug, ':description' => $description,
                    ':repo_url' => $repoUrl ?: null, ':live_url' => $liveUrl ?: null,
                    ':github_repo' => $githubRepo ?: null, ':is_published' => $isPublished,
                    ':id' => $editId,
                ]);
                $projectId = $editId;
            } else {
                $pdo->prepare(
                    "INSERT INTO projects (title, slug, description, repo_url, live_url, github_repo_full_name, is_published)
                     VALUES (:title, :slug, :description, :repo_url, :live_url, :github_repo, :is_published)"
                )->execute([
                    ':title' => $title, ':slug' => $slug, ':description' => $description,
                    ':repo_url' => $repoUrl ?: null, ':live_url' => $liveUrl ?: null,
                    ':github_repo' => $githubRepo ?: null, ':is_published' => $isPublished,
                ]);
                $projectId = (int) $pdo->lastInsertId();
            }

            // Sync tags: wipe and re-insert the checked set.
            $pdo->prepare("DELETE FROM project_skills WHERE project_id = :id")->execute([':id' => $projectId]);
            if (!empty($selectedSkillIds)) {
                $tagInsert = $pdo->prepare("INSERT INTO project_skills (project_id, skill_id) VALUES (:pid, :sid)");
                foreach ($selectedSkillIds as $skillId) {
                    $tagInsert->execute([':pid' => $projectId, ':sid' => $skillId]);
                }
            }

            $pdo->commit();
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => $isEdit ? 'Project updated.' : 'Project created.'];
            header('Location: projects.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Edit' : 'New' ?> Project — PortfolioForge Admin</title>
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

  main { padding: 2.5rem 3rem 4rem; max-width: 640px; }
  main h2 { font-size: 1.5rem; font-weight: 600; margin: 0 0 1.5rem; }

  .flash { font-family: var(--mono); font-size: 0.8rem; padding: 0.7rem 0.9rem; border-radius: 4px; background: var(--error-soft); color: var(--error); margin-bottom: 1.5rem; }

  form { display: flex; flex-direction: column; gap: 1.2rem; }
  .field label { display: block; font-family: var(--mono); font-size: 0.75rem; color: var(--muted); margin-bottom: 0.4rem; }
  .field .hint { font-family: var(--mono); font-size: 0.68rem; color: var(--muted); margin-top: 0.3rem; }
  .field input[type=text], .field input[type=url], .field textarea {
    width: 100%; font-family: var(--serif); font-size: 0.95rem;
    padding: 0.55rem 0.65rem; border: 1px solid var(--rule); border-radius: 4px;
    background: var(--surface); color: #E7E9E4;
  }
  .field textarea { min-height: 120px; resize: vertical; font-family: var(--serif); }

  .checkbox-row { display: flex; align-items: center; gap: 0.5rem; }
  .checkbox-row label { margin: 0; font-family: var(--serif); font-size: 0.95rem; color: #E7E9E4; }

  .skill-checks { display: flex; flex-wrap: wrap; gap: 0.6rem; }
  .skill-checks label {
    display: flex; align-items: center; gap: 0.35rem;
    font-family: var(--mono); font-size: 0.78rem; color: var(--muted);
    background: var(--surface); border: 1px solid var(--rule); border-radius: 4px;
    padding: 0.3rem 0.6rem; cursor: pointer;
  }
  .skill-checks input:checked + span { color: var(--accent); }

  .form-actions { display: flex; gap: 1rem; align-items: center; }
  button.submit-btn {
    font-family: var(--mono); font-size: 0.9rem; padding: 0.6rem 1.2rem;
    border: 1px solid var(--accent); background: var(--accent-soft); color: var(--accent);
    border-radius: 4px; cursor: pointer;
  }
  button.submit-btn:hover { background: var(--accent); color: var(--ink); }
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
    <h2><?= $isEdit ? 'Edit project' : 'New project' ?></h2>

    <?php if (!empty($errors)): ?>
      <div class="flash"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form method="post" action="project-form.php<?= $isEdit ? '?id=' . $editId : '' ?>">
      <div class="field">
        <label for="title">title</label>
        <input type="text" id="title" name="title" maxlength="150" value="<?= htmlspecialchars($project['title']) ?>" required>
      </div>

      <div class="field">
        <label for="slug">slug (optional)</label>
        <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($project['slug']) ?>" placeholder="leave blank to auto-generate from title">
        <p class="hint">used in the project's URL, e.g. project.php?slug=this-value</p>
      </div>

      <div class="field">
        <label for="description">description</label>
        <textarea id="description" name="description" required><?= htmlspecialchars($project['description']) ?></textarea>
      </div>

      <div class="field">
        <label for="repo_url">repo URL (optional)</label>
        <input type="url" id="repo_url" name="repo_url" value="<?= htmlspecialchars($project['repo_url'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="live_url">live demo URL (optional)</label>
        <input type="url" id="live_url" name="live_url" value="<?= htmlspecialchars($project['live_url'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="github_repo_full_name">GitHub repo (optional, format: owner/repo)</label>
        <input type="text" id="github_repo_full_name" name="github_repo_full_name" value="<?= htmlspecialchars($project['github_repo_full_name'] ?? '') ?>" placeholder="e.g. yourname/task-tracker-api">
        <p class="hint">used to match this project against a github_cache row once GitHub syncing is built</p>
      </div>

      <div class="field">
        <label>tags</label>
        <?php if (empty($allSkills)): ?>
          <p class="hint">No skills exist yet — add some on the Skills page first.</p>
        <?php else: ?>
          <div class="skill-checks">
            <?php foreach ($allSkills as $skill): ?>
              <label>
                <input type="checkbox" name="skills[]" value="<?= $skill['id'] ?>" <?= in_array((int) $skill['id'], $selectedSkillIds, true) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($skill['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="field checkbox-row">
        <input type="checkbox" id="is_published" name="is_published" <?= $project['is_published'] ? 'checked' : '' ?>>
        <label for="is_published">published (visible on the public site)</label>
      </div>

      <div class="form-actions">
        <button type="submit" class="submit-btn"><?= $isEdit ? 'save changes' : 'create project' ?></button>
        <a href="projects.php">cancel</a>
      </div>
    </form>
  </main>
</div>
</body>
</html>

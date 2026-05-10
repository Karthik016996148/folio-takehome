<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $publish_at = trim($_POST['publish_at'] ?? '');

        if ($title === '' || $body === '') {
            $error = 'Title and body are required.';
        } else {
            $slug = generate_slug($title);
            $publish_value = $publish_at !== '' ? $publish_at : null;

            $stmt = db()->prepare('
                INSERT INTO documents (title, body, created_by, slug, publish_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $body, $staff['id'], $slug, $publish_value]);
            $docId = (int) db()->lastInsertId();

            $auditDetails = ['title' => $title, 'slug' => $slug];
            if ($publish_value) {
                $auditDetails['publish_at'] = $publish_value;
            }
            audit_log('create', 'document', $docId, $auditDetails);

            header('Location: /admin.php?created=' . $docId);
            exit;
        }
    }
}

// Search / filter
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $search . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>


<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at <span class="label-hint">(leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <div class="search-row">
            <input type="text" name="q" placeholder="Search by title…" value="<?= h($search) ?>" class="search-input">
            <button type="submit" class="btn btn-search">Search</button>
            <?php if ($search !== ''): ?>
                <a href="/admin.php" class="btn-link">Clear</a>
            <?php endif ?>
        </div>
    </form>

    <?php if ($search !== '' && empty($docs)): ?>
        <p class="empty">No documents matching "<?= h($search) ?>".</p>
    <?php elseif (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['slug'] ?? '#' . $d['id']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td>
                            <?php if (is_published($d)): ?>
                                <span class="status-badge status-published">Published</span>
                            <?php else: ?>
                                <span class="status-badge status-scheduled">Scheduled <?= h($d['publish_at']) ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td class="actions-cell">
                            <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Share →</a>
                            <a href="/schedule.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Schedule</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>

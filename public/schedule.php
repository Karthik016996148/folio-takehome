<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docId = (int) ($_GET['doc'] ?? 0);
$stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publish_at = trim($_POST['publish_at'] ?? '');
    $publish_value = $publish_at !== '' ? $publish_at : null;

    $stmt = db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?');
    $stmt->execute([$publish_value, $doc['id']]);

    audit_log('update_schedule', 'document', (int) $doc['id'], [
        'publish_at' => $publish_value ?? 'immediate',
    ]);

    $doc['publish_at'] = $publish_value;
    $success = true;
}

render_header('Schedule · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Schedule "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">
    Slug: <code><?= h($doc['slug'] ?? '#' . $doc['id']) ?></code> ·
    Current status:
    <?php if (is_published($doc)): ?>
        <span class="status-badge status-published">Published</span>
    <?php else: ?>
        <span class="status-badge status-scheduled">Scheduled for <?= h($doc['publish_at']) ?></span>
    <?php endif ?>
</p>

<?php if ($success): ?>
    <div class="banner banner-success">Schedule updated.</div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Update publish schedule</h2>
    <form method="post">
        <div class="form-field">
            <label for="publish_at">Publish at <span class="label-hint">(clear to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at"
                   value="<?= h($doc['publish_at'] ? date('Y-m-d\TH:i', strtotime($doc['publish_at'])) : '') ?>">
        </div>
        <button type="submit" class="btn">Save schedule</button>
    </form>
</section>

<?php render_footer(); ?>

<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at = trim($_POST['publish_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $slug = make_slug($title);
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, slug)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $title,
            $body,
            $staff['id'],
            $publish_at !== '' ? $publish_at : null,
            $slug,
        ]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title'      => $title,
            'slug'       => $slug,
            'publish_at' => $publish_at ?: null,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ');
}
$docs = $stmt->fetchAll();

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
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at <span style="font-weight:normal;color:var(--color-muted)">(leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by title…" style="flex:1">
        <button type="submit" class="btn">Search</button>
        <?php if ($q !== ''): ?>
            <a href="/admin.php" class="btn" style="background:var(--color-muted)">Clear</a>
        <?php endif ?>
    </form>

    <?php if ($q !== ''): ?>
        <p style="color:var(--color-muted);margin-bottom:.75rem"><?= count($docs) ?> result<?= count($docs) !== 1 ? 's' : '' ?> for "<?= h($q) ?>"</p>
    <?php endif ?>

    <?php if (empty($docs)): ?>
        <p class="empty">No documents<?= $q !== '' ? ' matching that search' : ' yet' ?>.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID / Slug</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Publish at</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php
                        $published = is_published($d);
                        $shareParam = $d['slug'] ?? $d['id'];
                    ?>
                    <tr>
                        <td class="id">
                            #<?= (int) $d['id'] ?>
                            <?php if (!empty($d['slug'])): ?>
                                <br><code style="font-size:.75em;color:var(--color-muted)"><?= h($d['slug']) ?></code>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td>
                            <?php if (!empty($d['publish_at'])): ?>
                                <?php if ($published): ?>
                                    <span style="color:var(--color-success)">&#10003; <?= h($d['publish_at']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--color-warn)">&#9201; <?= h($d['publish_at']) ?></span>
                                <?php endif ?>
                            <?php else: ?>
                                <span style="color:var(--color-muted)">Immediate</span>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= h((string) $shareParam) ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>

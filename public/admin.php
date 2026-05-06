<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publishedAt = trim($_POST['published_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        if ($publishedAt === '') {
            $publishedAt = date('Y-m-d H:i:s');
        } else {
            // Convert HTML5 datetime-local to SQLite datetime
            $publishedAt = str_replace('T', ' ', $publishedAt) . ':00';
        }

        $readableId = generate_readable_id();

        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, published_at, readable_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publishedAt, $readableId]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, ['title' => $title, 'readable_id' => $readableId, 'published_at' => $publishedAt]);

        header('Location: /admin.php?created=' . urlencode($readableId));
        exit;
    }
}

$q = trim($_GET['q'] ?? '');
$params = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE d.title LIKE ?';
    $params[] = '%' . $q . '%';
}

$stmt = db()->prepare("
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    $where
    ORDER BY d.created_at DESC
");
$stmt->execute($params);
$docs = $stmt->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document <?= h($_GET['created']) ?> created.</div>
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
            <label for="published_at">Publish At (leave blank for immediately)</label>
            <input type="datetime-local" id="published_at" name="published_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    
    <form method="get" style="margin-bottom: 1rem; display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" name="q" placeholder="Search by title..." value="<?= h($q) ?>" style="padding: 0.4rem; border: 1px solid #ccc; border-radius: 4px;">
        <button type="submit" class="btn" style="padding: 0.4rem 0.8rem;">Search</button>
        <?php if ($q !== ''): ?>
            <a href="/admin.php" style="color: #666; text-decoration: none;">Clear</a>
        <?php endif ?>
    </form>

    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publish At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['readable_id'] ?? ('#' . $d['id'])) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?= h($d['published_at'] ?? 'Immediately') ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>

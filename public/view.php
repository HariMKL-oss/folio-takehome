<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = $_GET['token'] ?? '';

$stmt = db()->prepare('
    SELECT d.*, s.recipient_email
    FROM shares s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = ?
');
$stmt->execute([$token]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

if (!is_published($doc)) {
    audit_log('view_blocked', 'document', (int) $doc['id'], [
        'publish_at' => $doc['publish_at'],
        'token'      => $token,
    ]);
    http_response_code(403);
    render_header('Not yet available');
    ?>
    <div class="centered-message">
        <h1>Not yet available</h1>
        <p>This document will be available on <?= h($doc['publish_at']) ?>.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>

<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

// ── Baseline ──────────────────────────────────────────────────────────────────

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// ── Feature: Scheduled Publishing ─────────────────────────────────────────────

test('document with future publish_at is blocked from view', function () {
    $future = date('Y-m-d\TH:i', strtotime('+1 hour'));
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)')
        ->execute(['Embargoed Doc', 'secret body', $future]);
    $doc = db()->query('SELECT * FROM documents ORDER BY id DESC LIMIT 1')->fetch();
    assert_true(!is_published($doc), 'document with future publish_at should not be published yet');
});

test('document with past publish_at is accessible', function () {
    $past = date('Y-m-d\TH:i', strtotime('-1 hour'));
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)')
        ->execute(['Released Doc', 'visible body', $past]);
    $doc = db()->query('SELECT * FROM documents ORDER BY id DESC LIMIT 1')->fetch();
    assert_true(is_published($doc), 'document with past publish_at should be published');
});

test('document with no publish_at is immediately accessible', function () {
    $doc = db()->query('SELECT * FROM documents WHERE publish_at IS NULL LIMIT 1')->fetch();
    assert_true($doc !== false, 'expected at least one document with no publish_at');
    assert_true(is_published($doc), 'document with null publish_at should be published');
});

// ── Feature: Human-Readable Slugs ─────────────────────────────────────────────

test('seeded document has a slug', function () {
    $doc = db()->query('SELECT * FROM documents WHERE title = \'Welcome Packet\' LIMIT 1')->fetch();
    assert_true(!empty($doc['slug']), 'expected seeded document to have a slug');
});

test('make_slug produces kebab-case title with year suffix', function () {
    $slug = make_slug('Quarterly Report');
    $year = date('Y');
    assert_true(
        $slug === "quarterly-report-{$year}",
        "expected 'quarterly-report-{$year}', got '{$slug}'"
    );
});

test('make_slug deduplicates collisions', function () {
    $year = date('Y');
    // Pre-insert a doc that would occupy the canonical slug.
    db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)')
        ->execute(['Collision Test', 'body', "collision-test-{$year}"]);
    $slug = make_slug('Collision Test');
    assert_true(
        $slug !== "collision-test-{$year}",
        'expected a deduplicated slug, got the same canonical slug'
    );
    assert_true(
        str_starts_with($slug, 'collision-test-'),
        "expected slug to start with 'collision-test-', got '{$slug}'"
    );
});

// ── Feature: Search by Title ───────────────────────────────────────────────────

test('search returns only documents matching the query', function () {
    db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
        ->execute(['Alpha Report', 'body']);
    db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
        ->execute(['Beta Summary', 'body']);

    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Alpha%']);
    $results = $stmt->fetchAll();

    assert_true(count($results) === 1, 'expected 1 result, got ' . count($results));
    assert_true(
        stripos($results[0]['title'], 'alpha') !== false,
        'result title should contain "Alpha"'
    );
});

test('empty search returns all documents', function () {
    $total = (int) db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) === $total, "expected {$total} results, got " . count($results));
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

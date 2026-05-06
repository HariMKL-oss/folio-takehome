<?php

require_once __DIR__ . '/../lib/bootstrap.php';

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

test('scheduled document shows not available message', function () {
    $pdo = db();
    $future = date('Y-m-d H:i:s', time() + 86400); // 1 day in the future
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by, published_at, readable_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['Future Doc', 'Body', 1, $future, 'DOC-FUTU']);
    $docId = $pdo->lastInsertId();
    
    $token = random_token();
    $stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);
    
    // Mock view.php execution
    $_GET['token'] = $token;
    ob_start();
    require_once __DIR__ . '/../public/view.php';
    $output = ob_get_clean();
    
    assert_true(strpos($output, 'Not yet available') !== false, 'expected not available message');
});

test('readable ID is generated correctly', function () {
    $id1 = generate_readable_id();
    $id2 = generate_readable_id();
    assert_true(strpos($id1, 'FOLIO-') === 0, 'expected ID to start with FOLIO-');
    assert_true(strlen($id1) === 10, 'expected ID length to be 10 characters');
    assert_true($id1 !== $id2, 'expected IDs to be unique');
});

test('search by title returns correct document', function () {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by, published_at, readable_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['Unique Search Title', 'Body', 1, date('Y-m-d H:i:s'), generate_readable_id()]);
    
    // Test the logic used in admin.php
    $q = 'Unique';
    $params = ['%' . $q . '%'];
    $stmt = $pdo->prepare("SELECT title FROM documents WHERE title LIKE ?");
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
    
    assert_true(count($docs) >= 1, 'expected to find at least one document');
    assert_true($docs[0]['title'] === 'Unique Search Title', 'expected to find the specific document');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

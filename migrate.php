<?php

require_once __DIR__ . '/lib/bootstrap.php';

$pdo = db();

// Ensure the migrations table exists, in case 0001 hasn't run yet.
// Wait, 0001 is the one creating it. So we must check if it exists or just try to run 0001.
// A simpler way: just create it here if it doesn't exist, to bootstrap the system.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
");

$migrationsDir = __DIR__ . '/migrations';
if (!is_dir($migrationsDir)) {
    echo "No migrations directory found.\n";
    exit;
}

$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $filename = basename($file);
    
    $stmt = $pdo->prepare('SELECT id FROM migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) {
        continue; // Already applied
    }

    echo "Applying migration: {$filename}\n";
    
    $sql = file_get_contents($file);
    
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Failed to apply {$filename}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations applied.\n";

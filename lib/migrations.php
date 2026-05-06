<?php

function run_migrations(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name TEXT NOT NULL PRIMARY KEY,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $applied = array_flip(
        $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
    );

    $dir = __DIR__ . '/../migrations';
    if (!is_dir($dir)) {
        return;
    }

    $files = glob($dir . '/*.sql');
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }
        $pdo->exec(file_get_contents($file));
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
    }
}

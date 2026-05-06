<?php

date_default_timezone_set('America/Chicago');

require_once __DIR__ . '/migrations.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Auto-apply migrations once the base schema exists.
        // Skipped during seed.php before schema.sql is applied.
        $has_schema = (bool) $pdo
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='documents'")
            ->fetchColumn();
        if ($has_schema) {
            run_migrations($pdo);
        }
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Generate a URL-safe slug from a title, deduplicated against existing rows.
// Format: kebab-case-title-YYYY, falling back to kebab-case-title-XXXX (4-char hex) on collision.
function make_slug(string $title): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
    $base = trim($base, '-') ?: 'doc';
    $year = date('Y');
    $candidate = "{$base}-{$year}";

    $stmt = db()->prepare('SELECT id FROM documents WHERE slug = ?');
    $stmt->execute([$candidate]);
    if (!$stmt->fetch()) {
        return $candidate;
    }

    do {
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
        $candidate = "{$base}-{$suffix}";
        $stmt->execute([$candidate]);
    } while ($stmt->fetch());

    return $candidate;
}

// Returns true when the document should be visible to recipients.
function is_published(array $doc): bool {
    if (empty($doc['publish_at'])) {
        return true;
    }
    return strtotime($doc['publish_at']) <= time();
}

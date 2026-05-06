# Folio Take-Home — Solution Notes

## What Is This App?

Folio is a minimal PHP/SQLite document-sharing tool.

- **Staff (admin)** logs in and creates documents.
- Staff generates a **one-time share link** for a recipient.
- The **recipient** opens that link and reads the document.

Stack: PHP 8.3, SQLite, Docker. No frameworks — raw PDO + plain PHP templates.

---

## How to Run

```bash
git clone https://github.com/HariMKL-oss/folio-takehome -b solution
cd folio-takehome
docker compose up
```

Open http://localhost:8000/admin.php

Run tests:

```bash
docker compose run --rm app php tests/test.php
```

Expected output: `9 passed, 0 failed.`

---

## File Structure

```
folio-takehome/
├── lib/
│   ├── bootstrap.php       # db(), helpers, make_slug(), is_published()
│   ├── layout.php          # render_header(), render_footer()
│   └── migrations.php      # run_migrations(PDO) — NEW
├── migrations/
│   ├── 001_add_publish_at.sql   # NEW
│   └── 002_add_document_slug.sql # NEW
├── public/
│   ├── admin.php           # Staff dashboard — MODIFIED
│   ├── share.php           # Create share links — MODIFIED
│   ├── view.php            # Recipient view — MODIFIED
│   └── index.php           # Redirects to admin
├── tests/
│   └── test.php            # Test suite — MODIFIED (9 tests, was 1)
├── schema.sql              # Base schema — NOT touched
├── seed.php                # DB seeder — MODIFIED
├── docker-compose.yml
└── Dockerfile
```

---

## Database Schema

**Original tables (schema.sql — untouched):**

```sql
staff        (id, email, name)
documents    (id, title, body, created_by, created_at)
shares       (id, document_id, token, recipient_email, created_at)
audit_log    (id, staff_id, action, entity_type, entity_id, details, created_at)
```

**Added via migrations:**

```sql
-- 001_add_publish_at.sql
ALTER TABLE documents ADD COLUMN publish_at TEXT NULL;

-- 002_add_document_slug.sql
ALTER TABLE documents ADD COLUMN slug TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_documents_slug ON documents (slug);
```

**Migration tracking table (auto-created by runner):**

```sql
schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT)
```

---

## Migration System

**Why:** The README says "schema changes must go through migration files." Editing `schema.sql` directly would break the incremental-change audit trail.

**How it works (`lib/migrations.php`):**

1. Creates `schema_migrations` table if it does not exist.
2. Reads all `*.sql` files from `migrations/` in alphabetical order.
3. Skips files already recorded in `schema_migrations`.
4. Executes new files and records their names.

**When it runs:**

- `db()` in `bootstrap.php` calls `run_migrations()` automatically after the first connection — but only if the `documents` table already exists. This prevents it from firing during `seed.php` before `schema.sql` has been applied.
- `seed.php` explicitly calls `run_migrations($pdo)` right after applying `schema.sql`.

This means:
- Fresh `docker compose up` → seed runs → schema + migrations → app starts → `db()` sees migrations already applied → no-op.
- Tests → seed runs as subprocess → same path.
- Adding a new migration later: drop the file in `migrations/`, restart — it auto-applies.

---

## Feature 1: Scheduled Publishing

**Goal:** Staff can set a future date on a document. Recipients see "Not yet available" until that date passes.

### Schema change
`publish_at TEXT NULL` on `documents`. Stored as a datetime string in America/Chicago timezone (matching PHP's `date_default_timezone_set`). NULL means publish immediately.

### Key function (`lib/bootstrap.php`)

```php
function is_published(array $doc): bool {
    if (empty($doc['publish_at'])) return true;
    return strtotime($doc['publish_at']) <= time();
}
```

`strtotime()` parses the datetime in the configured timezone. No UTC conversion needed.

### Admin UI (`public/admin.php`)
- Optional `<input type="datetime-local">` field when creating a document.
- Documents table shows:
  - ✓ green — publish_at is in the past (live)
  - ⏱ yellow — publish_at is in the future (scheduled)
  - "Immediate" grey — no publish_at set

### Share creation (`public/share.php`)
Staff **can** create share links for unpublished documents. This is intentional — staff need to pre-generate links before an embargo lifts and send them to recipients in advance. A yellow warning banner explains the document is scheduled.

### Recipient view (`public/view.php`)
```
if (!is_published($doc)) {
    → HTTP 403
    → "Not yet available — available on [publish_at]"
    → audit_log('view_blocked', 'document', $id, [...])
}
```
The blocked access is recorded in `audit_log` with action `view_blocked`.

---

## Feature 2: Human-Readable Document Slugs

**Goal:** Replace opaque integer IDs with memorable identifiers like `welcome-packet-2026`.

### Schema change
`slug TEXT UNIQUE NULL` on `documents` + unique index.

### Slug generation (`lib/bootstrap.php`)

```php
function make_slug(string $title): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
    $base = trim($base, '-') ?: 'doc';
    $year = date('Y');
    $candidate = "{$base}-{$year}";   // e.g. "welcome-packet-2026"

    $stmt = db()->prepare('SELECT id FROM documents WHERE slug = ?');
    $stmt->execute([$candidate]);
    if (!$stmt->fetch()) return $candidate;

    // Collision: append random 4-char hex suffix
    do {
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
        $candidate = "{$base}-{$suffix}";
        $stmt->execute([$candidate]);
    } while ($stmt->fetch());

    return $candidate;
}
```

Format: `{kebab-case-title}-{YYYY}`, deduplicating with a random 4-char hex suffix on collision (e.g. `budget-report-2026` → `budget-report-a3f1`).

### Where slugs appear

- **Admin table:** slug shown in small text below the numeric ID.
- **"Create share →" links:** use slug as `?doc=welcome-packet-2026` instead of `?doc=1`.
- **share.php:** resolves `?doc=` by slug OR numeric ID:

```php
if (ctype_digit($docParam)) {
    // lookup by id
} else {
    // lookup by slug
}
```

Numeric IDs still work — backwards compatible.

---

## Feature 3: Search by Title

**Goal:** Staff can find documents by typing part of a title.

### Implementation (`public/admin.php`)

- GET parameter `?q=` filters the documents query:

```sql
WHERE d.title LIKE ?   -- bound to '%{query}%'
```

SQLite's `LIKE` is case-insensitive for ASCII, so "budget" matches "Budget Report".

- Search form sits above the documents table.
- When a query is active:
  - Shows result count: `3 results for "report"`
  - Shows a **Clear** button to reset
  - Empty table message reads "No documents matching that search" instead of "No documents yet"

### Design choices considered
- **FTS (Full-Text Search):** SQLite has FTS5 — would enable ranking and partial-word matching. Skipped as overkill for this scope; `LIKE` covers the stated requirements.
- **Exact vs prefix vs fuzzy:** Used `%query%` (contains match) as the default — most forgiving for staff who remember part of a title. Could expose radio buttons for mode selection later.

---

## Audit Logging

All significant events are written to `audit_log`. Existing events: `create` (document, share). Added event:

| action | entity_type | when |
|---|---|---|
| `view_blocked` | `document` | Recipient hits publish gate |

---

## Tests (`tests/test.php`)

Each test run starts with a fresh `seed.php` (drops and recreates the DB).

| # | Test | Feature |
|---|---|---|
| 1 | Seeded share resolves to "Welcome Packet" | Baseline |
| 2 | Future `publish_at` → `is_published()` returns false | Scheduled publishing |
| 3 | Past `publish_at` → `is_published()` returns true | Scheduled publishing |
| 4 | Null `publish_at` → `is_published()` returns true | Scheduled publishing |
| 5 | Seeded document has a slug | Slugs |
| 6 | `make_slug()` returns `kebab-case-title-YYYY` format | Slugs |
| 7 | `make_slug()` deduplicates collision with different suffix | Slugs |
| 8 | `LIKE` search returns only matching document | Search |
| 9 | Empty query (`%%`) returns all documents | Search |

---

## Design Decisions Summary

| Decision | Choice | Why |
|---|---|---|
| Edit schema.sql? | No — migration files only | Preserves incremental history, matches stated requirement |
| Timezone for publish_at | America/Chicago (PHP default) | Consistent with existing `date_default_timezone_set` in bootstrap |
| Allow shares for unpublished docs? | Yes, with warning | Staff must pre-generate links before embargo; recipients handle the gate |
| Slug collision handling | Random hex suffix | Simple, no user input needed, still readable |
| Search mode | Contains (`%query%`) | Most useful default; prefix/exact can be added |
| FTS index | Not added | LIKE is sufficient for current scale |
| Numeric ID support after slugs | Kept — share.php resolves both | Backwards compatible, no breaking change |

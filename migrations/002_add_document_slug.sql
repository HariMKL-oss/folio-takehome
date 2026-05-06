ALTER TABLE documents ADD COLUMN slug TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_documents_slug ON documents (slug);

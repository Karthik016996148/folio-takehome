<?php

/**
 * Adds a human-readable slug to each document.
 * Format: lowercased title words + 4-char random suffix, e.g. "welcome-packet-3k7x"
 */
return function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE documents ADD COLUMN slug TEXT DEFAULT NULL');
    $pdo->exec('CREATE UNIQUE INDEX idx_documents_slug ON documents(slug)');

    // Backfill existing documents with generated slugs
    $rows = $pdo->query('SELECT id, title FROM documents WHERE slug IS NULL')->fetchAll();
    $stmt = $pdo->prepare('UPDATE documents SET slug = ? WHERE id = ?');
    foreach ($rows as $row) {
        $slug = generate_slug($row['title']);
        $stmt->execute([$slug, $row['id']]);
    }
};

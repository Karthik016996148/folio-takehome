<?php

/**
 * Adds an index on documents.title for faster search queries.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE INDEX idx_documents_title ON documents(title COLLATE NOCASE)');
};

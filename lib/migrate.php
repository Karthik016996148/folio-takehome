<?php

/**
 * Simple sequential migration runner for SQLite.
 *
 * Migrations live in /migrations/ as numbered PHP files (001_name.php, 002_name.php, ...).
 * Each returns a closure that receives a PDO instance.
 * A `_migrations` table tracks which files have already been applied.
 */

function run_migrations(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS _migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $applied = $pdo->query('SELECT filename FROM _migrations')
        ->fetchAll(PDO::FETCH_COLUMN);

    $dir = __DIR__ . '/../migrations';
    $files = glob($dir . '/*.php');
    sort($files);

    foreach ($files as $file) {
        $basename = basename($file);
        if (in_array($basename, $applied, true)) {
            continue;
        }

        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException("Migration {$basename} must return a callable.");
        }

        $pdo->beginTransaction();
        try {
            $migration($pdo);
            $stmt = $pdo->prepare('INSERT INTO _migrations (filename) VALUES (?)');
            $stmt->execute([$basename]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration {$basename} failed: " . $e->getMessage(), 0, $e);
        }
    }
}

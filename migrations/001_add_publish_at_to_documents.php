<?php

/**
 * Adds scheduled publishing support.
 * NULL publish_at = immediately visible (backwards-compatible).
 */
return function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE documents ADD COLUMN publish_at TEXT DEFAULT NULL');
};

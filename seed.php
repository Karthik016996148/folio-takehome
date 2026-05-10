<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrate.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Run all migrations on the fresh schema
run_migrations($pdo);

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

// Create sample documents
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    generate_slug('Welcome Packet'),
]);
$docId = (int) $pdo->lastInsertId();

// A second document scheduled for the future (for testing)
$stmt->execute([
    'Q3 Benefits Update',
    "Details about Q3 benefits changes.\n\nEffective July 1.",
    generate_slug('Q3 Benefits Update'),
]);
$futureDocId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE documents SET publish_at = ? WHERE id = ?')
    ->execute([date('Y-m-d H:i:s', strtotime('+7 days')), $futureDocId]);

// A third document for search variety
$stmt->execute([
    'Onboarding Checklist',
    "Step 1: Complete HR forms.\nStep 2: Set up your workstation.\nStep 3: Meet your team.",
    generate_slug('Onboarding Checklist'),
]);

$token = random_token();
$insertShare = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$insertShare->execute([$docId, $token, 'recipient@example.com']);

// Share link for the scheduled document
$futureToken = random_token();
$insertShare->execute([$futureDocId, $futureToken, 'future@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
echo "Future share: http://localhost:8000/view.php?token={$futureToken} (not yet available)\n";

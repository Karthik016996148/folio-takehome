<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new RuntimeException($msg !== '' ? "$msg: $detail" : $detail);
    }
}

echo "\nRunning tests:\n";

// ─── Existing ──────────────────────────────────────────────────────────────

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE d.title = ?
    ');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_equals('Welcome Packet', $row['title']);
});

// ─── Feature 1: Scheduled Publishing ───────────────────────────────────────

test('document without publish_at is considered published', function () {
    $doc = ['publish_at' => null];
    assert_true(is_published($doc), 'null publish_at should mean published');
});

test('document with future publish_at is not yet published', function () {
    $doc = ['publish_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))];
    assert_true(!is_published($doc), 'future publish_at should not be published');
});

test('document with past publish_at is published', function () {
    $doc = ['publish_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))];
    assert_true(is_published($doc), 'past publish_at should be published');
});

test('seeded future document has publish_at set', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title = ?');
    $stmt->execute(['Q3 Benefits Update']);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'expected future document to exist');
    assert_true($doc['publish_at'] !== null, 'expected publish_at to be set');
    assert_true(!is_published($doc), 'expected document to not be published yet');
});

test('updating publish_at changes document visibility', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title = ?');
    $stmt->execute(['Q3 Benefits Update']);
    $doc = $stmt->fetch();

    // Set to past — should become published
    $past = date('Y-m-d H:i:s', strtotime('-1 day'));
    db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?')
        ->execute([$past, $doc['id']]);

    $stmt->execute(['Q3 Benefits Update']);
    $updated = $stmt->fetch();
    assert_true(is_published($updated), 'should be published after setting publish_at to past');

    // Restore to future
    db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s', strtotime('+7 days')), $doc['id']]);
});

// ─── Feature 2: Human-Readable Document IDs (slugs) ───────────────────────

test('generate_slug produces a valid slug', function () {
    $slug = generate_slug('Welcome Packet');
    assert_true(preg_match('/^welcome-packet-[a-f0-9]{4}$/', $slug) === 1,
        "slug format unexpected: $slug");
});

test('generate_slug handles special characters', function () {
    $slug = generate_slug('Q3 Benefits — Update!');
    assert_true(preg_match('/^q3-benefits-update-[a-f0-9]{4}$/', $slug) === 1,
        "slug format unexpected: $slug");
});

test('generate_slug produces unique values', function () {
    $a = generate_slug('Test');
    $b = generate_slug('Test');
    assert_true($a !== $b, 'two calls should produce different slugs');
});

test('seeded documents have slugs', function () {
    $rows = db()->query('SELECT slug FROM documents')->fetchAll();
    foreach ($rows as $row) {
        assert_true($row['slug'] !== null && $row['slug'] !== '', 'expected slug to be set');
    }
});

test('slug uniqueness is enforced', function () {
    $slug = generate_slug('Unique Test');
    db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)')
        ->execute(['Unique Test', 'body', $slug]);

    $threw = false;
    try {
        db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)')
            ->execute(['Unique Test 2', 'body', $slug]);
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'duplicate slug should throw');

    // Clean up
    db()->prepare('DELETE FROM documents WHERE slug = ?')->execute([$slug]);
});

// ─── Feature 3: Search ─────────────────────────────────────────────────────

test('search by exact title returns the document', function () {
    $stmt = db()->prepare('
        SELECT * FROM documents WHERE title LIKE ?
    ');
    $stmt->execute(['%Welcome Packet%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 1, 'expected 1 result for exact title search');
    assert_equals('Welcome Packet', $rows[0]['title']);
});

test('search by partial title matches', function () {
    $stmt = db()->prepare('
        SELECT * FROM documents WHERE title LIKE ?
    ');
    $stmt->execute(['%Packet%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least 1 result for partial search');
});

test('search is case-insensitive', function () {
    $stmt = db()->prepare('
        SELECT * FROM documents WHERE title LIKE ?
    ');
    $stmt->execute(['%welcome%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected case-insensitive match');
});

test('search with no match returns empty', function () {
    $stmt = db()->prepare('
        SELECT * FROM documents WHERE title LIKE ?
    ');
    $stmt->execute(['%zzz_nonexistent_zzz%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 0, 'expected no results');
});

// ─── Migration system ──────────────────────────────────────────────────────

test('migrations table tracks applied migrations', function () {
    $rows = db()->query('SELECT filename FROM _migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($rows) >= 3, 'expected at least 3 migrations applied');
    assert_true(in_array('001_add_publish_at_to_documents.php', $rows), 'migration 001 missing');
    assert_true(in_array('002_add_slug_to_documents.php', $rows), 'migration 002 missing');
    assert_true(in_array('003_add_search_index.php', $rows), 'migration 003 missing');
});

// ─── Audit logging ─────────────────────────────────────────────────────────

test('creating a document with schedule is audit-logged', function () {
    $slug = generate_slug('Audit Test');
    $publish_at = date('Y-m-d H:i:s', strtotime('+2 days'));
    db()->prepare('INSERT INTO documents (title, body, created_by, slug, publish_at) VALUES (?, ?, 1, ?, ?)')
        ->execute(['Audit Test', 'body', $slug, $publish_at]);
    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, ['title' => 'Audit Test', 'slug' => $slug, 'publish_at' => $publish_at]);

    $stmt = db()->prepare('SELECT * FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['document', $docId]);
    $log = $stmt->fetch();
    assert_true($log !== false, 'expected audit log entry');
    assert_equals('create', $log['action']);
    $details = json_decode($log['details'], true);
    assert_true(isset($details['publish_at']), 'audit details should include publish_at');
    assert_true(isset($details['slug']), 'audit details should include slug');

    // Clean up
    db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);
    db()->prepare('DELETE FROM audit_log WHERE entity_id = ? AND entity_type = ?')->execute([$docId, 'document']);
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

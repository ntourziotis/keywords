<?php

declare(strict_types=1);

// Run: php db_migrate_taxonomy_rules.php
// Safe to run multiple times. It will:
// - create taxonomy_rules table (if missing)
// - seed categories/subcategories and starter rules (INSERT OR IGNORE)

require __DIR__ . '/bootstrap.php';

use App\Core\DB;

$pdo = DB::pdo();

$sql = file_get_contents(__DIR__ . '/db/schema_sqlite.sql');
if ($sql === false) {
    fwrite(STDERR, "Could not read db/schema_sqlite.sql\n");
    exit(1);
}

try {
    $pdo->exec($sql);
    echo "OK: schema applied (taxonomy_rules + seeds)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * Adds missing video fields to videos table (SQLite).
 *
 * Usage:
 *  - Web: /db_migrate_add_video_fields.php?key=kalimera
 *  - CLI: php db_migrate_add_video_fields.php
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$WEB_KEY = 'kalimera';
if (PHP_SAPI !== 'cli') {
  $key = $_GET['key'] ?? '';
  if ($key !== $WEB_KEY) { http_response_code(403); exit('Forbidden'); }
}

$db = getenv('KEYWORDS_DB_PATH');
if (!$db) $db = __DIR__ . '/storage/app.db';
if (!is_file($db)) { http_response_code(500); exit('DB not found: ' . $db); }

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->query("PRAGMA table_info(" . $table . ")");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (($r['name'] ?? null) === $col) return true;
  }
  return false;
}

$adds = [
  ['video_url', 'TEXT'],
  ['page_url', 'TEXT'],
  ['thumbnail', 'TEXT'],
  ['duration', 'INTEGER'],
  ['description_raw', 'TEXT'],
];

header('Content-Type: text/plain; charset=utf-8');

foreach ($adds as [$col, $type]) {
  if (has_col($pdo, 'videos', $col)) {
    echo "OK: videos.$col exists\n";
    continue;
  }
  $sql = "ALTER TABLE videos ADD COLUMN $col $type";
  $pdo->exec($sql);
  echo "OK: added videos.$col ($type)\n";
}

echo "DONE\n";

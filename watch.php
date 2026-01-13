<?php
declare(strict_types=1);

/**
 * Redirector to watch a video.
 * Usage: /watch.php?id=123
 *
 * Priority:
 *  - page_url
 *  - url
 *  - video_url
 */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$db = getenv('KEYWORDS_DB_PATH');
if (!$db) $db = __DIR__ . '/storage/app.db';
if (!is_file($db)) { http_response_code(500); exit('DB not found'); }

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("SELECT * FROM videos WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); exit('Not found'); }

$url = null;
foreach (['page_url','url','video_url'] as $k) {
  if (!empty($row[$k]) && is_string($row[$k])) { $url = trim($row[$k]); break; }
}

if (!$url) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "No watch URL stored for this video.\n";
  echo "Run: /backfill_video_urls.php?key=kalimera\n";
  exit;
}

header('Location: ' . $url, true, 302);
exit;

<?php
declare(strict_types=1);

/**
 * Backfill missing watch-related fields (video_url/page_url/thumbnail/duration/description_raw)
 * for existing videos, by re-reading MRSS feeds.
 *
 * Usage:
 *  - Web: /backfill_video_urls.php?key=kalimera
 *  - CLI: php backfill_video_urls.php
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$WEB_KEY = 'kalimera';
if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== $WEB_KEY) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/bootstrap.php';

use App\Core\DB;
use App\Models\Channel;
use App\Services\MrssFetcher;

$pdo = DB::pdo();

function has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->query("PRAGMA table_info(" . $table . ")");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (($r['name'] ?? null) === $col) return true;
    }
    return false;
}

// Ensure required columns exist (safe to run multiple times)
$adds = [
    ['video_url', 'TEXT'],
    ['page_url', 'TEXT'],
    ['thumbnail', 'TEXT'],
    ['duration', 'INTEGER'],
    ['description_raw', 'TEXT'],
];

foreach ($adds as [$col, $type]) {
    if (has_col($pdo, 'videos', $col)) {
        echo "OK: videos.$col exists\n";
        continue;
    }
    $pdo->exec("ALTER TABLE videos ADD COLUMN $col $type");
    echo "OK: added videos.$col ($type)\n";
}

echo "----\n";

$fetcher = new MrssFetcher();
$channels = Channel::allActive();
if (!$channels) {
    echo "No active channels.\n";
    exit;
}

$totalMatched = 0;
$totalUpdated = 0;

foreach ($channels as $c) {
    $channelId = (int)($c['id'] ?? 0);
    $src = (string)($c['source_url'] ?? '');
    if ($channelId <= 0 || $src === '') continue;

    echo "[backfill] Channel #{$channelId} {$c['name']}\n";

    try {
        $items = $fetcher->fetch($src);
    } catch (Throwable $e) {
        echo "  ERROR fetching feed: " . $e->getMessage() . "\n";
        continue;
    }

    if (!$items) {
        echo "  (no items)\n";
        continue;
    }

    // Fill ONLY when empty
    $sql = "UPDATE videos SET
              video_url = CASE WHEN video_url IS NULL OR video_url = '' THEN :video_url ELSE video_url END,
              page_url  = CASE WHEN page_url  IS NULL OR page_url  = '' THEN :page_url  ELSE page_url  END,
              thumbnail = CASE WHEN thumbnail IS NULL OR thumbnail = '' THEN :thumbnail ELSE thumbnail END,
              duration  = CASE WHEN duration  IS NULL OR duration  = 0  THEN :duration  ELSE duration  END,
              description_raw = CASE WHEN description_raw IS NULL OR description_raw = '' THEN :description_raw ELSE description_raw END
            WHERE channel_id = :channel_id AND media_id = :media_id";

    $st = $pdo->prepare($sql);

    $matched = 0;
    $updated = 0;

    foreach ($items as $it) {
        $mediaId = (string)($it['media_id'] ?? '');
        if ($mediaId === '') continue;

        $videoUrl = (string)($it['video_url'] ?? '');
        $pageUrl = (string)($it['page_url'] ?? '');
        $thumb = (string)($it['thumbnail'] ?? '');
        $duration = isset($it['duration']) ? (int)$it['duration'] : 0;
        $desc = (string)($it['description'] ?? '');

        // Nothing to fill
        if ($videoUrl === '' && $pageUrl === '' && $thumb === '' && $duration <= 0 && $desc === '') {
            continue;
        }

        $st->execute([
            ':video_url' => $videoUrl,
            ':page_url' => $pageUrl,
            ':thumbnail' => $thumb,
            ':duration' => $duration,
            ':description_raw' => $desc,
            ':channel_id' => $channelId,
            ':media_id' => $mediaId,
        ]);

        $matched++;
        $rc = (int)$st->rowCount();
        if ($rc > 0) $updated += $rc;
    }

    $totalMatched += $matched;
    $totalUpdated += $updated;

    echo "  items=" . count($items) . " matched={$matched} updated_rows={$updated}\n";
}

echo "----\n";
echo "DONE. matched_items={$totalMatched} updated_rows={$totalUpdated}\n";

<?php
/**
 * Backfill missing subcategory_id for videos that already have category_id.
 *
 * Safe mode:
 * - Only sets subcategory when a rule match is found AND the matched category_id equals the video's category_id
 * - Only updates rows where subcategory_id IS NULL OR = 0
 *
 * Usage (web):
 *   /backfill_subcategories.php?key=kalimera&limit=2000&threshold=0.75
 *
 * Usage (cli):
 *   php backfill_subcategories.php --key=kalimera --limit=2000 --threshold=0.75
 *
 * Optional:
 *   &status=manual,auto,needs_review   (default: manual,auto,needs_review)
 *   &dry_run=1                         (no DB updates, just report)
 */

declare(strict_types=1);

$ROOT = __DIR__;
require_once $ROOT . '/bootstrap.php'; // adjust if your project uses a different bootstrap entry

// -------------------- helpers --------------------
function arg(string $name, $default = null) {
    // CLI: --name=value
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $a) {
            if (strpos($a, '--' . $name . '=') === 0) {
                return substr($a, strlen('--' . $name . '='));
            }
        }
        return $default;
    }
    // Web
    return $_GET[$name] ?? $default;
}

function normalize_title(string $text): string
{
    $t = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

    $map = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ',
        'Ά' => 'α', 'Έ' => 'ε', 'Ή' => 'η', 'Ί' => 'ι', 'Ό' => 'ο', 'Ύ' => 'υ', 'Ώ' => 'ω',
    ];

    $t = strtr($t, $map);
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return trim($t);
}

function out(string $s): void {
    if (PHP_SAPI === 'cli') {
        echo $s . PHP_EOL;
    } else {
        echo htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
    }
}

// -------------------- auth key --------------------
$key = (string) arg('key', '');
$expected = (string)($_ENV['APP_CRON_KEY'] ?? ($_ENV['CRON_KEY'] ?? 'kalimera'));
if ($key !== $expected) {
    http_response_code(403);
    out("Forbidden: invalid key.");
    exit;
}

// -------------------- deps --------------------
use App\Core\DB;
use App\Models\TaxonomyRule;
use App\Models\Video;

$limit = (int) arg('limit', 2000);
if ($limit < 1) $limit = 1;
if ($limit > 20000) $limit = 20000;

$threshold = (float) arg('threshold', ($_ENV['BACKFILL_SUBCATEGORY_THRESHOLD'] ?? '0.75'));
if ($threshold < 0.0) $threshold = 0.0;
if ($threshold > 1.0) $threshold = 1.0;

$statusRaw = (string) arg('status', 'manual,auto,needs_review');
$statuses = array_values(array_filter(array_map('trim', explode(',', $statusRaw))));
if (empty($statuses)) $statuses = ['manual', 'auto', 'needs_review'];

$dryRun = (string) arg('dry_run', '0');
$dryRun = ($dryRun === '1' || strtolower($dryRun) === 'true');

$pdo = DB::pdo();

// -------------------- query --------------------
$in = implode(',', array_fill(0, count($statuses), '?'));

$sql = "
SELECT id, title_raw, category_id, subcategory_id, confidence, status
FROM videos
WHERE category_id IS NOT NULL AND category_id != 0
  AND (subcategory_id IS NULL OR subcategory_id = 0)
  AND status IN ($in)
ORDER BY id DESC
LIMIT $limit
";

$st = $pdo->prepare($sql);
$st->execute($statuses);
$rows = $st->fetchAll() ?: [];

out("Found " . count($rows) . " videos with category but missing subcategory (limit=$limit, threshold=$threshold, dry_run=" . ($dryRun ? '1' : '0') . ").");

$updated = 0;
$skipped = 0;

foreach ($rows as $v) {
    $id = (int)($v['id'] ?? 0);
    $catId = (int)($v['category_id'] ?? 0);
    $title = (string)($v['title_raw'] ?? '');
    if ($id <= 0 || $catId <= 0 || $title === '') {
        $skipped++;
        continue;
    }

    $norm = normalize_title($title);

    // scoreMatch should return: category_id, subcategory_id, confidence
    $best = TaxonomyRule::scoreMatch($norm);
    $bestCat = (int)($best['category_id'] ?? 0);
    $bestSub = (int)($best['subcategory_id'] ?? 0);
    $conf = (float)($best['confidence'] ?? 0.0);

    if ($bestSub > 0 && $bestCat === $catId && $conf >= $threshold) {
        if ($dryRun) {
            $updated++;
            continue;
        }

        $newConf = max((float)($v['confidence'] ?? 0.0), $conf);
        Video::update($id, [
            'subcategory_id' => $bestSub,
            'confidence' => $newConf,
        ]);
        $updated++;
    } else {
        $skipped++;
    }
}

out("Updated: $updated");
out("Skipped: $skipped");

out("Done.");

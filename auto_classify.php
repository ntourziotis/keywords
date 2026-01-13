<?php
/**
 * Auto-classify videos using TaxonomyRule::scoreMatch and (optionally) auto-approve when confidence is high.
 *
 * What it does:
 *  - Looks for videos with status = needs_review (optionally also manual/auto) and missing category/subcategory.
 *  - Runs rule matching on title_raw (normalized).
 *  - Writes category_id/subcategory_id/confidence when it finds a match.
 *  - If it finds BOTH category + subcategory AND confidence >= threshold, it sets status=auto.
 *
 * Safe:
 *  - It never overwrites an existing (non-empty) category_id/subcategory_id unless you pass overwrite=1.
 *
 * Web:
 *   /auto_classify.php?key=kalimera&limit=500&threshold=0.85
 *   /auto_classify.php?key=kalimera&limit=500&threshold=0.85&overwrite=1
 *
 * CLI:
 *   php auto_classify.php --key=kalimera --limit=500 --threshold=0.85
 */

declare(strict_types=1);

$ROOT = __DIR__;
require_once $ROOT . '/bootstrap.php';

// -------------------- helpers --------------------
function arg(string $name, $default = null) {
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv as $a) {
            if (strpos($a, '--' . $name . '=') === 0) {
                return substr($a, strlen('--' . $name . '='));
            }
        }
        return $default;
    }
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

use App\Core\DB;
use App\Models\TaxonomyRule;
use App\Models\Video;

$limit = (int) arg('limit', 500);
if ($limit < 1) $limit = 1;
if ($limit > 20000) $limit = 20000;

$threshold = (float) arg('threshold', ($_ENV['AUTO_APPROVE_THRESHOLD'] ?? '0.85'));
if ($threshold < 0.0) $threshold = 0.0;
if ($threshold > 1.0) $threshold = 1.0;

$overwrite = (string) arg('overwrite', '0');
$overwrite = ($overwrite === '1' || strtolower($overwrite) === 'true');

$pdo = DB::pdo();

$sql = "
SELECT id, title_raw, category_id, subcategory_id, confidence, status
FROM videos
WHERE status = 'needs_review'
ORDER BY id DESC
LIMIT $limit
";

$rows = $pdo->query($sql)->fetchAll() ?: [];

out("Loaded " . count($rows) . " needs_review videos (limit=$limit).");
out("threshold=$threshold overwrite=" . ($overwrite ? '1' : '0'));

$filled = 0;
$auto = 0;
$skipped = 0;

foreach ($rows as $v) {
    $id = (int)($v['id'] ?? 0);
    if ($id <= 0) { $skipped++; continue; }

    $title = (string)($v['title_raw'] ?? '');
    if ($title === '') { $skipped++; continue; }

    $hasCat = !empty($v['category_id']) && (int)$v['category_id'] !== 0;
    $hasSub = !empty($v['subcategory_id']) && (int)$v['subcategory_id'] !== 0;

    if (!$overwrite && $hasCat && $hasSub) {
        $skipped++;
        continue;
    }

    $norm = normalize_title($title);
    $best = TaxonomyRule::scoreMatch($norm);

    $bestCat = (int)($best['category_id'] ?? 0);
    $bestSub = (int)($best['subcategory_id'] ?? 0);
    $conf = (float)($best['confidence'] ?? 0.0);

    if ($bestCat <= 0) {
        $skipped++;
        continue;
    }

    $update = [];

    if ($overwrite || !$hasCat) {
        $update['category_id'] = $bestCat;
    }
    if ($bestSub > 0 && ($overwrite || !$hasSub)) {
        $update['subcategory_id'] = $bestSub;
    }

    // confidence: keep the max
    $prevConf = (float)($v['confidence'] ?? 0.0);
    $update['confidence'] = max($prevConf, $conf);

    // auto approve only when we have both cat+sub and confidence high
    $willHaveSub = ($bestSub > 0) && ($overwrite || !$hasSub);
    $finalHasSub = $hasSub || $willHaveSub;

    if ($finalHasSub && $conf >= $threshold) {
        $update['status'] = 'auto';
    }

    if (!empty($update)) {
        Video::update($id, $update);
        $filled++;
        if (($update['status'] ?? '') === 'auto') $auto++;
    } else {
        $skipped++;
    }
}

out("Updated rows: $filled");
out("Set status=auto: $auto");
out("Skipped: $skipped");
out("Done.");

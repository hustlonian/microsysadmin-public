#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Quality\ReportOnlyCandidateRanker;

$report = (new ReportOnlyCandidateRanker(APP_ROOT . '/doctor/modules'))->rank();
$root = APP_ROOT . '/doctor/reports';
$write = static function (string $path, string $contents) use ($root): void {
    if (dirname($path) !== $root || is_link($path)) {
        throw new RuntimeException('Candidate ranking output escaped its fixed report directory.');
    }
    $temporary = $root . '/.' . basename($path) . '.rank-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write candidate ranking {$path}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install candidate ranking {$path}.");
    }
};
$write($root . '/report-only-candidate-ranking.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
$lines = ['# Doctor Report-only Candidate Ranking', '', 'Generated: ' . $report['generated_at'], '', '- Advisory repairs ranked: ' . $report['report_only_count'], '- Exact overlap groups: ' . count((array) $report['overlap_groups']), '', '## Classifications', ''];
foreach ((array) $report['classification_counts'] as $classification => $count) {
    $lines[] = '- ' . $classification . ': ' . $count;
}
$lines[] = '';
$lines[] = '## Highest-priority candidates';
$lines[] = '';
foreach (array_slice((array) $report['candidates'], 0, 25) as $candidate) {
    $lines[] = '- `' . $candidate['repair_id'] . '` (' . $candidate['commercial_priority_score'] . '): ' . $candidate['classification'] . ' — ' . $candidate['next_action'];
}
$lines[] = '';
$write($root . '/report-only-candidate-ranking.md', implode("\n", $lines));
printf("Ranked %d report-only repairs into %d classifications with %d exact overlap groups.\n", $report['report_only_count'], count((array) $report['classification_counts']), count((array) $report['overlap_groups']));

#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Quality\CommercialLibraryAuditor;
use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

$reportsRoot = APP_ROOT . '/doctor/reports';
if (!is_dir($reportsRoot) && !mkdir($reportsRoot, 0775, true) && !is_dir($reportsRoot)) {
    throw new RuntimeException('Unable to create the Doctor commercial report directory.');
}
if (is_link($reportsRoot)) {
    throw new RuntimeException('Doctor commercial report directory cannot be symbolic.');
}

$write = static function (string $path, string $contents) use ($reportsRoot): void {
    if (dirname($path) !== $reportsRoot || is_link($path)) {
        throw new RuntimeException('Commercial audit output escaped its fixed report directory.');
    }
    $temporary = $reportsRoot . '/.' . basename($path) . '.audit-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write temporary commercial audit {$temporary}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install commercial audit {$path}.");
    }
};

$auditor = new CommercialLibraryAuditor(APP_ROOT . '/doctor/modules', new RepairPrimitiveRegistry());
$report = $auditor->audit();
$write(
    $reportsRoot . '/commercial-library-audit.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
);
$write($reportsRoot . '/commercial-library-audit.md', $auditor->markdown($report));

$counts = (array) $report['counts'];
$coverage = (array) $report['coverage'];
$defectCount = 0;
foreach ((array) $report['defects'] as $defects) {
    $defectCount += count((array) $defects);
}

fwrite(STDOUT, sprintf(
    "Commercial audit: %d definitions; %d executable; %d fully automatic; %d report-only; %.2f%% automation; %.2f%% rollback; %d defects; readiness %.2f/100.\n",
    (int) $counts['definitions'],
    (int) $counts['executable'],
    (int) $counts['fully_automatic'],
    (int) $counts['report_only'],
    (float) $coverage['automation_percent'],
    (float) $coverage['rollback_percent'],
    $defectCount,
    (float) $report['commercial_readiness_score'],
));

exit($defectCount === 0 ? 0 : 1);

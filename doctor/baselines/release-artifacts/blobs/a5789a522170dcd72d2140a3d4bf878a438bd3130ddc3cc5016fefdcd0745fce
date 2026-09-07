#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));
        continue;
    }
    throw new InvalidArgumentException('Usage: verify-locked-composer-vendor.php --root=DIR');
}
$root = realpath($root) ?: '';
if ($root === '' || !is_file($root . '/vendor/autoload.php') || is_link($root . '/vendor')) {
    throw new RuntimeException('Locked Composer verification root is unavailable or unsafe.');
}
$catalogClass = $root . '/doctor/sdk/Generation/LockedComposerVendorCatalog.php';
if (!is_file($catalogClass) || is_link($catalogClass)) {
    throw new RuntimeException('First-party locked Composer verifier is unavailable or unsafe.');
}
require $catalogClass;

$result = (new \SysAdmin\Doctor\sdk\Generation\LockedComposerVendorCatalog($root))->verifyReleaseRoot($root);
if ($result['unexpected'] !== [] || $result['missing'] !== [] || $result['drifted'] !== []
    || $result['unexpected_directories'] !== [] || $result['missing_directories'] !== [] || $result['drifted_directories'] !== []) {
    fwrite(STDERR, sprintf(
        "Locked Composer vendor verification failed: files=%d unexpected/%d missing/%d drifted; directories=%d unexpected/%d missing/%d drifted.\n",
        count($result['unexpected']),
        count($result['missing']),
        count($result['drifted']),
        count($result['unexpected_directories']),
        count($result['missing_directories']),
        count($result['drifted_directories']),
    ));
    exit(1);
}
echo sprintf(
    "Locked Composer vendor verification PASS: %d exact production artifacts and %d directories.\n",
    $result['verified'],
    $result['verified_directories'],
);

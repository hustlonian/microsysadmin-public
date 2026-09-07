#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));
        continue;
    }
    throw new InvalidArgumentException('Usage: materialize-locked-composer-vendor.php --root=DIR');
}
$root = realpath($root) ?: '';
if ($root === '' || !is_file($root . '/composer.lock') || is_link($root)) {
    throw new RuntimeException('Locked Composer materialization root is unavailable or unsafe.');
}
$catalogClass = $root . '/doctor/sdk/Generation/LockedComposerVendorCatalog.php';
if (!is_file($catalogClass) || is_link($catalogClass)) {
    throw new RuntimeException('First-party locked Composer materializer is unavailable or unsafe.');
}
require $catalogClass;

$result = (new \SysAdmin\Doctor\sdk\Generation\LockedComposerVendorCatalog($root))->materializeReleaseRoot($root);
echo sprintf(
    "Locked Composer vendor materialized locally: %d exact artifacts and %d directories.\n",
    $result['artifacts'],
    $result['directories'],
);

#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

$releaseRoot = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--release-root=')) {
        $releaseRoot = substr($argument, strlen('--release-root='));
        continue;
    }
    throw new InvalidArgumentException('Usage: capture-locked-composer-vendor.php --release-root=DIR');
}
$releaseRoot = realpath($releaseRoot) ?: '';
$temporaryRoot = realpath(sys_get_temp_dir());
$releaseParent = $releaseRoot !== '' ? dirname($releaseRoot) : '';
if ($releaseRoot === '' || $temporaryRoot === false
    || dirname($releaseParent) !== $temporaryRoot
    || !str_starts_with(basename($releaseParent), 'microsysadmin-release.')
    || basename($releaseRoot) !== 'microsysadmin'
    || !is_dir($releaseRoot . '/vendor') || is_link($releaseRoot . '/vendor')) {
    throw new RuntimeException('Locked Composer capture accepts only a validated build-release staging root.');
}

$sourceLock = APP_ROOT . '/composer.lock';
$releaseLock = $releaseRoot . '/composer.lock';
$sourceLockHash = is_file($sourceLock) ? hash_file('sha256', $sourceLock) : false;
$releaseLockHash = is_file($releaseLock) ? hash_file('sha256', $releaseLock) : false;
if (!is_string($sourceLockHash) || !is_string($releaseLockHash) || !hash_equals($sourceLockHash, $releaseLockHash)) {
    throw new RuntimeException('Release staging composer.lock differs from the source lock.');
}
$lock = json_decode((string) file_get_contents($sourceLock), true, 512, JSON_THROW_ON_ERROR);
$installedPath = $releaseRoot . '/vendor/composer/installed.json';
$installed = json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
$packageIdentity = static function (array $package): array {
    return [
        'name' => (string) ($package['name'] ?? ''),
        'version' => (string) ($package['version'] ?? ''),
        'reference' => (string) ($package['dist']['reference'] ?? $package['source']['reference'] ?? ''),
    ];
};
$expectedPackages = [];
foreach ((array) ($lock['packages'] ?? []) as $package) {
    $identity = $packageIdentity((array) $package);
    if ($identity['name'] === '' || isset($expectedPackages[$identity['name']])) {
        throw new RuntimeException('composer.lock contains an invalid production package identity.');
    }
    $expectedPackages[$identity['name']] = $identity;
}
$actualPackages = [];
foreach ((array) ($installed['packages'] ?? []) as $package) {
    $identity = $packageIdentity((array) $package);
    if ($identity['name'] === '' || isset($actualPackages[$identity['name']])) {
        throw new RuntimeException('Composer installed metadata contains an invalid package identity.');
    }
    $actualPackages[$identity['name']] = $identity;
}
ksort($expectedPackages, SORT_STRING);
ksort($actualPackages, SORT_STRING);
if ($expectedPackages !== $actualPackages) {
    throw new RuntimeException('Clean release Composer packages differ from the production lock set.');
}

$blobRoot = APP_ROOT . '/doctor/baselines/release-artifacts/blobs';
if (!is_dir($blobRoot) || is_link($blobRoot) || !is_writable($blobRoot)) {
    throw new RuntimeException('Release-artifact baseline blob root is unavailable or unsafe.');
}
$write = static function (string $path, string $contents, int $mode): void {
    $parent = dirname($path);
    if (!is_dir($parent) || is_link($parent)) {
        throw new RuntimeException("Capture output parent is unavailable or symbolic: {$parent}");
    }
    $temporary = $parent . '/.' . basename($path) . '.vendor-capture-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write capture output {$path}.");
    }
    chmod($temporary, $mode);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install capture output {$path}.");
    }
};

$vendor = $releaseRoot . '/vendor';
$directories = [];
$directoryIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD,
);
$vendorMode = fileperms($vendor) & 0777;
if ($vendorMode < 0700 || $vendorMode > 0775 || ($vendorMode & 0002) !== 0) {
    throw new RuntimeException('Clean Composer vendor root has an unsafe mode.');
}
$directories[] = ['path' => 'vendor', 'mode' => $vendorMode];
foreach ($directoryIterator as $entry) {
    if (!$entry->isDir()) {
        continue;
    }
    if ($entry->isLink()) {
        throw new RuntimeException('Clean Composer vendor contains a symbolic directory.');
    }
    $mode = fileperms($entry->getPathname()) & 0777;
    if ($mode < 0700 || $mode > 0775 || ($mode & 0002) !== 0) {
        throw new RuntimeException('Clean Composer vendor contains a directory outside the bounded mode policy.');
    }
    $directories[] = [
        'path' => 'vendor/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($vendor) + 1)),
        'mode' => $mode,
    ];
}
usort($directories, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD,
);
$artifacts = [];
foreach ($iterator as $entry) {
    if ($entry->isLink() || !$entry->isFile() || !$entry->isReadable()) {
        throw new RuntimeException('Clean Composer vendor contains a non-regular or unreadable artifact.');
    }
    $size = (int) $entry->getSize();
    $mode = fileperms($entry->getPathname()) & 0777;
    if ($size > 8_388_608 || $mode < 0600 || $mode > 0775 || ($mode & 0002) !== 0) {
        throw new RuntimeException('Clean Composer vendor contains an artifact outside the bounded size/mode policy.');
    }
    $relative = 'vendor/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($vendor) + 1));
    $contents = (string) file_get_contents($entry->getPathname());
    $sha256 = hash('sha256', $contents);
    $blobRelative = 'doctor/baselines/release-artifacts/blobs/' . $sha256;
    $blob = APP_ROOT . '/' . $blobRelative;
    if (is_file($blob)) {
        $actual = hash_file('sha256', $blob);
        if (!is_string($actual) || !hash_equals($sha256, $actual)) {
            throw new RuntimeException("Existing release baseline blob failed validation: {$sha256}");
        }
    } else {
        $write($blob, $contents, 0444);
    }
    $artifacts[] = ['path' => $relative, 'sha256' => $sha256, 'mode' => $mode, 'size' => $size, 'baseline' => $blobRelative];
}
usort($artifacts, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
if ($artifacts === []) {
    throw new RuntimeException('Clean Composer vendor capture produced no artifacts.');
}
$jsonCompact = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$manifest = [
    'schema_version' => 2,
    'source' => 'clean_commercial_release_composer_install',
    'composer_lock_sha256' => $sourceLockHash,
    'composer_install_flags' => ['--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-scripts', '--classmap-authoritative'],
    'packages' => array_values($expectedPackages),
    'artifact_count' => count($artifacts),
    'directory_count' => count($directories),
    'catalog_revision' => hash('sha256', $jsonCompact(['artifacts' => $artifacts, 'directories' => $directories])),
    'artifacts' => $artifacts,
    'directories' => $directories,
];
$manifestPath = APP_ROOT . '/doctor/baselines/locked-composer-vendor.json';
$write($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", 0644);

echo sprintf(
    "Locked Composer vendor capture: %d production packages, %d artifacts, %d directories, revision %s.\n",
    count($expectedPackages),
    count($artifacts),
    count($directories),
    $manifest['catalog_revision'],
);

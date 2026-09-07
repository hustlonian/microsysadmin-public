<?php

declare(strict_types=1);

$options = getopt('', [
    'manifest:',
    'package:',
    'public-key:',
    'detached-signature:',
]);

foreach (['manifest', 'package', 'public-key', 'detached-signature'] as $required) {
    if (!isset($options[$required]) || trim((string) $options[$required]) === '') {
        fwrite(STDERR, "Missing --{$required}.\n");
        exit(64);
    }
}

$manifestPath = (string) $options['manifest'];
$packagePath = (string) $options['package'];
$publicKeyPath = (string) $options['public-key'];
$detachedPath = (string) $options['detached-signature'];

foreach ([
    'manifest' => $manifestPath,
    'package' => $packagePath,
    'public key' => $publicKeyPath,
] as $label => $path) {
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Release {$label} is missing or unreadable.");
    }
}

$manifest = json_decode(
    (string) file_get_contents($manifestPath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
if (!is_array($manifest)) {
    throw new RuntimeException('Release manifest is not a JSON object.');
}
foreach (['product', 'version', 'channel', 'released_at', 'package', 'sha256', 'signature_algorithm', 'signature'] as $field) {
    if (!isset($manifest[$field]) || trim((string) $manifest[$field]) === '') {
        throw new RuntimeException("Release manifest is missing {$field}.");
    }
}
if ((string) $manifest['product'] !== 'MicroSysAdmin') {
    throw new RuntimeException('Release manifest identifies the wrong product.');
}
if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', (string) $manifest['version'])) {
    throw new RuntimeException('Release manifest version is not Semantic Versioning.');
}
if (!in_array((string) $manifest['channel'], ['stable', 'beta'], true)) {
    throw new RuntimeException('Customer release verification rejects non-stable/non-beta channels.');
}
if ((string) $manifest['package'] !== basename($packagePath)) {
    throw new RuntimeException('Manifest package name does not match the supplied archive.');
}

$actualChecksum = hash_file('sha256', $packagePath);
if (!is_string($actualChecksum) || !hash_equals(strtolower((string) $manifest['sha256']), strtolower($actualChecksum))) {
    throw new RuntimeException('Release package checksum verification failed.');
}
if ((string) $manifest['signature_algorithm'] !== 'RSA-SHA256') {
    throw new RuntimeException('Customer release signature algorithm must be RSA-SHA256.');
}

$signature = base64_decode((string) $manifest['signature'], true);
if (!is_string($signature) || $signature === '') {
    throw new RuntimeException('Release manifest signature is invalid.');
}
if (!is_file($detachedPath) || !is_readable($detachedPath)) {
    throw new RuntimeException('Detached release signature is missing or unreadable.');
}
$detached = (string) file_get_contents($detachedPath);
if (!hash_equals($signature, $detached)) {
    throw new RuntimeException('Detached signature does not match the manifest signature.');
}

$publicKeyPem = trim((string) file_get_contents($publicKeyPath));
$publicKey = openssl_pkey_get_public($publicKeyPem);
$details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
if ($publicKey === false || !is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
    throw new RuntimeException('Release public key is not a valid RSA public key.');
}
$canonical = implode("\n", [
    (string) $manifest['version'],
    (string) $manifest['channel'],
    strtolower((string) $manifest['sha256']),
    (string) $manifest['released_at'],
]);
if (openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
    throw new RuntimeException('Release signature verification failed.');
}

echo json_encode([
    'ok' => true,
    'product' => 'MicroSysAdmin',
    'version' => $manifest['version'],
    'channel' => $manifest['channel'],
    'package' => basename($packagePath),
    'sha256' => $actualChecksum,
    'signature_algorithm' => 'RSA-SHA256',
    'signature_verified' => true,
    'detached_signature_verified' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

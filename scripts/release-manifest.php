<?php

declare(strict_types=1);

$options = getopt('', [
    'version:',
    'channel:',
    'released-at:',
    'sha256:',
    'package-url:',
    'package-name:',
    'output:',
    'signature-output:',
    'signing-key-file:',
    'unsigned-development',
]);

$required = ['version', 'channel', 'released-at', 'sha256', 'package-name', 'output'];
foreach ($required as $key) {
    if (!isset($options[$key]) || trim((string) $options[$key]) === '') {
        fwrite(STDERR, "Missing --{$key}.\n");
        exit(64);
    }
}

$version = trim((string) $options['version']);
$channel = trim((string) $options['channel']);
$releasedAt = trim((string) $options['released-at']);
$checksum = strtolower(trim((string) $options['sha256']));
$packageUrl = trim((string) ($options['package-url'] ?? ''));
$packageName = basename((string) $options['package-name']);
$output = (string) $options['output'];
$signatureOutput = (string) ($options['signature-output'] ?? '');

if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
    throw new InvalidArgumentException('Release version is not valid Semantic Versioning.');
}
if (!in_array($channel, ['stable', 'beta', 'development'], true)) {
    throw new InvalidArgumentException('Release channel is invalid.');
}
if (strtotime($releasedAt) === false) {
    throw new InvalidArgumentException('Release timestamp is invalid.');
}
$releasedAt = gmdate(DATE_ATOM, (int) strtotime($releasedAt));
if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
    throw new InvalidArgumentException('Package SHA-256 is invalid.');
}
if ($packageUrl !== '' && !filter_var($packageUrl, FILTER_VALIDATE_URL)) {
    throw new InvalidArgumentException('Package URL is invalid.');
}

$canonical = implode("\n", [$version, $channel, $checksum, $releasedAt]);
$keyFile = (string) ($options['signing-key-file'] ?? '');
$unsignedDevelopment = isset($options['unsigned-development']);

if ($keyFile !== '') {
    $keyContents = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
    if (str_contains($keyContents, 'BEGIN') && str_contains($keyContents, 'PRIVATE KEY')) {
        $privateKey = openssl_pkey_get_private($keyContents);
        $details = $privateKey !== false ? openssl_pkey_get_details($privateKey) : false;
        if ($privateKey === false || !is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The PEM signing key must be an RSA private key.');
        }
        $signatureBytes = '';
        if (!openssl_sign($canonical, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL could not sign the release manifest.');
        }
        $signature = base64_encode($signatureBytes);
        $signatureAlgorithm = 'RSA-SHA256';
    } else {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('A base64 Ed25519 signing key requires the Sodium extension. Use a PEM RSA key on this PHP build.');
        }
        $secretKey = base64_decode($keyContents, true);
        $secretBytes = defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES')
            ? (int) constant('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES')
            : 64;
        if ($secretKey === false || strlen($secretKey) !== $secretBytes) {
            throw new RuntimeException('The signing-key file must contain a PEM RSA key or one base64 Ed25519 secret key.');
        }
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));
        $signatureAlgorithm = 'Ed25519';
    }
    $signed = true;
} elseif ($channel === 'development' && $unsignedDevelopment) {
    $signature = 'development-unsigned';
    $signatureAlgorithm = 'development-unsigned';
    $signed = false;
} else {
    throw new RuntimeException('Stable and beta releases require --signing-key-file. Unsigned development builds require --unsigned-development.');
}

$manifest = [
    'schema_version' => 1,
    'product' => 'MicroSysAdmin',
    'version' => $version,
    'channel' => $channel,
    'released_at' => $releasedAt,
    'package' => $packageName,
    'package_url' => $packageUrl,
    'sha256' => $checksum,
    'signature_algorithm' => $signatureAlgorithm,
    'signature' => $signature,
    'minimum_php' => '8.2.0',
];

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create manifest output directory.');
}
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $json, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write release manifest.');
}
chmod($output, 0644);
if ($signatureOutput !== '') {
    if (!$signed) {
        throw new RuntimeException('A detached signature cannot be emitted for an unsigned manifest.');
    }
    $signatureBytes = base64_decode($signature, true);
    if ($signatureBytes === false || $signatureBytes === '') {
        throw new RuntimeException('The generated signature could not be decoded.');
    }
    $signatureDirectory = dirname($signatureOutput);
    if (
        !is_dir($signatureDirectory)
        && !mkdir($signatureDirectory, 0775, true)
        && !is_dir($signatureDirectory)
    ) {
        throw new RuntimeException('Unable to create detached-signature output directory.');
    }
    if (file_put_contents($signatureOutput, $signatureBytes, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write detached release signature.');
    }
    chmod($signatureOutput, 0644);
}

echo json_encode([
    'manifest' => $output,
    'detached_signature' => $signatureOutput !== '' ? $signatureOutput : null,
    'version' => $version,
    'channel' => $channel,
    'signed' => $signed,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

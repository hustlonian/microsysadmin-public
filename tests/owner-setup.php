<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$configuration = require APP_ROOT . '/config/config.php';
if (!preg_match('~^/tmp/msa-job-test-[^/]+/app$~', APP_ROOT)
    || ($configuration['database']['name'] ?? '') !== 'microsysadmin_job_test_isolated'
    || ($configuration['database']['socket'] ?? '') !== dirname(APP_ROOT) . '/mysql.sock') {
    throw new RuntimeException('Use the disposable owner setup runner.');
}
require APP_ROOT . '/classes/bootstrap.php';
set_exception_handler(static function (Throwable $e): never { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });

use SysAdmin\Crypto;
use SysAdmin\Models\Setting;
use SysAdmin\OwnerSetupService;

$checks = 0;
$assert = static function (bool $value, string $why) use (&$checks): void {
    $checks++;
    if (!$value) throw new RuntimeException($why);
};
$service = new OwnerSetupService();
$assert($service->overview()['hosting']['state'] === 'HOSTING_NOT_CONFIGURED', 'Initial hosting state');
$host = ['provider' => 'cpanel', 'environment' => 'shared', 'domain' => 'example.test',
    'application_url' => 'https://example.test/app', 'deployment_method' => 'sftp',
    'deployment_scope' => 'Staging files only', 'database_host' => 'localhost',
    'database_name' => 'synthetic_db', 'database_username' => 'synthetic_user'];
$assert($service->saveHosting($host)['state'] === 'CREDENTIALS_REQUIRED', 'Missing credential state');
$credential = 'synthetic-host-value';
$saved = $service->saveHosting($host + ['deploy_credential' => $credential]);
$assert($saved['state'] === 'HOSTING_CONFIGURED_NOT_VERIFIED', 'Saved host must remain unverified');
foreach ($host as $field => $value) $assert($saved['configuration'][$field] === $value, 'Missing hosting field: ' . $field);
$roundTrip = $service->saveHosting((new OwnerSetupService())->overview()['hosting']['configuration'] + ['deploy_credential' => '']);
$assert($roundTrip['configuration'] === $host, 'Form read/save lost hosting settings');
$assert($roundTrip['credential_saved'], 'Blank form erased hosting credential');
$mail = $service->saveEmail(['provider' => 'smtp', 'owner_email' => 'owner@example.test',
    'sender_email' => 'sender@example.test', 'additional_recipients' => 'admin@example.test; admin@example.test',
    'provider_credential' => 'synthetic-mail-value']);
$assert($mail['state'] === 'EMAIL_CONFIGURED_NOT_TESTED' && $mail['delivery_status'] === 'NOT_TESTED', 'Invented mail verification');
$assert($mail['configuration']['additional_recipients'] === 'admin@example.test', 'Recipient normalization');
$mailAgain = $service->saveEmail($mail['configuration'] + ['provider_credential' => '']);
$assert($mailAgain['credential_saved'], 'Blank form erased email credential');
$restricted = implode('_', ['rk', 'test', 'synthetic12345678']);
$webhook = 'whsec_' . 'synthetic12345678';
$stripe = $service->saveStripeCredentials('test', $restricted, $webhook);
$assert($stripe['test_credential_saved'] && $stripe['test_webhook_saved'], 'Restricted key or webhook not saved');
$assert(!$stripe['live_credential_saved'] && !$stripe['live_webhook_saved'] && !$stripe['live_payments_enabled'], 'Test mode affected live mode');
$service->saveStripeCredentials('test', $restricted);
$stored = json_decode(Setting::all()['owner_setup.configuration.v1'], true, 512, JSON_THROW_ON_ERROR);
$assert(Crypto::decrypt($stored['hosting']['deploy_credential']) === $credential, 'Hosting encryption round-trip');
$assert(Crypto::decrypt($stored['stripe']['test_secret']) === $restricted, 'Restricted key encryption round-trip');
$assert(Crypto::decrypt($stored['stripe']['test_webhook_secret']) === $webhook, 'Blank signing secret erased prior value');
$assert(!str_contains(json_encode($stored), $restricted) && !str_contains(json_encode($service->overview()), $restricted), 'Credential disclosure');
foreach ([['live', $restricted], ['test', implode('_', ['rk', 'live', 'synthetic12345678'])], ['test', $restricted . "\n"]] as [$mode, $key]) {
    $before = Setting::all()['owner_setup.configuration.v1'];
    try { $service->saveStripeCredentials($mode, $key); throw new RuntimeException('Invalid mode/key accepted'); }
    catch (InvalidArgumentException) { $assert(Setting::all()['owner_setup.configuration.v1'] === $before, 'Invalid key mutated settings'); }
}
// Render the actual form after a fresh read, exercising the save/read/view contract.
$setup = (new OwnerSetupService())->overview();
ob_start(); require APP_ROOT . '/views/pages/owner-setup.php'; $html = ob_get_clean();
foreach (['synthetic_db', 'synthetic_user', 'Staging files only', 'HOSTING_CONFIGURED_NOT_VERIFIED', 'restricted API key'] as $text) {
    $assert(str_contains($html, $text), 'Missing saved form value: ' . $text);
}
$assert(!str_contains($html, $credential) && !str_contains($html, $restricted) && !str_contains($html, $webhook), 'Secret rendered in HTML');
$assert(!$setup['stripe']['live_payments_enabled'], 'Live payments activated');
$hostingCalls = 0;
$hostingMutationBlocked = false;
$hostingReceipt = $service->verifyHostingCompatibility('host-check-0001', function (array $configuration, string $secret, string $requestId) use (&$hostingCalls, &$hostingMutationBlocked, $credential, $host, $service): array {
    $hostingCalls++;
    if ($configuration['provider'] !== 'cpanel' || $secret !== $credential || $requestId !== 'host-check-0001') throw new RuntimeException('Hosting adapter inputs');
    try { $service->saveHosting($host); }
    catch (RuntimeException $exception) { $hostingMutationBlocked = str_contains($exception->getMessage(), 'in progress'); }
    return ['php' => true, 'database' => true, 'scheduler' => true, 'queue' => true, 'ssl' => true, 'backup' => true, 'restore' => true];
});
$assert($hostingReceipt['status'] === 'HOSTING_COMPATIBILITY_VERIFIED' && !$hostingReceipt['replayed'], 'Hosting receipt missing');
$assert($service->verifyHostingCompatibility('host-check-0001', function (): never { throw new RuntimeException('Replayed hosting probe ran'); })['replayed'], 'Hosting replay not fenced');
$assert($hostingCalls === 1 && $hostingMutationBlocked && (new OwnerSetupService())->overview()['hosting']['state'] === 'HOSTING_COMPATIBILITY_VERIFIED', 'Hosting verification not durable or isolated');
$deliveryCalls = 0;
$emailMutationBlocked = false;
$delivery = $service->verifyEmailDelivery('mail-check-0001', function (array $configuration, string $secret, string $requestId) use (&$deliveryCalls, &$emailMutationBlocked, $mail, $service): array {
    $deliveryCalls++;
    if ($configuration['owner_email'] !== 'owner@example.test' || $secret !== 'synthetic-mail-value' || $requestId !== 'mail-check-0001') throw new RuntimeException('Mail adapter inputs');
    try { $service->saveEmail($mail['configuration']); }
    catch (RuntimeException $exception) { $emailMutationBlocked = str_contains($exception->getMessage(), 'requires reconciliation'); }
    return ['status' => 'DELIVERED', 'provider_message_id' => 'synthetic-provider-message-1'];
});
$assert($delivery['status'] === 'DELIVERED' && !$delivery['replayed'], 'Email receipt missing');
$assert($service->verifyEmailDelivery('mail-check-0001', function (): never { throw new RuntimeException('Replayed email ran'); })['replayed'], 'Email replay not fenced');
$assert($deliveryCalls === 1 && $emailMutationBlocked && (new OwnerSetupService())->overview()['email']['state'] === 'EMAIL_TEST_DELIVERY_VERIFIED', 'Email delivery not durable or isolated');
$unknownCalls = 0;
try {
    $service->verifyEmailDelivery('mail-unknown-0001', function () use (&$unknownCalls): never { $unknownCalls++; throw new RuntimeException('Synthetic adapter interruption'); });
    throw new RuntimeException('Unknown email outcome accepted');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'outcome is unknown'), 'Unknown email outcome reason');
}
try {
    $service->verifyEmailDelivery('mail-after-unknown-0001', function () use (&$unknownCalls): never { $unknownCalls++; throw new RuntimeException('Unreconciled email ran'); });
    throw new RuntimeException('Unreconciled email retry accepted');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'requires reconciliation'), 'Unreconciled email retry reason');
}
$assert($unknownCalls === 1 && (new OwnerSetupService())->overview()['email']['delivery_status'] === 'OUTCOME_UNKNOWN_NO_RETRY', 'Unknown email outcome was not fenced durably');
$reconciled = $service->reconcileEmailDelivery('mail-unknown-0001', 'NOT_DELIVERED');
$assert($reconciled['status'] === 'NOT_DELIVERED_RECONCILED' && (new OwnerSetupService())->overview()['email']['delivery_status'] === 'NOT_TESTED', 'Email reconciliation did not restore a safe retry state');
$payload = json_encode(['id' => 'evt_test_owner_0001', 'livemode' => false], JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$signature = \SysAdmin\WebhookSigner::sign($webhook, $timestamp, $payload);
$stripeReceipt = $service->verifyStripeTestWebhook('evt_test_owner_0001', $timestamp, $payload, $signature);
$assert($stripeReceipt['status'] === 'TEST_WEBHOOK_VERIFIED' && !$stripeReceipt['replayed'], 'Stripe webhook receipt missing');
$assert($service->verifyStripeTestWebhook('evt_test_owner_0001', $timestamp, $payload, $signature)['replayed'], 'Stripe webhook replay not fenced');
try { $service->verifyStripeTestWebhook('evt_test_owner_0001', $timestamp, $payload, 'v1=' . str_repeat('0', 64)); throw new RuntimeException('Unsigned Stripe replay accepted'); }
catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'signature'), 'Unsigned Stripe replay reason'); }
$changedPayload = $payload . ' ';
$changedSignature = \SysAdmin\WebhookSigner::sign($webhook, $timestamp, $changedPayload);
try { $service->verifyStripeTestWebhook('evt_test_owner_0001', $timestamp, $changedPayload, $changedSignature); throw new RuntimeException('Changed Stripe replay accepted'); }
catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'different payload'), 'Changed Stripe replay reason'); }
try {
    $livePayload = json_encode(['id' => 'evt_test_owner_0002', 'livemode' => true], JSON_THROW_ON_ERROR);
    $service->verifyStripeTestWebhook('evt_test_owner_0002', $timestamp, $livePayload, \SysAdmin\WebhookSigner::sign($webhook, $timestamp, $livePayload));
    throw new RuntimeException('Live Stripe event accepted by test verifier');
} catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'mode'), 'Live Stripe event rejection reason'); }
$fresh = (new OwnerSetupService())->overview();
$assert($fresh['stripe']['test_state'] === 'TEST_WEBHOOK_VERIFIED_API_NOT_VERIFIED' && $fresh['stripe']['test_webhook_status'] === 'VERIFIED_IDEMPOTENT', 'Stripe webhook state not durable');
$encodedOverview = json_encode($fresh, JSON_THROW_ON_ERROR);
$assert(!str_contains($encodedOverview, $credential) && !str_contains($encodedOverview, 'synthetic-mail-value') && !str_contains($encodedOverview, $webhook), 'Verification leaked a secret');
$sameCredential = $service->saveStripeCredentials('test', $restricted);
$assert($sameCredential['test_webhook_status'] === 'VERIFIED_IDEMPOTENT', 'Unchanged Stripe credential invalidated webhook receipt');
$differentRestricted = implode('_', ['rk', 'test', 'synthetic87654321']);
$changedCredential = $service->saveStripeCredentials('test', $differentRestricted);
$assert($changedCredential['test_state'] === 'TEST_CREDENTIALS_SAVED' && $changedCredential['test_webhook_status'] === 'NOT_VERIFIED', 'Changed Stripe credential retained a stale webhook receipt');
echo "PASS: owner setup persistence, rendering, encryption and mode isolation — {$checks} assertions; external calls: 0.\n";

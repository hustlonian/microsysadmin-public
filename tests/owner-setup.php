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
echo "PASS: owner setup persistence, rendering, encryption and mode isolation — {$checks} assertions; external calls: 0.\n";

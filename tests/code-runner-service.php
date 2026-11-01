<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$configuration = require APP_ROOT . '/config/config.php';
if (!preg_match('/\Amicrosysadmin_(?:test_doctor_[A-Za-z0-9]+|ci)\z/', (string)$configuration['database']['name'])) {
    throw new RuntimeException('Runner service tests require the explicitly disposable Doctor test database.');
}
require APP_ROOT . '/classes/bootstrap.php';

use SysAdmin\{Database, LicenseManager, LocalLicenseService, MachineIdentity, SandboxService, SecurityProfile, HttpException};

// A real local license row in a disposable database; never a production entitlement change.
$pdo = Database::pdo(); $now = date('Y-m-d H:i:s');
$organization = (int)$pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn();
$plan = (int)$pdo->query("SELECT id FROM plans WHERE code='development'")->fetchColumn();
if (!$pdo->query("SELECT id FROM licenses WHERE source='isolated-runner-fixture'")->fetchColumn()) {
    $pdo->prepare('INSERT INTO licenses (uuid,organization_id,plan_id,status,source,starts_at,last_verified_at,metadata,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([MachineIdentity::uuid(), $organization, $plan, 'active', 'isolated-runner-fixture', $now, $now, '{"fixture":true}', $now, $now]);
}
LicenseManager::useService(new LocalLicenseService());
$service = new SandboxService(); $assertions = 0;
$check = static function (bool $ok, string $why) use (&$assertions): void { $assertions++; if (!$ok) { throw new RuntimeException($why); } };
SecurityProfile::set('developer', true);
foreach (['bash' => "echo 'service bash'", 'php' => "<?php echo 'service php';", 'python' => "print('service python')", 'javascript' => "console.log('service javascript')"] as $language => $code) {
    $r = $service->run($language, $code, '', 5, true);
    $check($r['ok'] && trim($r['output']) === 'service ' . $language, 'Actual SandboxService run: ' . $language . ' ' . json_encode($r));
}
try { $service->run('bash', 'echo denied', '', 5, false); throw new RuntimeException('Confirmation bypassed'); }
catch (InvalidArgumentException) { $check(true, 'Existing confirmation requirement retained'); }
try { $service->startTerminal('bash', '', true); throw new LogicException('Host shell was exposed'); }
catch (RuntimeException $e) { $check($e instanceof HttpException || str_contains($e->getMessage(), 'disabled'), 'Host-shell start blocked'); }
try { $service->terminalInput(str_repeat('a',32), 'id'); throw new LogicException('Host shell input exposed'); }
catch (RuntimeException $e) { $check($e instanceof HttpException || str_contains($e->getMessage(), 'disabled'), 'Legacy shell input blocked'); }
SecurityProfile::set('safe', true);
try { $service->run('bash', 'echo denied', '', 5, true); throw new LogicException('Safe profile bypassed'); }
catch (HttpException $e) { $check($e->statusCode() === 403, 'Safe profile remains enforced'); }
SecurityProfile::set('developer', true);
LicenseManager::useService(new class implements \SysAdmin\Contracts\LicensingServiceInterface {
    public function status(): array { return []; }
    public function entitlement(string $key, mixed $default = null): mixed { return false; }
    public function allows(string $key): bool { return false; }
    public function assert(string $key): void { throw new RuntimeException('Unavailable'); }
});
try { $service->run('bash', 'echo denied', '', 5, true); throw new LogicException('License bypassed'); }
catch (HttpException $e) { $check($e->statusCode() === 403, 'License gate remains enforced'); }
LicenseManager::useService(new LocalLicenseService());
echo json_encode(['status' => 'PASS', 'assertions' => $assertions, 'database_scope' => 'DISPOSABLE_ONLY']) . "\n";

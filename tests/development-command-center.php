<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$configuration = require APP_ROOT . '/config/config.php';
if (!preg_match('~^/tmp/msa-job-test-[^/]+/app$~', APP_ROOT)
    || ($configuration['database']['name'] ?? '') !== 'microsysadmin_job_test_isolated'
    || ($configuration['database']['socket'] ?? '') !== dirname(APP_ROOT) . '/mysql.sock') {
    throw new RuntimeException('Use the disposable development runner.');
}
require APP_ROOT . '/classes/bootstrap.php';
set_exception_handler(static function (Throwable $e): never { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });

use SysAdmin\Auth;
use SysAdmin\Database;
use SysAdmin\DevelopmentCommandCenter;
use SysAdmin\HttpException;
use SysAdmin\Controllers\DevelopmentController;

$pdo = Database::pdo();
$now = date('Y-m-d H:i:s');
$organization = static function (string $slug) use ($pdo, $now): int {
    $pdo->prepare('INSERT INTO organizations(uuid,name,slug,status,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([bin2hex(random_bytes(18)), 'Fixture ' . $slug, $slug, 'active', $now, $now]);
    return (int) $pdo->lastInsertId();
};
$org = $organization('development-owner');
$other = $organization('development-other');
$createUser = static function (string $name, int $organization, string $role = 'Viewer') use ($pdo, $now): int {
    $pdo->prepare('INSERT INTO users(username,password_hash,role,organization_id,is_disabled,force_password_change,created_at,updated_at) VALUES(?,?,?,?,0,0,?,?)')
        ->execute([$name, password_hash('DisposableOnly-789', PASSWORD_DEFAULT), $role, $organization, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO organization_members(organization_id,user_id,organization_role,status,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([$organization, $id, $role === 'Administrator' ? 'admin' : 'viewer', 'active', $now, $now]);
    return $id;
};
$owner = $createUser('development-owner', $org);
$viewer = $createUser('development-viewer', $org);
$unassigned = $createUser('development-unassigned', $org);
$outsider = $createUser('development-other-admin', $other, 'Administrator');
$fixture = STORAGE_PATH . '/development-fixture.json';
$actual = json_decode((string) file_get_contents($fixture), true, 48, JSON_THROW_ON_ERROR);
if (($actual['kind'] ?? '') !== 'DEVELOPMENT_COMMAND_CENTER' || ($actual['project']['id'] ?? '') !== 'digital-empire') {
    throw new RuntimeException('Actual sanitized controller projection was not provided.');
}
$GLOBALS['sysadmin_config']['development']['projects']['digital-empire'] = [
    'organization_id' => $org, 'tenant_key' => 'customer-zero-digital-empire', 'name' => 'Digital Empire',
    'projection_file' => $fixture, 'users' => [$owner => 'owner', $viewer => 'viewer'],
];
$_SESSION['user_id'] = $owner;
$service = new DevelopmentCommandCenter();
$checks = 0;
$assert = static function (bool $value, string $why) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($why); } };
$assert($service->authorize('digital-empire') === 'owner', 'Assigned tenant owner denied');
$assert(!Auth::can('security.settings'), 'Tenant owner unexpectedly has platform privileges');
$report = $service->report('digital-empire');
$assert($report['available'], 'Actual controller projection unavailable');
$assert($report['access']['role'] === 'owner' && $report['access']['mode'] === 'READ_ONLY'
    && $report['access']['owner_decisions'] === 'NOT_CONNECTED', 'Host role implied canonical approval authority');
$assert(count($report['history']) >= 3, 'Real previous checkpoints not imported');
$assert(count($report['workstreams']) === 12 && count($report['gates']) === 12, 'Digital Empire scope missing');
$assert($report['readiness'] === 'NOT_VERIFIED', 'Fabricated release readiness');
$assert(array_unique(array_column($report['gates'], 'state')) === ['DRAFT'], 'Infrastructure completion promoted top-level gates');
$assert(!str_contains(json_encode($report), '/opt/lampp'), 'Private host paths exposed');
$assert(!isset($report['project']['tenant_key']), 'Internal tenant binding exported');
$assert($service->filter($report, ['agent' => 'no-such-agent'])['history'] === [], 'History filter ignored');
$assert(str_contains($service->markdown($report), 'NOT_VERIFIED'), 'Markdown hides readiness limits');
foreach (['overview','history','working','roadmap','gates','evidence','reports'] as $view) {
    $_GET = ['project' => 'digital-empire', 'view' => $view];
    ob_start(); (new DevelopmentController())->handle('development-command-center'); $html = (string) ob_get_clean();
    $assert(str_contains($html, 'main-sidebar') && str_contains($html, 'Development Command Center'), 'Host AdminLTE layout not preserved: ' . $view);
    $assert(str_contains($html, 'development-command-center'), 'Working host navigation missing');
    file_put_contents(STORAGE_PATH . '/development-' . $view . '.html', $html);
}
foreach (['snapshot','export.json','export.md'] as $endpoint) {
    $_GET = ['project' => 'digital-empire'];
    ob_start(); (new DevelopmentController())->handle('development-command-center/' . $endpoint); $body = (string) ob_get_clean();
    $assert($body !== '' && !str_contains($body, 'password_hash'), 'Unsafe export or API');
}
$_GET = ['project' => 'digital-empire', 'partial' => '1'];
ob_start(); (new DevelopmentController())->handle('development-command-center'); $partial = (string) ob_get_clean();
$assert(str_contains($partial, 'development-content') && !str_contains($partial, 'main-sidebar'), 'Polling did not return scoped partial');
$_SESSION['user_id'] = $viewer;
$assert($service->authorize('digital-empire') === 'viewer', 'Assigned viewer denied');
foreach ([$outsider, $unassigned] as $denied) {
    $_SESSION['user_id'] = $denied;
    $assert($service->projects() === [], 'Other project metadata leaked');
    foreach (['snapshot','export.json','export.md'] as $endpoint) {
        $_GET = ['project' => 'digital-empire'];
        try { (new DevelopmentController())->handle('development-command-center/' . $endpoint); throw new RuntimeException('Scope bypass: ' . $endpoint); }
        catch (HttpException $e) { $assert($e->statusCode() === 403, 'Incorrect scope rejection'); }
    }
}
$_SESSION['user_id'] = $owner;
$pdo->prepare("UPDATE organization_members SET status='suspended' WHERE user_id=?")->execute([$owner]);
try { $service->authorize('digital-empire'); throw new RuntimeException('Revoked membership accepted'); }
catch (HttpException $e) { $assert($e->statusCode() === 403, 'Revocation not enforced'); }
$pdo->prepare("UPDATE organization_members SET status='active' WHERE user_id=?")->execute([$owner]);
$stale = $actual; $stale['source']['persisted_at'] = '2020-01-01T00:00:00+00:00';
file_put_contents($fixture, json_encode($stale));
$report = $service->report('digital-empire');
$assert($report['freshness'] === 'STALE' && $report['ownership']['status'] === 'NOT_CONFIRMED_ACTIVE', 'Stale agent shown active');
$wrong = $actual; $wrong['project']['tenant_key'] = 'different-tenant'; file_put_contents($fixture, json_encode($wrong));
$assert(!$service->report('digital-empire')['available'], 'Wrong-tenant projection accepted');
$incomplete = $actual; unset($incomplete['last_verified']); file_put_contents($fixture, json_encode($incomplete));
$assert(!$service->report('digital-empire')['available'], 'Incomplete projection accepted');
file_put_contents($fixture, '{interrupted');
$assert(!$service->report('digital-empire')['available'], 'Corrupt projection presented as live');
$assert($service->report('digital-empire')['access']['project_assignment'] === 'EXPLICIT', 'Unavailable data hid verified enrollment');
file_put_contents($fixture, json_encode($actual));
// Optional replay from the actual canonical importer in a disposable workflow database.
$domainPath = STORAGE_PATH . '/development-domain-replay.json';
if (is_file($domainPath)) {
    $domain = json_decode((string) file_get_contents($domainPath), true, 48, JSON_THROW_ON_ERROR);
    $domain['source']['captured_at'] = gmdate('c'); // Replayed capture, never operational acceptance.
    file_put_contents($domainPath, json_encode($domain));
    $GLOBALS['sysadmin_config']['development']['projects']['digital-empire']['domain_projection_file'] = $domainPath;
    $report = $service->report('digital-empire');
    $assert($report['domain']['status'] === 'RECENT', 'Canonical imported domain replay unavailable');
    $assert(count($report['gates']) === 12 && array_unique(array_column($report['gates'], 'state')) === ['DRAFT'], 'Import fabricated gate acceptance');
    $assert(!empty($report['gates'][0]['domain']['unmet']), 'Actual independent acceptance blockers missing');
    $assert(count($report['gates'][0]['domain']['evidence']) === count($report['gates'][0]['criteria']), 'Criterion assessments omitted');
    $binding =& $GLOBALS['sysadmin_config']['development']['projects']['digital-empire'];
    $binding['owner_review'] = ['base_url' => 'http://127.0.0.1:18081', 'bindings' => [$owner => 'fixture-owner-review']];
    $reviewReport = $service->report('digital-empire');
    $links = array_values(array_filter($reviewReport['gates'], static fn(array $gate): bool => isset($gate['review_url'])));
    $assert($reviewReport['owner_review']['status'] === 'INDEPENDENT_LOGIN_REQUIRED' && count($links) > 0, 'Explicit owner review navigation missing');
    parse_str((string) parse_url($links[0]['review_url'], PHP_URL_QUERY), $reviewInput);
    $assert($reviewInput['review_binding'] === 'fixture-owner-review' && $reviewInput['revision'] === (string) $links[0]['domain']['revision']
        && $reviewInput['source_hash'] === $links[0]['domain']['source_hash'] && strlen($reviewInput['definition_hash']) === 64,
        'Review link lost exact canonical context');
    $assert(!str_contains($links[0]['review_url'], 'expires_at') && !str_contains($links[0]['review_url'], 'operation_id'), 'Navigation attempted to submit a decision');
    $_SESSION['user_id'] = $viewer;
    $assert(!array_filter($service->report('digital-empire')['gates'], static fn(array $gate): bool => isset($gate['review_url'])), 'Viewer acquired owner review navigation');
    $_SESSION['user_id'] = $owner;
    foreach (['javascript:alert(1)','http://example.invalid','https://example.invalid/redirect','https://user:pass@example.invalid','https://example.invalid?next=elsewhere'] as $unsafe) {
        $binding['owner_review']['base_url'] = $unsafe;
        $assert($service->report('digital-empire')['owner_review']['status'] === 'INVALID_CONFIGURATION', 'Unsafe review destination allowed');
    }
    $binding['owner_review']['base_url'] = 'http://127.0.0.1:18081';
    $_GET = ['project' => 'digital-empire', 'view' => 'evidence'];
    ob_start(); (new DevelopmentController())->handle('development-command-center'); $evidenceHtml = (string) ob_get_clean();
    $assert(str_contains($evidenceHtml, 'Assessment at capture') && str_contains($evidenceHtml, 'No matching evidence record')
        && str_contains($evidenceHtml, 'LG-12.criterion-3'), 'Evidence inspection missing exact criteria or absence of proof');
    $assert(str_contains($evidenceHtml, 'Project access and enrollment') && str_contains($evidenceHtml, 'Owner decisions are not connected'), 'Enrollment authority boundary hidden');
    $_GET = ['project' => 'digital-empire', 'view' => 'gates'];
    ob_start(); (new DevelopmentController())->handle('development-command-center'); $html = (string) ob_get_clean();
    $assert(str_contains($html, 'Decision revision') && str_contains($html, 'LG-12.criterion-3'), 'Imported gate provenance/requirements not rendered');
    $assert(str_contains($html, 'data-owner-review') && str_contains($html, 'Independent owner login required'), 'Owner review boundary not rendered');
    foreach (['tenant','requirements','duplicate','completion','finish-line','evidence-status','evidence-reference','evidence-missing','evidence-empty-pass','evidence-contradiction','clock','active','stale'] as $case) {
        $changed = $domain;
        if ($case === 'tenant') $changed['project']['tenant_key'] = 'other-tenant';
        if ($case === 'requirements') $changed['gates'][0]['definition']['criteria'][0]['description'] = 'Forged requirement';
        if ($case === 'duplicate') $changed['gates'][1]['key'] = $changed['gates'][0]['key'];
        if ($case === 'completion') $changed['gates'][0]['state'] = 'UNLOCKED';
        if ($case === 'finish-line') $changed['scope']['definition_of_done']['initial_scope'] = ['LG-01'];
        $criterionId = $domain['gates'][0]['definition']['criteria'][0]['id'];
        if ($case === 'evidence-status') $changed['gates'][0]['assessment']['evidence'][$criterionId]['status'] = 'ACCEPTED_BY_OWNER';
        if ($case === 'evidence-reference') $changed['gates'][0]['assessment']['evidence'][$criterionId]['evidence_id'] = '/private/other-tenant';
        if ($case === 'evidence-missing') unset($changed['gates'][0]['assessment']['evidence'][$criterionId]);
        if ($case === 'evidence-empty-pass') $changed['gates'][0]['assessment']['evidence'][$criterionId] = ['status' => 'PASS', 'evidence_id' => null];
        if ($case === 'evidence-contradiction') $changed['gates'][0]['assessment']['accepted'] = true;
        if ($case === 'clock') $changed['source']['captured_at'] = gmdate('c', time()+3600);
        if ($case === 'stale') $changed['source']['captured_at'] = '2020-01-01T00:00:00+00:00';
        if ($case === 'active') { $changed['gates'][0]['state'] = 'ACTIVE'; $changed['gates'][0]['heartbeat_at'] = '2020-01-01T00:00:00+00:00'; }
        file_put_contents($domainPath, json_encode($changed));
        $result = $service->report('digital-empire');
        if (in_array($case, ['clock','active','stale'], true)) {
            $assert($result['gates'][0]['state'] === 'BLOCKED', 'Expired/clock-skewed gate claims current status: ' . $case);
            if ($case !== 'active') $assert($result['owner_review']['status'] === 'FRESH_WORKFLOW_REQUIRED', 'Stale snapshot retained review navigation');
        } else {
            $assert($result['domain']['status'] === 'UNAVAILABLE' && $result['gates'][0]['state'] === 'DRAFT', 'Invalid domain bypass: ' . $case);
        }
    }
    file_put_contents($domainPath, json_encode($domain));
    $_SESSION['user_id'] = $outsider;
    try { $service->report('digital-empire'); throw new RuntimeException('Domain export bypassed tenant auth'); }
    catch (HttpException $e) { $assert($e->statusCode() === 403, 'Domain tenant denial missing'); }
    $_SESSION['user_id'] = $owner;
}
// Keep only generated fixture configuration for browser acceptance; never installed config.
$config = $GLOBALS['sysadmin_config'];
file_put_contents(APP_ROOT . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
file_put_contents(STORAGE_PATH . '/development-browser.json', json_encode(['owner_id'=>$owner,'viewer_id'=>$viewer,'outsider_id'=>$outsider,'organization_id'=>$org]));
echo "PASS: development host bridge, real controller replay, authorization, rendering and freshness — {$checks} assertions.\n";

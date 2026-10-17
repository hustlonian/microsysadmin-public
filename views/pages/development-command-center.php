<?php
$projectUrl = static fn(string $view): string => url('development-command-center') . '?' . http_build_query(['project' => $selectedProject, 'view' => $view]);
?>
<section id="development-content" data-generation="<?= e($report['source']['generation'] ?? '') ?>">
<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3">Development Command Center</h1><p class="text-muted">What happened · What is happening · What happens next · What proves completion</p></div></div>
<form method="get" class="card card-body mb-3"><label for="development-project">Project</label><div class="input-group"><select class="form-select" id="development-project" name="project"><option value="">Select an authorized project</option><?php foreach ($projects as $item): ?><option value="<?= e($item['id']) ?>" <?= $selectedProject === $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?> · <?= e($item['role']) ?></option><?php endforeach; ?></select><button class="btn btn-primary">Open project</button></div></form>
<?php if (isset($report['access'])): ?>
<details class="card card-body mb-3"><summary>Project access and enrollment</summary><p>Your assigned project role: <strong><?= e($report['access']['role']) ?></strong>. Your organization membership is active and your project assignment is explicit.</p><p>This Command Center is read-only. Owner decisions are not connected. A project owner role here does not grant approval access in Software Ops.</p><p>Project: <code><?= e($selectedProject) ?></code>. Controller data: <?= !empty($report['available']) ? 'Available; check the as-of time below.' : 'Unavailable; access alone does not enroll a data source.' ?></p></details>
<?php endif; ?>
<?php if ($report !== null && empty($report['available'])): ?>
<div class="alert alert-warning" role="status">Project data is unavailable. No cached progress is being represented as live. Project readiness is not verified.</div>
<?php elseif ($report !== null): ?>
<h2 class="h4"><?= e($report['project']['name']) ?></h2>
<p>Owner: <?= e($report['project']['owner']) ?> · Stage: <?= e($report['lifecycle']) ?> · Initial scope v<?= e((string) $report['project']['scope_version']) ?></p>
<div class="alert alert-light border">Source: <?= e($report['source']['kind']) ?> · Revision <code><?= e($report['source']['generation']) ?></code><br>As of <?= e($report['source']['persisted_at']) ?> · <strong><?= e($report['freshness']) ?></strong> · Overall readiness: <strong>NOT VERIFIED</strong></div>
<nav class="nav nav-pills mb-3" aria-label="Development views"><?php foreach (['overview'=>'Executive overview','history'=>'Accomplishments / History','working'=>'Working now','roadmap'=>'Future roadmap','gates'=>'LockGate board','evidence'=>'Evidence inspection','reports'=>'Reports'] as $key=>$label): ?><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e($projectUrl($key)) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<?php if ($tab === 'overview' || $tab === 'working'): ?>
<div class="row"><div class="col-lg-6"><div class="card card-body"><h3 class="h5">Current agent activity</h3><p>Agent: <?= e($report['current_agent'] ?? 'Not recorded') ?></p><p><strong data-agent-status data-heartbeat="<?= e($report['ownership']['heartbeat_at'] ?? '') ?>"><?= e($report['ownership']['status']) ?></strong> · Heartbeat: <?= e($report['ownership']['heartbeat_at'] ?? 'Not recorded') ?></p><p>Milestone: <?= e($report['mission']['milestone'] ?? 'Not recorded') ?> · Gate: <?= e($report['mission']['gate'] ?? 'Not recorded') ?></p><p>Current checkpoint: <?= e($report['mission']['operation'] ?? 'Not recorded') ?><br>Step: <?= e($report['mission']['step'] ?? 'Not recorded') ?></p><p>Next scoped operation: <?= e($report['next_action'] ?? 'Not recorded') ?></p><p>Objective: <?= e($report['mission']['objective'] ?? 'Deliver the current authorized milestone with independently verified evidence.') ?><br>Expected deliverable: <?= e($report['mission']['expected_deliverable'] ?? 'See the milestone acceptance contract.') ?></p></div></div>
<div class="col-lg-6"><div class="card card-body"><h3 class="h5">Last verified completed checkpoint</h3><p><?= e($report['last_verified']['operation'] ?? 'Not verified') ?></p><p><code><?= e($report['last_verified']['commit'] ?? '') ?></code></p><p><?= e($report['last_verified']['evidence']) ?> · <?= e($report['last_verified']['scope']) ?></p><p>Latest retained test result: <?= e(json_encode($report['last_verified']['test_counts'])) ?></p><p>Historical test results do not establish current commercial readiness.</p></div></div></div>
<h3 class="h5">Blockers and pending human decisions</h3><ul><?php foreach ($report['pending_approvals'] as $approval): ?><li><?= e($approval) ?> — owner authorization remains required.</li><?php endforeach; ?></ul>
<h3 class="h5">Current risks</h3><ul><?php foreach ($report['risks'] as $risk): ?><li><?= e($risk) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($tab === 'overview' || $tab === 'history'): ?>
<h3 class="h5">Verified accomplishments</h3>
<?php if ($tab === 'history'): ?><form method="get" class="row g-2 mb-3"><input type="hidden" name="project" value="<?= e($selectedProject) ?>"><input type="hidden" name="view" value="history"><?php foreach (['agent','workstream','milestone'] as $filter): ?><label class="col-lg-3"><?= e(ucfirst($filter)) ?><input class="form-control" name="<?= e($filter) ?>" value="<?= e(is_string($_GET[$filter] ?? null) ? $_GET[$filter] : '') ?>"></label><?php endforeach; ?><div class="col-lg-2 align-self-end"><button class="btn btn-outline-primary">Filter history</button></div></form><?php endif; ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Time / agent</th><th>Work completed</th><th>Source</th><th>Acceptance evidence</th></tr></thead><tbody>
<?php foreach (array_slice($report['history'], 0, $tab === 'overview' ? 5 : 100) as $entry): ?><tr><td><?= e($entry['observed_at'] ?? 'Not recorded') ?><br><?= e($entry['agent']) ?></td><td><?= e($entry['work_completed']) ?><br><small>Project <?= e($entry['project']) ?> · <?= e($entry['workstream']) ?><br>Milestone / task: <?= e($entry['milestone']) ?></small><details><summary>Changed files / components</summary><?php foreach ($entry['files'] as $file): ?><div><code><?= e($file) ?></code></div><?php endforeach; ?><small><?= e($entry['files_verification']) ?></small></details></td><td><code><?= e($entry['commit']) ?></code></td><td><?= e($entry['acceptance']) ?><br><?= e($entry['verification']) ?><details><summary>Tests, evidence and limitations</summary><pre><?= e(json_encode($entry['tests'], JSON_PRETTY_PRINT)) ?></pre><p>Verified receipt: <code><?= e($entry['evidence_ref']) ?></code></p><?php foreach ($entry['limitations'] as $limit): ?><p><?= e($limit) ?></p><?php endforeach; ?></details></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if ($tab === 'roadmap'): ?>
<h3 class="h5">Complete authorized controller roadmap</h3><p>Committed requirements are shown below. No delivery dates or effort estimates have been invented. Eligible work remains subject to its authority and acceptance contract.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Phase / operation</th><th>State</th><th>Dependencies</th><th>Acceptance and next action</th></tr></thead><tbody><?php foreach ($report['roadmap'] as $operation): ?><tr><td><?= e($operation['phase']) ?><br><?= e($operation['id']) ?></td><td><?= e($operation['state']) ?><br><?= e($operation['requirement_status']) ?></td><td><?= e(implode(', ', $operation['dependencies'])) ?></td><td><?php foreach ($operation['acceptance_criteria'] as $criterion): ?><p><?= e($criterion) ?></p><?php endforeach; ?><?php foreach ($operation['required_evidence'] as $proof): ?><small><?= e($proof) ?></small><?php endforeach; ?><p><?= $operation['eligible'] ? 'NEXT ELIGIBLE AUTHORIZED WORK' : 'Inspect dependencies and acceptance before starting.' ?></p></td></tr><?php endforeach; ?></tbody></table></div>
<h3 class="h5">Digital Empire workstreams</h3><ul><?php foreach ($report['workstreams'] as $stream): ?><li><?= e($stream['name']) ?> — <?= e($stream['state']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (in_array($tab, ['overview','gates','roadmap'], true)): ?>
<h3 class="h5">LockGate dependency board</h3><p>These are committed initial-scope gate definitions. Requirements import alone never unlocks a gate.</p>
<p>Workflow domain: <strong><?= e($report['domain']['status']) ?></strong><?php if (isset($report['domain']['captured_at'])): ?> · Captured <?= e($report['domain']['captured_at']) ?> · Decision revision <?= e((string) $report['domain']['revision']) ?><?php else: ?>. Gate states below are roadmap declarations; live database enrollment is not established.<?php endif; ?></p>
<?php if ($tab === 'overview'): ?><p><a href="<?= e($projectUrl('gates')) ?>">Inspect all <?= e((string) count($report['gates'])) ?> gates and their exact criteria</a></p><?php endif; ?>
<?php foreach (($tab === 'overview' ? array_slice($report['gates'], 0, 3) : $report['gates']) as $gate): ?><article class="card" data-domain-captured="<?= e($report['domain']['captured_at'] ?? '') ?>" data-domain-heartbeat="<?= e($gate['domain']['heartbeat_at'] ?? '') ?>"><div class="card-header"><strong><?= e($gate['id']) ?> — <?= e($gate['name']) ?></strong><span class="badge bg-secondary float-end"><?= e($gate['state']) ?></span></div><div class="card-body"><p><?= e($gate['business_objective']) ?></p><p>Priority <?= e((string) $gate['priority']) ?> · Role: <?= e($gate['responsible_role']) ?> · Prerequisites: <?= e(implode(', ', $gate['dependencies']) ?: 'None') ?></p><details><summary>Exact unmet criteria and required proof</summary><ul><?php foreach ($gate['criteria'] as $criterion): ?><li><?= e($criterion['description']) ?> — <?= e($criterion['dimension']) ?> evidence required (<code><?= e($criterion['id']) ?></code>)</li><?php endforeach; ?></ul><p><?= e($gate['technical_objective']) ?></p></details><?php if (isset($gate['domain'])): ?><p>Workflow source <code><?= e($gate['domain']['source_hash']) ?></code> · Environment <?= e($gate['domain']['environment']) ?> · Gate revision <?= e((string) $gate['domain']['revision']) ?> · Stored decision <?= e($gate['domain']['recorded_state']) ?></p><ul><?php foreach ($gate['domain']['unmet'] as $unmet): ?><li><?= e($unmet) ?></li><?php endforeach; ?></ul><?php else: ?><p>Why advancement is blocked: independent acceptance evidence has not been imported and verified for this gate.</p><?php endif; ?><?php if ($gate['owner_approval']): ?><p>Explicit independent owner approval is also required. This view grants no authorization.</p><?php endif; ?><?php if (isset($gate['review_url'])): ?><p><a data-owner-review href="<?= e($gate['review_url']) ?>" target="_blank" rel="noopener noreferrer">Review this decision in Software Ops</a> · Independent owner login required.</p><?php endif; ?><p>Recovery: <?= e($gate['recovery']) ?><br>After opening: <?= e($gate['next']) ?></p></div></article><?php endforeach; ?>
<?php endif; ?>
<?php if (in_array($tab, ['reports','gates'], true)): ?>
<h3 class="h5">Versioned Definition of Done</h3><p>Version <?= e((string) $report['definition_of_done']['version']) ?>. New proposals do not silently change the initial scope.</p><?php foreach (['minimum_viable_commercial_release'=>'Minimum viable commercial release','production_ready_release'=>'Production-ready release','initial_scope'=>'Fully accepted initial scope'] as $key=>$title): ?><details><summary><?= e($title) ?></summary><ul><?php foreach ($report['definition_of_done'][$key] as $criterion): ?><li><?= e($criterion) ?></li><?php endforeach; ?></ul></details><?php endforeach; ?>
<?php endif; ?>
<?php if ($tab === 'evidence'): ?>
<h3 class="h5">Criterion evidence inspection</h3>
<p>Workflow data: <strong><?= e($report['domain']['status']) ?></strong>. These assessments were checked in Software Ops at the captured time. References identify evidence records; artifact contents and owner approvals stay in Software Ops.</p>
<?php if (!isset($report['domain']['captured_at'])): ?><div class="alert alert-warning">Workflow evidence is not available. An authorized administrator must verify project enrollment and the workflow snapshot before evidence can be inspected.</div><?php else: ?>
<p>Captured <?= e($report['domain']['captured_at']) ?> · Decision revision <?= e((string) $report['domain']['revision']) ?>. Capture-time passes do not prove current acceptance or release readiness.</p>
<?php foreach ($report['gates'] as $gate): ?><article class="card" data-domain-captured="<?= e($report['domain']['captured_at']) ?>" data-domain-heartbeat="<?= e($gate['domain']['heartbeat_at'] ?? '') ?>"><div class="card-header"><strong><?= e($gate['id']) ?> — <?= e($gate['name']) ?></strong><span class="badge bg-secondary float-end"><?= e($gate['state']) ?></span></div><div class="card-body"><p>Source <code><?= e($gate['domain']['source_hash']) ?></code> · Environment <?= e($gate['domain']['environment']) ?> · Gate revision <?= e((string) $gate['domain']['revision']) ?></p><div class="table-responsive"><table class="table"><thead><tr><th>Criterion / proof</th><th>Assessment at capture</th><th>Evidence reference</th></tr></thead><tbody><?php foreach ($gate['criteria'] as $criterion): $proof = $gate['domain']['evidence'][$criterion['id']]; ?><tr><td><code><?= e($criterion['id']) ?></code><br><?= e($criterion['description']) ?><br><?= e($criterion['dimension']) ?></td><td><?= e($proof['status']) ?></td><td><?= e($proof['evidence_id'] ?? 'No matching evidence record') ?></td></tr><?php endforeach; ?></tbody></table></div></div></article><?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($tab === 'reports'): ?>
<h3 class="h5 mt-3">Sanitized project exports</h3><a class="btn btn-outline-primary" href="<?= e(url('development-command-center/export.json') . '?project=' . rawurlencode($selectedProject)) ?>">Download JSON</a> <a class="btn btn-outline-primary" href="<?= e(url('development-command-center/export.md') . '?project=' . rawurlencode($selectedProject)) ?>">Download Markdown</a>
<?php endif; ?>
<?php endif; ?>
</section>
<?php if (empty($partial)): ?>
<p id="development-poll-status" class="text-muted" role="status">Snapshot as of the displayed time. Refreshing at most once every 30 seconds.</p>
<script>
(() => {
let attempts = 0;
const status = document.getElementById('development-poll-status');
async function refresh() {
    if (attempts >= 120) { status.textContent = 'Polling paused after one hour; reload to resume.'; return; }
    if (document.hidden || document.activeElement?.matches('input,select,textarea')) { setTimeout(refresh, 30000); return; }
    attempts++;
    const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 8000);
    try {
        const address = new URL(location.href); address.searchParams.set('partial', '1');
        const response = await fetch(address, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error('Unavailable');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const content = doc.getElementById('development-content');
        if (!content) throw new Error('Invalid response');
        document.getElementById('development-content').replaceWith(document.importNode(content, true));
        expireClaims();
        status.textContent = 'Status refreshed at ' + new Date().toISOString() + '. Check the source freshness shown above.';
    } catch (_) { status.textContent = 'Refresh unavailable; displayed data is cached as of its shown timestamp.'; }
    finally { clearTimeout(timeout); }
    setTimeout(refresh, 30000);
}
function expireClaims() {
    document.querySelectorAll('[data-agent-status]').forEach(label => {
        const age = Date.now() - Date.parse(label.dataset.heartbeat);
        if (!Number.isFinite(age) || age < 0 || age > 60000) label.textContent = 'NOT_CONFIRMED_ACTIVE';
    });
    document.querySelectorAll('[data-domain-captured]').forEach(card => {
        if (!card.dataset.domainCaptured) return;
        const badge = card.querySelector('.badge');
        const captureAge = Date.now() - Date.parse(card.dataset.domainCaptured);
        const heartbeatAge = Date.now() - Date.parse(card.dataset.domainHeartbeat);
        if (!Number.isFinite(captureAge) || captureAge < 0 || captureAge > 60000 ||
            (badge.textContent === 'ACTIVE' && (!Number.isFinite(heartbeatAge) || heartbeatAge < 0 || heartbeatAge > 60000))) {
            badge.textContent = 'BLOCKED';
            badge.title = 'Cached workflow state expired; current acceptance must be rechecked.';
            card.querySelectorAll('[data-owner-review]').forEach(link => { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); });
        }
    });
}
setInterval(expireClaims, 5000);
document.addEventListener('visibilitychange', expireClaims);
expireClaims();
setTimeout(refresh, 30000);
})();
</script>
<?php endif; ?>

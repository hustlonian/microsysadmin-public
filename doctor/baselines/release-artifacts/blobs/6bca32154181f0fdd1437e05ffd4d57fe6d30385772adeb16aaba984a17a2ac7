<?php
$registry = (array) ($doctor['registry'] ?? []);
$modules = (array) ($doctor['modules'] ?? []);
$inventory = (array) ($doctor['inventory'] ?? []);
$latestRecord = (array) ($doctor['latest'] ?? []);
$latest = (array) ($latestRecord['result_json'] ?? []);
$health = (array) ($latest['after_health_score'] ?? $latest['before_health_score'] ?? []);
$checks = (array) (($latest['after_checks'] ?? []) ?: ($latest['checks'] ?? []));
$diagnoses = (array) ($latest['diagnoses'] ?? []);
$plans = (array) ($latest['plans'] ?? []);
$plansByRepair = [];
foreach ($plans as $planEntry) {
    $planRepairId = strtoupper((string) ($planEntry['repair_id'] ?? ''));
    if ($planRepairId !== '') {
        $plansByRepair[$planRepairId] = (array) $planEntry;
    }
}
$repairs = (array) ($latest['repairs'] ?? []);
$repairSummary = (array) ($latest['repair_summary'] ?? []);
$repairNotice = trim((string) ($latest['notice'] ?? ''));
if (($latest['workflow'] ?? '') === 'fix_all'
    && ((int) ($repairSummary['failed'] ?? 0)) === 0
    && ((int) ($repairSummary['unresolved_failures'] ?? 0)) === 0) {
    $repairNotice = sprintf(
        'Fix everything completed: %d repairs were verified. %d advisory findings remain and 0 failures remain.',
        (int) ($repairSummary['fixed'] ?? 0),
        (int) ($repairSummary['manual'] ?? 0),
    );
}
$audit = (array) ($latestRecord['audit_trail'] ?? $latest['audit_trail'] ?? []);
$reports = (array) ($latest['reports'] ?? []);
$canScan = \SysAdmin\Auth::can('doctor.scan');
$canExecute = \SysAdmin\Auth::can('doctor.execute');
$canApprove = \SysAdmin\Auth::can('doctor.approve');
$canRollback = \SysAdmin\Auth::can('doctor.rollback');
$scoreClass = ((float) ($health['percentage'] ?? 0)) >= 90 ? 'success' : (((float) ($health['percentage'] ?? 0)) >= 70 ? 'warning' : 'danger');
?>
<div class="content-header doctor-heading">
    <div class="container-fluid">
        <div>
            <span class="doctor-eyebrow">AUTOMATED DIAGNOSTICS &amp; REPAIR LIBRARY</span>
            <h1 class="m-0">MicroSysAdmin Doctor</h1>
            <p>Evidence-first checks, cause-specific plans, policy-controlled repair, independent verification, and recovery.</p>
        </div>
        <div class="doctor-library-count"><strong><?= e((string) ($registry['repairs'] ?? 0)) ?></strong><span>permanent repair records</span></div>
    </div>
</div>

<section class="content doctor-page">
    <div class="container-fluid">
        <div class="doctor-stat-grid">
            <?php foreach ([
                ['Modules', $registry['modules'] ?? 0, 'fa-cubes'],
                ['Checks', $registry['checks'] ?? 0, 'fa-stethoscope'],
                ['Diagnoses', $registry['diagnoses'] ?? 0, 'fa-magnifying-glass-chart'],
                ['Repairs', $registry['repairs'] ?? 0, 'fa-screwdriver-wrench'],
                ['Health', isset($health['percentage']) ? $health['percentage'] . '%' : 'Not scanned', 'fa-heart-pulse'],
            ] as [$label, $value, $icon]): ?>
                <div class="doctor-stat"><i class="fas <?= e($icon) ?>"></i><div><strong><?= e((string) $value) ?></strong><span><?= e($label) ?></span></div></div>
            <?php endforeach; ?>
        </div>

        <?php if ($canScan): ?>
            <div class="card doctor-command-card">
                <div class="card-body">
                    <form class="js-api-form doctor-command-form" data-endpoint="<?= e(url('api/doctor.php')) ?>" data-output="#doctorOutput" data-reload-on-success="1">
                        <?= \SysAdmin\Csrf::field() ?>
                        <div class="doctor-command-field doctor-path-field">
                            <label for="doctorProjectPath">Project path</label>
                            <input id="doctorProjectPath" class="form-control" name="project_path" value="<?= e((string) ($latestRecord['project_path'] ?? APP_ROOT)) ?>" autocomplete="off">
                        </div>
                        <div class="doctor-command-field">
                            <label for="doctorModule">Module</label>
                            <select id="doctorModule" class="form-control" name="module">
                                <option value="">All enabled modules</option>
                                <?php foreach ($modules as $module): ?><option value="<?= e($module['name']) ?>"><?= e($module['display_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="doctor-command-field">
                            <label for="doctorCheck">One check (optional)</label>
                            <input id="doctorCheck" class="form-control" name="check_ids[]" placeholder="LARAVEL-C0004" pattern="[A-Z]+-C[0-9]{4}">
                        </div>
                        <div class="doctor-command-actions">
                            <button class="btn btn-primary" name="action" value="scan"><i class="fas fa-play"></i> Run checks</button>
                            <button class="btn btn-outline-primary" name="action" value="diagnose"><i class="fas fa-magnifying-glass"></i> Diagnose</button>
                            <button class="btn btn-outline-secondary" name="action" value="plan"><i class="fas fa-list-check"></i> Build plans</button>
                            <?php if (\SysAdmin\Auth::can('doctor.sensitive')): ?><a class="btn btn-outline-secondary" href="<?= e(url('api/doctor.php?action=discover&project_path=' . rawurlencode((string) ($latestRecord['project_path'] ?? APP_ROOT)))) ?>"><i class="fas fa-server"></i> Environment discovery</a><?php endif; ?>
                            <?php if ($canExecute): ?>
                                <?php if ($canApprove): ?>
                                    <button class="btn btn-success doctor-fix-all" name="action" value="fix-all" data-submitting-label="Fixing everything" data-confirm-title="Fix everything Doctor can repair?" data-confirm-text="Doctor will run a fresh full scan, diagnose every finding, authorize all eligible non-destructive repairs, execute them through scoped controls, verify every result, and report anything that still requires manual work." data-confirm-button="Fix everything" data-confirm-icon="warning"><i class="fas fa-wand-magic-sparkles"></i> Fix everything</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($canExecute && $canApprove): ?><p class="doctor-fix-all-help"><i class="fas fa-shield-halved"></i> One action handles scan, diagnosis, approval, repair, and verification. Checks that do not apply to the detected stack are excluded from the active execution set.</p><?php endif; ?>
                    </form>
                    <pre id="doctorOutput" class="sys-output mt-3" hidden></pre>
                </div>
            </div>
        <?php endif; ?>

        <?php if (($latest['mode'] ?? '') === 'repair' && $repairSummary): ?>
            <div class="doctor-fix-summary <?= ((int) ($repairSummary['failed'] ?? 0)) > 0 || ((int) ($repairSummary['unresolved_failures'] ?? 0)) > 0 || ((int) ($repairSummary['skipped'] ?? 0)) > 0 ? 'doctor-fix-summary-warning' : 'doctor-fix-summary-success' ?>">
                <div>
                    <span class="doctor-eyebrow"><?= ($latest['workflow'] ?? '') === 'fix_all' ? 'FIX EVERYTHING RESULT' : 'REPAIR RESULT' ?></span>
                    <strong><?= e($repairNotice !== '' ? $repairNotice : 'Repair workflow completed.') ?></strong>
                </div>
                <dl>
                    <div><dt>Eligible</dt><dd><?= e((string) ($repairSummary['eligible'] ?? 0)) ?></dd></div>
                    <div><dt>Attempted</dt><dd><?= e((string) ($repairSummary['attempted'] ?? 0)) ?></dd></div>
                    <div><dt>Verified</dt><dd><?= e((string) ($repairSummary['fixed'] ?? 0)) ?></dd></div>
                    <div><dt>Failed</dt><dd><?= e((string) ($repairSummary['failed'] ?? 0)) ?></dd></div>
                    <div><dt>Unresolved</dt><dd><?= e((string) ($repairSummary['unresolved_failures'] ?? 0)) ?></dd></div>
                    <div><dt>Skipped</dt><dd><?= e((string) ($repairSummary['skipped'] ?? 0)) ?></dd></div>
                    <div><dt><?= ((int) ($repairSummary['unresolved_failures'] ?? 0)) > 0 ? 'Manual' : 'Advisories' ?></dt><dd><?= e((string) ($repairSummary['manual'] ?? 0)) ?></dd></div>
                </dl>
            </div>
        <?php endif; ?>

        <?php if ($latestRecord): ?>
            <div class="row">
                <div class="col-xl-4">
                    <div class="card doctor-health-card border-<?= e($scoreClass) ?>">
                        <div class="card-body">
                            <div class="doctor-score doctor-score-<?= e($scoreClass) ?>"><strong><?= e((string) ($health['percentage'] ?? '—')) ?><?= isset($health['percentage']) ? '%' : '' ?></strong><span><?= e((string) ($health['grade'] ?? 'Not scored')) ?></span></div>
                            <div><span class="doctor-eyebrow">LATEST EXECUTION</span><h2><?= e((string) ($latestRecord['execution_id'] ?? '')) ?></h2><p><?= e((string) ($latestRecord['status'] ?? '')) ?> · <?= e((string) ($latestRecord['started_at'] ?? '')) ?></p></div>
                        </div>
                        <div class="doctor-health-counts">
                            <?php foreach (['PASS', 'WARNING', 'FAIL', 'ERROR', 'UNKNOWN'] as $status): ?><span><strong><?= e((string) (($health['counts'][$status] ?? 0))) ?></strong><?= e($status) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-8">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Module health</h2></div>
                        <div class="card-body doctor-module-health">
                            <?php foreach ((array) ($health['modules'] ?? []) as $module => $score): ?>
                                <div><span><?= e(ucfirst((string) $module)) ?></span><div class="progress"><div class="progress-bar" style="width: <?= e((string) max(0, min(100, (float) ($score['percentage'] ?? 0)))) ?>%"></div></div><strong><?= e((string) ($score['percentage'] ?? 0)) ?>%</strong></div>
                            <?php endforeach; ?>
                            <?php if (empty($health['modules'])): ?><p class="text-muted mb-0">Run a Doctor scan to calculate module health.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($plans): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Previewed repair plans</h2><span class="badge bg-secondary ms-2"><?= count($plans) ?></span></div>
                <div class="card-body">
                    <div class="doctor-plan-grid">
                        <?php foreach ($plans as $entry): $meta = (array) ($entry['metadata'] ?? []); $plan = (array) ($entry['plan'] ?? []); $policy = (array) ($entry['policy'] ?? []); ?>
                            <article class="doctor-plan">
                                <div class="doctor-plan-top"><code><?= e((string) ($entry['repair_id'] ?? '')) ?></code><span class="badge bg-<?= !empty($policy['allowed']) ? 'primary' : 'secondary' ?>"><?= e((string) ($meta['automation_class'] ?? '')) ?></span></div>
                                <h3><?= e((string) ($meta['name'] ?? 'Repair plan')) ?></h3>
                                <p><strong>Cause:</strong> <?= e((string) ($plan['cause'] ?? '')) ?></p>
                                <ol><?php foreach ((array) ($plan['actions'] ?? []) as $action): ?><li><?= e((string) ($action['description'] ?? $action['type'] ?? 'Planned action')) ?></li><?php endforeach; ?></ol>
                                <?php if (empty($plan['actions'])): ?><p class="text-muted">Report-only: no mutation will be executed.</p><?php endif; ?>
                                <details><summary>Evidence, affected paths, and policy</summary><pre><?= e(json_encode(['evidence' => $plan['evidence'] ?? [], 'affected_paths' => $plan['affected_paths'] ?? [], 'backup' => $plan['requires_backup'] ?? false, 'rollback' => $plan['rollback_supported'] ?? false, 'policy' => $policy], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canExecute && $canApprove): ?><p class="doctor-plan-guidance"><i class="fas fa-circle-info"></i> Use <strong>Fix everything</strong> above for the complete workflow, or use a cause card’s fix button for one issue.</p><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($diagnoses): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Detected causes</h2><span class="badge bg-warning ms-2"><?= count($diagnoses) ?></span></div>
                <div class="card-body doctor-diagnosis-grid">
                    <?php foreach ($diagnoses as $diagnosis):
                        $diagnosisRepairIds = array_values(array_filter(array_map(static fn (mixed $id): string => strtoupper((string) $id), (array) ($diagnosis['related_repair_ids'] ?? []))));
                    ?>
                        <article>
                            <code><?= e((string) ($diagnosis['diagnosis_id'] ?? '')) ?></code>
                            <strong><?= e((string) ($diagnosis['probable_cause'] ?? '')) ?></strong>
                            <p>Confidence <?= e((string) round(((float) ($diagnosis['confidence'] ?? 0)) * 100)) ?>% · <?= e((string) ($diagnosis['automation_reason'] ?? '')) ?></p>
                            <details><summary>Evidence and expected state</summary><pre><?= e(json_encode(['condition' => $diagnosis['condition'] ?? null, 'expected' => $diagnosis['expected_state'] ?? null, 'actual' => $diagnosis['actual_state'] ?? null, 'evidence' => $diagnosis['evidence'] ?? [], 'repairs' => $diagnosisRepairIds], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
                            <div class="doctor-diagnosis-actions">
                                <?php $actionableFix = false; foreach ($diagnosisRepairIds as $diagnosisRepairId):
                                    $fixPlan = (array) ($plansByRepair[$diagnosisRepairId] ?? []);
                                    $fixPolicy = (array) ($fixPlan['policy'] ?? []);
                                    if (!$canExecute || !$canApprove || empty($fixPolicy['allowed']) || empty($fixPlan['plan_hash'])) {
                                        continue;
                                    }
                                    $actionableFix = true;
                                ?>
                                    <form class="js-api-form" data-endpoint="<?= e(url('api/doctor.php')) ?>" data-output="#doctorOutput" data-reload-on-success="1" data-submitting-label="Fixing">
                                        <?= \SysAdmin\Csrf::field() ?>
                                        <input type="hidden" name="action" value="fix">
                                        <input type="hidden" name="execution_id" value="<?= e((string) ($latestRecord['execution_id'] ?? '')) ?>">
                                        <input type="hidden" name="repair_id" value="<?= e($diagnosisRepairId) ?>">
                                        <input type="hidden" name="plan_hash" value="<?= e((string) $fixPlan['plan_hash']) ?>">
                                        <button class="btn btn-sm btn-success" data-confirm-title="Fix <?= e($diagnosisRepairId) ?>?" data-confirm-text="This approves and executes only the exact displayed plan. Doctor will back up required state, verify the result independently, and roll back supported repairs if verification fails." data-confirm-button="Fix this issue" data-confirm-icon="warning"><i class="fas fa-screwdriver-wrench"></i> Fix this issue</button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if (!$actionableFix): ?><span class="doctor-manual-only"><i class="fas fa-circle-info"></i> <?= ((int) ($repairSummary['unresolved_failures'] ?? 0)) > 0 ? 'Manual repair or a fresh Diagnose plan is required.' : 'Advisory only; no automatic change is required.' ?></span><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($repairs): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Repair and verification results</h2></div>
                <div class="card-body doctor-repair-results">
                    <?php foreach ($repairs as $repair): ?><article><span class="doctor-status doctor-status-<?= e(strtolower((string) ($repair['status'] ?? 'unknown'))) ?>"><?= e((string) ($repair['status'] ?? 'UNKNOWN')) ?></span><code><?= e((string) ($repair['repair_id'] ?? '')) ?></code><strong><?= e((string) ($repair['message'] ?? '')) ?></strong><details><summary>Changes, backup, verification, and rollback</summary><pre><?= e(json_encode($repair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details></article><?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($checks || $diagnoses || $repairs): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Execution evidence</h2></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover mb-0 doctor-result-table">
                        <thead><tr><th>Status</th><th>ID</th><th>Module</th><th>Finding</th><th>Evidence</th></tr></thead>
                        <tbody>
                        <?php foreach ($checks as $check): $meta = (array) ($check['metadata'] ?? []); ?>
                            <tr><td><span class="doctor-status doctor-status-<?= e(strtolower((string) ($check['status'] ?? 'unknown'))) ?>"><?= e((string) ($check['status'] ?? 'UNKNOWN')) ?></span></td><td><code><?= e((string) ($meta['check_id'] ?? '')) ?></code></td><td><?= e((string) ($meta['module'] ?? '')) ?></td><td><?= e((string) ($check['message'] ?? '')) ?></td><td><details><summary>View</summary><pre><?= e(json_encode($check['evidence'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Repair library</h2><span class="badge bg-primary ms-2"><?= count($inventory) ?></span></div>
            <div class="card-body">
                <div class="doctor-library-filters">
                    <?php foreach ([1 => ['Module', array_column($modules, 'name')], 3 => ['Severity', ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']], 4 => ['Risk', ['LOW', 'MEDIUM', 'HIGH', 'TO_BE_CLASSIFIED']], 5 => ['Automation', ['AUTO_SAFE', 'AUTO_WITH_BACKUP', 'APPROVAL_REQUIRED', 'REPORT_ONLY']], 6 => ['Status', ['IMPLEMENTED', 'REPORT_ONLY', 'RESEARCHED']]] as $column => [$label, $values]): ?>
                        <label><?= e($label) ?><select class="form-control form-control-sm" data-doctor-library-filter="<?= e((string) $column) ?>"><option value="">All</option><?php foreach ($values as $value): ?><option value="<?= e((string) $value) ?>"><?= e((string) $value) ?></option><?php endforeach; ?></select></label>
                    <?php endforeach; ?>
                </div>
                <div class="table-responsive">
                <table id="doctorInventoryTable" class="table table-sm table-hover data-table doctor-inventory-table">
                    <thead><tr><th>ID</th><th>Module</th><th>Repair / finding</th><th>Severity</th><th>Risk</th><th>Automation</th><th>Status</th></tr></thead>
                    <tbody><?php foreach ($inventory as $entry): ?><tr><td><?php if (in_array((string) ($entry['implementation_status'] ?? ''), ['IMPLEMENTED', 'REPORT_ONLY', 'TESTED', 'RELEASED'], true)): ?><a href="<?= e(url('api/doctor.php?action=repair&id=' . rawurlencode((string) $entry['repair_id']))) ?>"><code><?= e((string) $entry['repair_id']) ?></code></a><?php else: ?><code><?= e((string) $entry['repair_id']) ?></code><?php endif; ?></td><td><?= e((string) ($entry['module'] ?? '')) ?></td><td><?= e((string) ($entry['title'] ?? '')) ?></td><td><?= e((string) ($entry['severity'] ?? '')) ?></td><td><?= e((string) ($entry['risk'] ?? '')) ?></td><td><?= e((string) ($entry['automation_class'] ?? '')) ?></td><td><?= e((string) ($entry['implementation_status'] ?? '')) ?></td></tr><?php endforeach; ?></tbody>
                </table>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-6"><div class="card"><div class="card-header"><h2 class="card-title">Reports</h2></div><div class="card-body"><?php foreach ($reports as $report): ?><a class="btn btn-sm btn-outline-primary me-2 mb-2" href="<?= e(url('api/doctor.php?action=download-report&execution_id=' . rawurlencode((string) ($latestRecord['execution_id'] ?? '')) . '&format=' . rawurlencode((string) ($report['format'] ?? '')))) ?>"><i class="fas fa-download"></i> <?= e(strtoupper((string) ($report['format'] ?? 'report'))) ?></a><?php endforeach; ?><?php if (!$reports): ?><p class="text-muted mb-0">Reports are created after every execution.</p><?php endif; ?></div></div></div>
            <div class="col-xl-6"><div class="card"><div class="card-header"><h2 class="card-title">Sanitized audit trail</h2></div><div class="card-body doctor-audit"><?php foreach (array_slice($audit, -30) as $event): ?><div><time><?= e((string) ($event['created_at'] ?? '')) ?></time><span class="badge bg-secondary"><?= e((string) ($event['level'] ?? 'INFO')) ?></span><strong><?= e((string) ($event['event_type'] ?? '')) ?></strong><p><?= e((string) ($event['message'] ?? '')) ?></p></div><?php endforeach; ?><?php if (!$audit): ?><p class="text-muted mb-0">No Doctor audit events yet.</p><?php endif; ?></div></div></div>
        </div>

        <?php if ($canRollback && $repairs): ?>
            <form class="js-api-form mb-4" data-endpoint="<?= e(url('api/doctor.php')) ?>" data-output="#doctorOutput" data-reload-on-success="1">
                <?= \SysAdmin\Csrf::field() ?><input type="hidden" name="action" value="rollback"><input type="hidden" name="execution_id" value="<?= e((string) $latestRecord['execution_id']) ?>">
                <?php foreach ($repairs as $repair): if (!empty($repair['backup'])): ?><input type="hidden" name="repair_ids[]" value="<?= e((string) ($repair['repair_id'] ?? '')) ?>"><?php endif; endforeach; ?>
                <button class="btn btn-outline-danger" data-confirm-title="Roll back supported repairs?" data-confirm-text="Doctor will restore only captured state for repairs that declare rollback support."><i class="fas fa-rotate-left"></i> Roll back supported changes</button>
            </form>
        <?php endif; ?>
    </div>
</section>

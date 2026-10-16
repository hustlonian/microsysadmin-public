<div class="content-header">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <h1 class="m-0"><?= e($title) ?></h1>
        <span class="badge bg-secondary">Organization: <?= e($organization['name'] ?? 'Local Organization') ?></span>
    </div>
</div>
<section class="content">
    <div class="container-fluid">
        <?php if ($mode === 'machine-info'): ?>
            <div class="row">
                <div class="col-xl-7">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Persistent machine identity</h2></div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <?php foreach ([
                                    'Machine UUID' => $machine['machine_uuid'],
                                    'Installation UUID' => $machine['installation_uuid'],
                                    'Display name' => $machine['display_name'],
                                    'Hostname' => $machine['hostname'],
                                    'Operating system' => $machine['operating_system'],
                                    'Architecture' => $machine['architecture'],
                                    'MicroSysAdmin version' => $machine['microsysadmin_version'],
                                    'PHP version' => $machine['php_version'],
                                    'XAMPP/LAMPP root' => $machine['xampp_root'],
                                    'Installed' => $machine['installed_at'],
                                    'Last update' => $machine['last_update_at'],
                                    'License' => $machine['license_status'],
                                    'Hub connection' => $machine['connection_status'],
                                ] as $label => $value): ?>
                                    <dt class="col-sm-4"><?= e($label) ?></dt><dd class="col-sm-8"><code><?= e($value) ?></code></dd>
                                <?php endforeach; ?>
                                <dt class="col-sm-4">Document roots</dt><dd class="col-sm-8"><code><?= e(implode(', ', $machine['document_roots'])) ?></code></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Machine settings</h2></div>
                        <div class="card-body">
                            <form class="js-api-form mb-4" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="machine-name">
                                <label class="form-label">Display name</label>
                                <input class="form-control mb-2" name="display_name" value="<?= e($machine['display_name']) ?>" required>
                                <button class="btn btn-primary">Save name</button>
                            </form>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-confirm-title="Rotate agent credential?" data-confirm-text="The old signed-agent credential will be revoked immediately." data-confirm-button="Rotate">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="agent-rotate">
                                <button class="btn btn-outline-warning">Rotate local-agent credential</button>
                            </form>
                            <p class="small text-muted mt-2">The new secret is returned once. Store it securely.</p>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Health summary</h2></div>
                        <div class="card-body">
                            <span class="badge bg-<?= $health['ok'] ? 'success' : 'warning' ?> mb-2"><?= e($health['status']) ?></span>
                            <?php foreach ($health['checks'] as $check): ?>
                                <div class="small d-flex justify-content-between"><span><?= e($check['name']) ?></span><span><?= e($check['status']) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($mode === 'licensing'): ?>
            <div class="row">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">License status</h2></div>
                        <div class="card-body">
                            <h3><?= e($license['edition']) ?></h3>
                            <p><span class="badge bg-<?= $license['available'] ? 'success' : 'warning' ?>"><?= e($license['status']) ?></span></p>
                            <p class="small">Source: <?= e($license['source']) ?><br>Updates until: <?= e($license['updates_until'] ?? 'edition policy') ?></p>
                            <div class="alert alert-info small mb-0">Essential local access remains available if a licensing service is unreachable. There is no remote kill switch.</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Effective entitlements</h2></div>
                        <div class="card-body table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Entitlement</th><th>Value</th></tr></thead>
                                <tbody><?php foreach ($license['entitlements'] as $key => $value): ?><tr><td><code><?= e($key) ?></code></td><td><?= e(is_bool($value) ? ($value ? 'enabled' : 'disabled') : $value) ?></td></tr><?php endforeach; ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($mode === 'updates'): ?>
            <div class="card">
                <div class="card-header d-flex align-items-center"><h2 class="card-title mb-0">Update channel</h2><span class="ms-auto badge bg-secondary"><?= e($updates['channel']) ?></span></div>
                <div class="card-body">
                    <p>Installed version: <strong><?= e($updates['current_version']) ?></strong> · Provider: <?= e($updates['provider']) ?></p>
                    <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput">
                        <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                        <input type="hidden" name="action" value="update-check">
                        <button class="btn btn-primary">Check signed release manifest</button>
                    </form>
                    <p class="small text-muted mt-2">Installation remains a CLI operation: <code>./update.sh apply MANIFEST_JSON PACKAGE_TAR_GZ</code>.</p>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Update and rollback history</h2></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm"><thead><tr><th>From</th><th>To</th><th>Status</th><th>Started</th><th>Completed</th></tr></thead>
                    <tbody><?php foreach ($updates['history'] as $entry): ?><tr><td><?= e($entry['from_version']) ?></td><td><?= e($entry['to_version']) ?></td><td><?= e($entry['status']) ?></td><td><?= e($entry['started_at']) ?></td><td><?= e($entry['completed_at']) ?></td></tr><?php endforeach; ?></tbody></table>
                </div>
            </div>
        <?php elseif ($mode === 'environment'): ?>
            <div class="row">
                <div class="col-xl-7">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Environment overrides</h2></div>
                        <div class="card-body">
                            <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="environment-save">
                                <?php foreach ([
                                    'xampp_root' => 'XAMPP/LAMPP root',
                                    'php_binary' => 'PHP binary',
                                    'composer_binary' => 'Composer binary',
                                    'mysql_binary' => 'MySQL binary',
                                    'mysqldump_binary' => 'mysqldump binary',
                                    'git_binary' => 'Git binary',
                                    'bash_binary' => 'Bash binary',
                                    'ffmpeg_binary' => 'FFmpeg binary',
                                    'storage_root' => 'Storage root (restart required)',
                                    'private_root' => 'Private state root',
                                    'trash_root' => 'Trash root',
                                    'backup_root' => 'Backup root',
                                    'script_root' => 'Script root',
                                    'temporary_workspace_root' => 'Workspace root',
                                    'privileged_helper_path' => 'Privileged helper',
                                ] as $key => $label): ?>
                                    <label class="form-label"><?= e($label) ?></label>
                                    <input class="form-control mb-2" name="<?= e($key) ?>" value="<?= e($environment[$key]) ?>">
                                <?php endforeach; ?>
                                <label class="form-label">Document roots, one per line</label>
                                <textarea class="form-control mb-2" name="document_roots" rows="3"><?= e(implode("\n", $environment['document_roots'])) ?></textarea>
                                <label class="form-label">Allowed filesystem roots, one per line</label>
                                <textarea class="form-control mb-2" name="allowed_filesystem_roots" rows="3"><?= e(implode("\n", $environment['allowed_filesystem_roots'])) ?></textarea>
                                <div class="row">
                                    <div class="col-md-8"><label class="form-label">MySQL host</label><input class="form-control mb-2" name="mysql_host" value="<?= e($environment['mysql_host']) ?>"></div>
                                    <div class="col-md-4"><label class="form-label">MySQL port</label><input class="form-control mb-2" name="mysql_port" type="number" min="1" max="65535" value="<?= e($environment['mysql_port']) ?>"></div>
                                </div>
                                <label class="form-label">MySQL socket</label>
                                <input class="form-control mb-2" name="mysql_socket" value="<?= e($environment['mysql_socket']) ?>" placeholder="/path/to/mysql.sock">
                                <label class="form-label">Service-control driver</label>
                                <select class="form-select mb-2" name="service_control_driver">
                                    <?php foreach (['xampp', 'systemd', 'inspection'] as $driver): ?><option <?= $environment['service_control_driver'] === $driver ? 'selected' : '' ?>><?= e($driver) ?></option><?php endforeach; ?>
                                </select>
                                <input type="hidden" name="terminal_available_present" value="1">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="terminal_available" value="1" id="terminalAvailable" <?= !empty($environment['terminal_available']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="terminalAvailable">Host makes terminal capability available (profile and entitlement still apply)</label>
                                </div>
                                <button class="btn btn-primary">Save environment overrides</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Diagnostics</h2></div>
                        <div class="card-body">
                            <?php foreach ($environmentDiagnostics as $check): ?>
                                <div class="mb-2"><span class="badge bg-<?= $check['status'] === 'ok' ? 'success' : 'warning' ?>"><?= e($check['status']) ?></span> <strong><?= e($check['key']) ?></strong><br><code><?= e($check['message']) ?></code></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($mode === 'hub-connection'): ?>
            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Optional outbound hub connection</h2></div>
                        <div class="card-body">
                            <p>Status: <span class="badge bg-secondary"><?= e($hub['status']) ?></span></p>
                            <p class="small text-muted">The local machine initiates heartbeats. Source code, credentials, file contents, SQL, terminal output, and private keys are never included.</p>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="hub-enroll">
                                <label class="form-label">Hub URL</label><input class="form-control mb-2" name="url" type="url" value="<?= e($hub['hub_url'] ?? '') ?>" placeholder="https://hub.example.com" required>
                                <label class="form-label">Organization ID</label><input class="form-control mb-2" name="organization_id" required>
                                <label class="form-label">One-time enrollment token</label><input class="form-control mb-3" name="token" type="password" autocomplete="off" required>
                                <label class="form-label d-block">Approved heartbeat data</label>
                                <?php foreach ([
                                    'health' => 'Health summary',
                                    'services' => 'Service status',
                                    'metrics' => 'Metric summary',
                                    'backups' => 'Backup status',
                                    'updates' => 'Update status',
                                    'activity' => 'Last activity time',
                                ] as $category => $label): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="share[]" value="<?= e($category) ?>" id="share-<?= e($category) ?>" <?= !empty($hubSharing[$category]) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="share-<?= e($category) ?>"><?= e($label) ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <button class="btn btn-primary">Enroll machine</button>
                            </form>
                            <?php if (!empty($hub['configured'])): ?>
                                <form class="js-api-form mt-3" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1">
                                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="hub-sharing">
                                    <label class="form-label d-block">Change heartbeat data sharing</label>
                                    <?php foreach ([
                                        'health' => 'Health summary',
                                        'services' => 'Service status',
                                        'metrics' => 'Metric summary',
                                        'backups' => 'Backup status',
                                        'updates' => 'Update status',
                                        'activity' => 'Last activity time',
                                    ] as $category => $label): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="share[]" value="<?= e($category) ?>" id="sharing-<?= e($category) ?>" <?= !empty($hubSharing[$category]) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="sharing-<?= e($category) ?>"><?= e($label) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <button class="btn btn-outline-secondary btn-sm mt-2">Save sharing controls</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card"><div class="card-header"><h2 class="card-title">Connection actions</h2></div><div class="card-body d-flex flex-wrap gap-2">
                        <?php foreach (['hub-test' => 'Test', 'hub-heartbeat' => 'Send heartbeat', 'hub-rotate' => 'Rotate credential', 'hub-disconnect' => 'Disconnect'] as $action => $label): ?>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" <?= $action === 'hub-disconnect' ? 'data-confirm-title="Disconnect from hub?" data-confirm-button="Disconnect"' : '' ?>>
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="<?= e($action) ?>">
                                <button class="btn btn-outline-<?= $action === 'hub-disconnect' ? 'danger' : 'primary' ?>"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div></div>
                </div>
            </div>
        <?php elseif ($mode === 'jobs'): ?>
            <div class="card"><div class="card-header"><h2 class="card-title">Durable background jobs</h2></div><div class="card-body table-responsive">
                <table class="table table-sm data-table"><thead><tr><th>Type</th><th>Status</th><th>Progress</th><th>Attempts</th><th>Owner</th><th>Created</th><th></th></tr></thead><tbody>
                <?php foreach ($jobs as $job): ?><tr><td><code><?= e($job['type']) ?></code></td><td><?= e($job['status'] === 'needs_reconciliation' ? 'Review required' : $job['status']) ?><?php if ($job['status'] === 'needs_reconciliation'): ?><div class="small text-muted"><?= e($job['error_message']) ?></div><?php endif; ?></td><td><?= e($job['progress']) ?>%</td><td><?= e($job['attempts']) ?>/<?= e($job['max_attempts']) ?></td><td><?= e($job['owned_by']) ?></td><td><?= e($job['created_at']) ?></td><td>
                    <?php if ($job['status'] === 'queued' && (int) $job['attempts'] === 0): ?><form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1"><input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="job-cancel"><input type="hidden" name="uuid" value="<?= e($job['uuid']) ?>"><button class="btn btn-xs btn-outline-danger">Cancel</button></form><?php endif; ?>
                </td></tr><?php endforeach; ?></tbody></table>
            </div></div>
        <?php elseif ($mode === 'api-access'): ?>
            <div class="row"><div class="col-lg-5"><div class="card"><div class="card-header"><h2 class="card-title">Create scoped API token</h2></div><div class="card-body">
                <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput">
                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="api-token-create">
                    <label class="form-label">Name</label><input class="form-control mb-2" name="name" required>
                    <label class="form-label">Expiration</label><input class="form-control mb-2" name="expires_at" type="datetime-local">
                    <label class="form-label">Requests per minute</label><input class="form-control mb-2" name="rate_limit" type="number" min="1" max="1000" value="60">
                    <?php foreach (['machine.health:read','services.status:read','projects.inventory:read','backups.status:read','version:read','backups:write','health:run','audit.summary:read'] as $scope): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?= e($scope) ?>" id="scope-<?= e(md5($scope)) ?>"><label class="form-check-label" for="scope-<?= e(md5($scope)) ?>"><code><?= e($scope) ?></code></label></div>
                    <?php endforeach; ?>
                    <button class="btn btn-primary mt-3">Create token</button>
                </form><p class="small text-muted mt-2">The token is shown once. OpenAPI: <code>docs/openapi.yaml</code>.</p>
            </div></div></div><div class="col-lg-7"><div class="card"><div class="card-header"><h2 class="card-title">API tokens</h2></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Prefix</th><th>Expires</th><th>Last used</th><th></th></tr></thead><tbody>
                <?php foreach ($apiTokens as $token): ?><tr><td><?= e($token['name']) ?></td><td><code><?= e($token['token_prefix']) ?></code></td><td><?= e($token['expires_at']) ?></td><td><?= e($token['last_used_at']) ?></td><td><?php if (!$token['revoked_at']): ?><form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1"><input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="api-token-revoke"><input type="hidden" name="id" value="<?= e($token['id']) ?>"><button class="btn btn-xs btn-outline-danger">Revoke</button></form><?php else: ?>Revoked<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div></div>
        <?php elseif ($mode === 'webhooks'): ?>
            <div class="row"><div class="col-lg-5"><div class="card"><div class="card-header"><h2 class="card-title">Create webhook</h2></div><div class="card-body">
                <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1">
                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="webhook-create">
                    <label class="form-label">HTTPS endpoint</label><input class="form-control mb-2" name="url" type="url" required>
                    <?php foreach (['machine.health_changed','service.failed','backup.completed','backup.failed','update.available','update.completed','update.failed','disk.threshold_exceeded','login.lockout','site.provisioned','site.provisioning_failed','scheduled_task.failed','license.entitlement_changed'] as $event): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="event_types[]" value="<?= e($event) ?>" id="event-<?= e(md5($event)) ?>"><label class="form-check-label" for="event-<?= e(md5($event)) ?>"><code><?= e($event) ?></code></label></div>
                    <?php endforeach; ?><button class="btn btn-primary mt-3">Create disabled webhook</button>
                </form><p class="small text-muted mt-2">The signing secret is shown once. New webhooks are disabled until explicitly enabled.</p>
            </div></div></div><div class="col-lg-7"><div class="card"><div class="card-header"><h2 class="card-title">Webhook endpoints</h2></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>URL</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($webhooks as $webhook): ?><tr><td><code><?= e($webhook['url']) ?></code></td><td><?= !empty($webhook['enabled']) ? 'Enabled' : 'Disabled' ?></td><td class="d-flex gap-1">
                    <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1"><input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="id" value="<?= e($webhook['id']) ?>"><button class="btn btn-xs btn-outline-primary" name="action" value="<?= !empty($webhook['enabled']) ? 'webhook-disable' : 'webhook-enable' ?>"><?= !empty($webhook['enabled']) ? 'Disable' : 'Enable' ?></button></form>
                    <form class="js-api-form" data-endpoint="<?= e(url('api/administration.php')) ?>" data-output="#adminOutput" data-reload-on-success="1"><input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="id" value="<?= e($webhook['id']) ?>"><button class="btn btn-xs btn-outline-danger" name="action" value="webhook-delete">Delete</button></form>
                </td></tr><?php endforeach; ?>
            </tbody></table></div></div></div></div>
        <?php elseif ($mode === 'audit'): ?>
            <div class="card"><div class="card-header"><h2 class="card-title">Organization-scoped audit history</h2></div><div class="card-body table-responsive"><table class="table table-sm data-table"><thead><tr><th>Level</th><th>Action</th><th>User</th><th>IP</th><th>Request</th><th>Created</th></tr></thead><tbody>
                <?php foreach ($audit as $row): ?><tr><td><?= e($row['level']) ?></td><td><?= e($row['action']) ?></td><td><?= e($row['username']) ?></td><td><?= e($row['ip_address']) ?></td><td><code><?= e($row['request_id'] ?? '') ?></code></td><td><?= e($row['created_at']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        <?php elseif ($mode === 'security'): ?>
            <div class="card"><div class="card-header"><h2 class="card-title">Installation security profile</h2></div><div class="card-body">
                <h3><?= e(ucfirst($securityProfile)) ?></h3>
                <?php foreach ($securityCapabilities as $capability => $enabled): ?><span class="badge bg-<?= $enabled ? 'success' : 'secondary' ?> me-1"><?= e($capability) ?>: <?= $enabled ? 'on' : 'off' ?></span><?php endforeach; ?>
                <p class="mt-3">Change this profile and manage MFA from <a href="<?= e(url('settings')) ?>">Settings</a>.</p>
            </div></div>
        <?php endif; ?>

        <pre class="sys-output mt-3" id="adminOutput"></pre>
    </div>
</section>

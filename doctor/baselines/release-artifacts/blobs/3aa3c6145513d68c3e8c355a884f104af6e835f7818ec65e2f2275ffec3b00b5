<div class="content-header"><div class="container-fluid"><h1 class="m-0">Settings</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Preferences</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/settings.php')) ?>" data-output="#settingsOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Dark mode</label>
                            <select class="form-select mb-2" name="dark_mode">
                                <option value="0" <?= ($settings['dark_mode'] ?? '0') === '0' ? 'selected' : '' ?>>Off</option>
                                <option value="1" <?= ($settings['dark_mode'] ?? '0') === '1' ? 'selected' : '' ?>>On</option>
                            </select>
                            <label class="form-label">Theme</label>
                            <select class="form-select mb-2" name="theme">
                                <option value="default" <?= ($settings['theme'] ?? 'default') === 'default' ? 'selected' : '' ?>>Default</option>
                                <option value="blue" <?= ($settings['theme'] ?? '') === 'blue' ? 'selected' : '' ?>>Blue</option>
                                <option value="green" <?= ($settings['theme'] ?? '') === 'green' ? 'selected' : '' ?>>Green</option>
                            </select>
                            <label class="form-label">Timezone</label>
                            <input class="form-control mb-2" name="timezone" value="<?= e($settings['timezone'] ?? config_value('app.timezone')) ?>">
                            <label class="form-label">Dashboard refresh interval</label>
                            <input class="form-control mb-2" name="refresh_interval" type="number" min="2000" max="60000" value="<?= e($settings['refresh_interval'] ?? config_value('app.refresh_interval')) ?>">
                            <label class="form-label">Email notifications</label>
                            <select class="form-select mb-3" name="email_notifications">
                                <option value="0" <?= ($settings['email_notifications'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($settings['email_notifications'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                            <label class="form-label">Installation security profile</label>
                            <select class="form-select mb-2" name="security_profile">
                                <?php foreach (['safe' => 'Safe', 'developer' => 'Developer', 'advanced' => 'Advanced'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $securityProfile === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check mb-3">
                                <input class="form-check-input" id="riskAcknowledged" name="risk_acknowledged" type="checkbox" value="1">
                                <label class="form-check-label small" for="riskAcknowledged">I understand Advanced mode enables real machine-management capabilities.</label>
                            </div>
                            <button class="btn btn-primary">Save settings</button>
                        </form>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Multifactor authentication</h2></div>
                    <div class="card-body">
                        <?php $currentUser = \SysAdmin\Auth::user(); ?>
                        <p>Status: <strong><?= !empty($currentUser['mfa_enabled']) ? 'Enabled' : 'Disabled' ?></strong></p>
                        <p class="small text-muted">Remaining unused recovery codes: <?= e($mfaRecoveryRemaining) ?></p>
                        <?php if (empty($currentUser['mfa_enabled'])): ?>
                            <form class="js-api-form mb-2" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#settingsOutput">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="mfa-setup">
                                <button class="btn btn-outline-primary">Generate authenticator secret</button>
                            </form>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#settingsOutput" data-reload-on-success="1">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="mfa-enable">
                                <label class="form-label">Six-digit authenticator code</label>
                                <input class="form-control mb-2" name="code" pattern="[0-9]{6}" required>
                                <button class="btn btn-primary">Verify and enable MFA</button>
                            </form>
                        <?php else: ?>
                            <form class="js-api-form mb-2" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#settingsOutput">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="mfa-recovery-regenerate">
                                <button class="btn btn-outline-warning">Regenerate recovery codes</button>
                            </form>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#settingsOutput" data-confirm-title="Disable MFA?" data-confirm-text="Authenticator and recovery codes will be removed." data-confirm-button="Disable MFA" data-confirm-icon="warning">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="mfa-disable">
                                <button class="btn btn-outline-danger">Disable MFA</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <pre class="sys-output" id="settingsOutput"></pre>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Audit log</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm data-table">
                            <thead><tr><th>Level</th><th>Action</th><th>User</th><th>IP</th><th>Created</th></tr></thead>
                            <tbody>
                            <?php foreach ($audit as $row): ?>
                                <tr><td><?= e($row['level']) ?></td><td><?= e($row['action']) ?></td><td><?= e($row['username']) ?></td><td><?= e($row['ip_address']) ?></td><td><?= e($row['created_at']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title"><?= e(config_value('app.name', 'MicroSysAdmin')) ?> users</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>User</th><th>Role</th><th>Status</th><th>MFA</th><th>Last login</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $appUser): ?>
                                <tr>
                                    <td><strong><?= e($appUser['username']) ?></strong><br><span class="small text-muted"><?= e($appUser['email']) ?></span></td>
                                    <td>
                                        <form class="js-api-form d-flex gap-1" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#userOutput" data-reload-on-success="1">
                                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="role">
                                            <input type="hidden" name="id" value="<?= e($appUser['id']) ?>">
                                            <select class="form-select form-select-sm" name="role">
                                                <?php foreach (['Administrator', 'Operator', 'Viewer'] as $role): ?>
                                                    <option <?= $appUser['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary" title="Save role"><i class="fas fa-floppy-disk"></i></button>
                                        </form>
                                    </td>
                                    <td><span class="badge bg-<?= empty($appUser['is_disabled']) ? 'success' : 'secondary' ?>"><?= empty($appUser['is_disabled']) ? 'Active' : 'Disabled' ?></span></td>
                                    <td><?= !empty($appUser['mfa_enabled']) ? 'Enabled' : 'Off' ?></td>
                                    <td><?= e($appUser['last_login_at'] ?: 'Never') ?></td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#userOutput" data-reload-on-success="1">
                                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                                <input type="hidden" name="id" value="<?= e($appUser['id']) ?>">
                                                <button class="btn btn-xs btn-outline-<?= empty($appUser['is_disabled']) ? 'danger' : 'success' ?>" name="action" value="<?= empty($appUser['is_disabled']) ? 'disable' : 'enable' ?>">
                                                    <?= empty($appUser['is_disabled']) ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>
                                            <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#userOutput">
                                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                                <input type="hidden" name="id" value="<?= e($appUser['id']) ?>">
                                                <button class="btn btn-xs btn-outline-warning" name="action" value="logout-all">Revoke sessions</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Create user</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#userOutput" data-reload-on-success="1">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="create">
                            <label class="form-label">Username</label>
                            <input class="form-control mb-2" name="username" required>
                            <label class="form-label">Email</label>
                            <input class="form-control mb-2" name="email" type="email">
                            <label class="form-label">Temporary password</label>
                            <input class="form-control mb-2" name="password" type="password" autocomplete="new-password" required>
                            <label class="form-label">Role</label>
                            <select class="form-select mb-3" name="role">
                                <option>Viewer</option>
                                <option>Operator</option>
                                <option>Administrator</option>
                            </select>
                            <button class="btn btn-primary">Create user</button>
                        </form>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Reset password</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/users.php')) ?>" data-output="#userOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="password">
                            <select class="form-select mb-2" name="id">
                                <?php foreach ($users as $appUser): ?><option value="<?= e($appUser['id']) ?>"><?= e($appUser['username']) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control mb-2" name="password" type="password" autocomplete="new-password" placeholder="New secure password" required>
                            <button class="btn btn-outline-warning">Reset and require change</button>
                        </form>
                    </div>
                </div>
                <pre class="sys-output" id="userOutput"></pre>
            </div>
        </div>

        <?php if (($mode ?? '') === 'ssh-keys'): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">SSH public keys — inspection only</h2></div>
                <div class="card-body">
                    <?php foreach ($sshKeys as $key): ?><div class="mb-2"><code><?= e($key['file']) ?></code><br><span class="small text-muted"><?= e($key['fingerprint']) ?></span></div><?php endforeach; ?>
                    <?php if (!$sshKeys): ?><span class="text-muted">No readable public keys found.</span><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (($mode ?? '') === 'linux-users'): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Linux users — inspection only</h2></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm data-table">
                        <thead><tr><th>Name</th><th>UID</th><th>GID</th><th>Home</th><th>Shell</th></tr></thead>
                        <tbody>
                        <?php foreach ($linuxUsers as $linuxUser): ?>
                            <tr><td><?= e($linuxUser['name']) ?></td><td><?= e($linuxUser['uid']) ?></td><td><?= e($linuxUser['gid']) ?></td><td><code><?= e($linuxUser['home']) ?></code></td><td><code><?= e($linuxUser['shell']) ?></code></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="content-header"><div class="container-fluid"><h1 class="m-0">Notifications</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">SMTP connection</h2></div>
                    <div class="card-body">
                        <p class="text-muted">Credentials are encrypted locally. Leave the password blank to retain the existing value.</p>
                        <form class="js-api-form" data-endpoint="<?= e(url('api/notifications.php')) ?>" data-output="#notificationOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="save">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="enabled" value="1" id="smtpEnabled" <?= !empty($smtp['enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="smtpEnabled">Enable SMTP delivery</label>
                            </div>
                            <div class="row">
                                <div class="col-md-8"><label class="form-label">Host</label><input class="form-control mb-2" name="host" value="<?= e($smtp['host']) ?>" required></div>
                                <div class="col-md-4"><label class="form-label">Port</label><input class="form-control mb-2" name="port" type="number" min="1" max="65535" value="<?= e($smtp['port']) ?>" required></div>
                                <div class="col-md-6"><label class="form-label">Username</label><input class="form-control mb-2" name="username" value="<?= e($smtp['username']) ?>"></div>
                                <div class="col-md-6"><label class="form-label">Password</label><input class="form-control mb-2" name="password" type="password" autocomplete="new-password" placeholder="<?= !empty($smtp['password_configured']) ? 'Configured — leave blank to keep' : 'SMTP password' ?>"></div>
                                <div class="col-md-4">
                                    <label class="form-label">Encryption</label>
                                    <select class="form-select mb-2" name="encryption">
                                        <?php foreach (['' => 'None', 'tls' => 'TLS', 'ssl' => 'SSL'] as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= ($smtp['encryption'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8"><label class="form-label">From email</label><input class="form-control mb-2" name="from_email" type="email" value="<?= e($smtp['from_email']) ?>" required></div>
                                <div class="col-12"><label class="form-label">From name</label><input class="form-control mb-3" name="from_name" value="<?= e($smtp['from_name']) ?>" required></div>
                            </div>
                            <button class="btn btn-primary">Save SMTP settings</button>
                        </form>
                        <hr>
                        <form class="js-api-form" data-endpoint="<?= e(url('api/notifications.php')) ?>" data-output="#notificationOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="test">
                            <label class="form-label">Send test email</label>
                            <input class="form-control mb-2" name="email" type="email" required>
                            <button class="btn btn-outline-primary">Send test</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5"><pre class="sys-output" id="notificationOutput"></pre></div>
        </div>
    </div>
</section>

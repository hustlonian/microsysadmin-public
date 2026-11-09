<div class="content-header"><div class="container-fluid"><h1 class="m-0">Owner Setup Center</h1><p class="text-muted mb-0">Credentials are encrypted at rest. Saving a credential does not verify a connection, authorize deployment, or enable live payments.</p></div></div>
<section class="content"><div class="container-fluid">
    <div class="row">
        <div class="col-12"><div class="alert alert-info">Configure each service when its milestone is ready. This page never displays stored secrets. Use test credentials and synthetic recipients during development.</div></div>
        <div class="col-xl-4 col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="card-title">Hosting</h2></div><div class="card-body">
            <p>Readiness: <strong><?= e($setup['hosting']['state']) ?></strong></p><p>Credential saved: <?= !empty($setup['hosting']['credential_saved']) ? 'Yes' : 'No' ?></p>
            <form class="js-api-form" data-endpoint="<?= e(url('api/owner-setup.php')) ?>" data-output="#ownerSetupOutput">
                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="save-hosting">
                <label class="form-label">Provider</label><select class="form-select mb-2" name="provider" required><?php foreach (['cpanel','ssh','managed_host','other'] as $option): ?><option value="<?= e($option) ?>" <?= ($setup['hosting']['configuration']['provider'] ?? '') === $option ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$option))) ?></option><?php endforeach ?></select>
                <label class="form-label">Environment</label><select class="form-select mb-2" name="environment" required><?php foreach (['shared','vps','dedicated','cloud','local','other'] as $option): ?><option value="<?= e($option) ?>" <?= ($setup['hosting']['configuration']['environment'] ?? '') === $option ? 'selected' : '' ?>><?= e(ucwords($option)) ?></option><?php endforeach ?></select>
                <label class="form-label">Domain</label><input class="form-control mb-2" name="domain" value="<?= e($setup['hosting']['configuration']['domain'] ?? '') ?>">
                <label class="form-label">Application URL</label><input class="form-control mb-2" name="application_url" type="url" value="<?= e($setup['hosting']['configuration']['application_url'] ?? '') ?>">
                <label class="form-label">Deployment method</label><select class="form-select mb-2" name="deployment_method" required><?php foreach (['sftp','ssh','cpanel','git','manual','other'] as $option): ?><option value="<?= e($option) ?>" <?= ($setup['hosting']['configuration']['deployment_method'] ?? '') === $option ? 'selected' : '' ?>><?= e(strtoupper($option)) ?></option><?php endforeach ?></select>
                <label class="form-label">Permitted deployment scope</label><input class="form-control mb-2" name="deployment_scope" value="<?= e($setup['hosting']['configuration']['deployment_scope'] ?? '') ?>" placeholder="For example, staged application files only">
                <label class="form-label">Control panel</label><input class="form-control mb-2" name="control_panel" value="<?= e($setup['hosting']['configuration']['control_panel'] ?? '') ?>" placeholder="cPanel or other supported panel">
                <div class="row"><div class="col-md-6"><label class="form-label">PHP requirement</label><input class="form-control mb-2" name="php_requirement" value="<?= e($setup['hosting']['configuration']['php_requirement'] ?? '') ?>" placeholder="Version/extensions"></div><div class="col-md-6"><label class="form-label">Database requirement</label><input class="form-control mb-2" name="database_requirement" value="<?= e($setup['hosting']['configuration']['database_requirement'] ?? '') ?>" placeholder="Engine/version"></div></div>
                <div class="row"><div class="col-md-6"><label class="form-label">Scheduler requirement</label><input class="form-control mb-2" name="scheduler_requirement" value="<?= e($setup['hosting']['configuration']['scheduler_requirement'] ?? '') ?>"></div><div class="col-md-6"><label class="form-label">Queue requirement</label><input class="form-control mb-2" name="queue_requirement" value="<?= e($setup['hosting']['configuration']['queue_requirement'] ?? '') ?>"></div></div>
                <label class="form-label">SSL requirement</label><input class="form-control mb-2" name="ssl_requirement" value="<?= e($setup['hosting']['configuration']['ssl_requirement'] ?? '') ?>">
                <label class="form-label">Backup destination and restore capability</label><input class="form-control mb-2" name="backup_destination" value="<?= e($setup['hosting']['configuration']['backup_destination'] ?? '') ?>">
                <div class="row"><div class="col-md-4"><label class="form-label">Database host</label><input class="form-control mb-2" name="database_host" value="<?= e($setup['hosting']['configuration']['database_host'] ?? '') ?>"></div><div class="col-md-4"><label class="form-label">Database name</label><input class="form-control mb-2" name="database_name" value="<?= e($setup['hosting']['configuration']['database_name'] ?? '') ?>"></div><div class="col-md-4"><label class="form-label">Database username</label><input class="form-control mb-2" name="database_username" value="<?= e($setup['hosting']['configuration']['database_username'] ?? '') ?>"></div></div>
                <label class="form-label">Scoped deployment credential</label><input class="form-control mb-3" name="deploy_credential" type="password" autocomplete="new-password" placeholder="<?= !empty($setup['hosting']['credential_saved']) ? 'Saved — leave blank to retain' : 'Enter only when needed' ?>">
                <button class="btn btn-primary">Save hosting setup</button>
            </form>
        </div></div></div>
        <div class="col-xl-4 col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="card-title">Email</h2></div><div class="card-body">
            <p>Readiness: <strong><?= e($setup['email']['state']) ?></strong></p><p>Delivery: <?= e($setup['email']['delivery_status']) ?> · Credential saved: <?= !empty($setup['email']['credential_saved']) ? 'Yes' : 'No' ?></p>
            <form class="js-api-form" data-endpoint="<?= e(url('api/owner-setup.php')) ?>" data-output="#ownerSetupOutput">
                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="save-email">
                <label class="form-label">Provider</label><select class="form-select mb-2" name="provider" required><?php foreach (['smtp','postmark','sendgrid','ses','other'] as $option): ?><option value="<?= e($option) ?>" <?= ($setup['email']['configuration']['provider'] ?? '') === $option ? 'selected' : '' ?>><?= e(strtoupper($option)) ?></option><?php endforeach ?></select>
                <label class="form-label">Owner notification address</label><input class="form-control mb-2" name="owner_email" type="email" value="<?= e($setup['email']['configuration']['owner_email'] ?? '') ?>" required>
                <label class="form-label">Verified sender address</label><input class="form-control mb-2" name="sender_email" type="email" value="<?= e($setup['email']['configuration']['sender_email'] ?? '') ?>" required>
                <label class="form-label">Sender identity</label><input class="form-control mb-2" name="sender_name" maxlength="190" value="<?= e($setup['email']['configuration']['sender_name'] ?? '') ?>">
                <label class="form-label">Reply-to address</label><input class="form-control mb-2" name="reply_to" type="email" value="<?= e($setup['email']['configuration']['reply_to'] ?? '') ?>">
                <label class="form-label">Additional administrative recipients</label><input class="form-control mb-2" name="additional_recipients" value="<?= e($setup['email']['configuration']['additional_recipients'] ?? '') ?>">
                <label class="form-label">Provider credential</label><input class="form-control mb-3" name="provider_credential" type="password" autocomplete="new-password" placeholder="<?= !empty($setup['email']['credential_saved']) ? 'Saved — leave blank to retain' : 'Enter only when needed' ?>">
                <button class="btn btn-primary">Save email setup</button>
            </form>
        </div></div></div>
        <div class="col-xl-4 col-lg-12"><div class="card h-100"><div class="card-header"><h2 class="card-title">Stripe</h2></div><div class="card-body">
            <p>Test mode: <strong><?= e($setup['stripe']['test_state']) ?></strong></p><p>Live mode: <strong><?= e($setup['stripe']['live_state']) ?></strong></p><p>Live payments enabled: No</p>
            <form class="js-api-form" data-endpoint="<?= e(url('api/owner-setup.php')) ?>" data-output="#ownerSetupOutput">
                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="save-stripe-credentials">
                <label class="form-label">Credential mode</label><select class="form-select mb-2" name="mode"><option value="test">Test</option><option value="live">Live (stored separately; payments remain disabled)</option></select>
                <label class="form-label">Stripe secret key</label><input class="form-control mb-2" name="secret_key" type="password" autocomplete="new-password" required>
                <label class="form-label">Webhook signing secret (optional)</label><input class="form-control mb-3" name="webhook_secret" type="password" autocomplete="new-password">
                <button class="btn btn-primary">Save Stripe credentials</button>
            </form>
        </div></div></div>
    </div><pre class="sys-output mt-3" id="ownerSetupOutput"></pre>
</div></section>

<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Create backup</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/backups.php')) ?>" data-output="#backupOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Database backup</label>
                            <select class="form-select mb-2" name="database">
                                <?php foreach ($databases as $db): ?><option value="<?= e($db['name']) ?>"><?= e($db['name']) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary mb-3" name="action" value="database">Backup database</button>
                        </form>
                        <form class="js-api-form" data-endpoint="<?= e(url('api/backups.php')) ?>" data-output="#backupOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Project folder backup</label>
                            <select class="form-select mb-2" name="path">
                                <?php foreach ($roots as $root): ?><option value="<?= e($root) ?>"><?= e($root) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary" name="action" value="folder">Backup folder</button>
                        </form>
                        <div class="progress mt-3"><div class="progress-bar progress-bar-striped progress-bar-animated" id="backupProgress" style="width:0%">0%</div></div>
                        <pre class="sys-output mt-3" id="backupOutput"></pre>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Schedule backup</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/cron.php')) ?>" data-output="#backupOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="script" value="backup-sysadmin-db.sh">
                            <label class="form-label">Name</label>
                            <input class="form-control mb-2" name="name" value="<?= e(config_value('app.name', 'MicroSysAdmin')) ?> database backup">
                            <label class="form-label">Schedule</label>
                            <input class="form-control mb-2" name="schedule" value="0 3 * * *">
                            <button class="btn btn-outline-primary">Schedule nightly backup</button>
                        </form>
                    </div>
                </div>
                <?php if (\SysAdmin\Auth::can('backup.restore')): ?>
                    <div class="card border-warning">
                        <div class="card-header"><h2 class="card-title">Restore backup</h2></div>
                        <div class="card-body">
                            <p class="small text-muted">ZIP integrity and paths are checked first. MicroSysAdmin creates a pre-restore backup automatically.</p>
                            <form class="js-api-form" data-endpoint="<?= e(url('api/backups.php')) ?>" data-output="#backupOutput" data-confirm-title="Restore this backup?" data-confirm-text="Existing files may be overwritten after a pre-restore backup is created." data-confirm-button="Restore" data-confirm-icon="warning">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                <input type="hidden" name="action" value="restore">
                                <label class="form-label">ZIP archive</label>
                                <select class="form-select mb-2" name="archive" required>
                                    <?php foreach ($backups['archives'] as $archive): ?>
                                        <?php if (str_ends_with(strtolower($archive['name']), '.zip')): ?>
                                            <option value="<?= e($archive['name']) ?>"><?= e($archive['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <label class="form-label">Existing restore target</label>
                                <select class="form-select mb-2" name="target" required>
                                    <?php foreach ($roots as $root): ?><option value="<?= e($root) ?>"><?= e($root) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-warning">Verify & restore</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Backup history</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm data-table">
                            <thead><tr><th>Type</th><th>Target</th><th>Status</th><th>Progress</th><th>Archive</th><th>Created</th></tr></thead>
                            <tbody>
                            <?php foreach ($backups['history'] as $job): ?>
                                <tr><td><?= e($job['type']) ?></td><td><code><?= e($job['target']) ?></code></td><td><?= e($job['status']) ?></td><td><?= e($job['progress']) ?>%</td><td><code><?= e($job['archive_path']) ?></code></td><td><?= e($job['created_at']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Archives</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Path</th></tr></thead>
                            <tbody>
                            <?php foreach ($backups['archives'] as $archive): ?>
                                <tr><td><?= e($archive['name']) ?></td><td><?= e($archive['size_human']) ?></td><td><?= e($archive['modified']) ?></td><td><code><?= e($archive['path']) ?></code></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

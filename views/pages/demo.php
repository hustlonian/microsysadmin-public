<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0"><?= e($title) ?></h1>
        <p class="text-muted mb-0">Guided MicroSysAdmin product tour · simulated workspace</p>
    </div>
</div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <?php foreach ([
                ['CPU', $demo['metrics']['cpu'] . '%', 'fa-microchip', 'primary'],
                ['Memory', $demo['metrics']['ram']['percent'] . '%', 'fa-memory', 'info'],
                ['Storage', $demo['metrics']['disk']['percent'] . '%', 'fa-hard-drive', 'warning'],
                ['Projects', count($demo['hosting']['sites']), 'fa-window-restore', 'success'],
            ] as [$label, $value, $icon, $color]): ?>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-<?= e($color) ?>"><i class="fas <?= e($icon) ?>"></i></span><div class="info-box-content"><span class="info-box-text"><?= e($label) ?></span><span class="info-box-number"><?= e($value) ?></span></div></div></div>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <div class="col-xl-7">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Sample projects</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm"><thead><tr><th>Project</th><th>Framework</th><th>Status</th><th>Database</th></tr></thead><tbody>
                        <?php foreach ($demo['hosting']['sites'] as $site): ?><tr><td><strong><?= e($site['name']) ?></strong><br><code><?= e($site['document_root']) ?></code></td><td><?= $site['laravel'] ? 'Laravel' : 'Static' ?></td><td><span class="badge bg-success"><?= e($site['status']) ?></span></td><td><code><?= e($site['database_name'] ?? '—') ?></code></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Sample Git activity</h2></div>
                    <div class="card-body">
                        <?php foreach ($demo['git'] as $repository): ?><div class="d-flex justify-content-between border-bottom py-2"><span><code><?= e($repository['path']) ?></code><br><small><?= e($repository['last_commit']) ?></small></span><span><span class="badge bg-secondary"><?= e($repository['branch']) ?></span><br><?= e($repository['status']) ?></span></div><?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Simulated actions</h2></div>
                    <div class="card-body">
                        <p class="small text-muted">These buttons exercise the demo API. They cannot execute commands or mutate files, databases, repositories, or services.</p>
                        <?php foreach ([
                            ['services', 'restart', 'Restart Apache'],
                            ['backups', 'database', 'Create backup'],
                            ['git', 'pull', 'Pull repository'],
                            ['sandbox', 'terminal-start', 'Open simulated terminal'],
                        ] as [$endpointName, $action, $label]): ?>
                            <form class="js-api-form d-inline-block mb-2" data-endpoint="<?= e(url('api/' . $endpointName . '.php')) ?>" data-output="#demoOutput">
                                <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>"><input type="hidden" name="action" value="<?= e($action) ?>">
                                <button class="btn btn-sm btn-outline-primary"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                        <pre class="sys-output mt-3" id="demoOutput">$ microsysadmin demo
All host operations are isolated.</pre>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Sample databases</h2></div>
                    <div class="card-body">
                        <?php foreach ($demo['databases'] as $database): ?><div class="d-flex justify-content-between py-2 border-bottom"><code><?= e($database['name']) ?></code><span><?= e($database['tables']) ?> tables · <?= e($database['size']) ?></span></div><?php endforeach; ?>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Product tour</h2></div>
                    <div class="card-body small">
                        <ol class="mb-0">
                            <li>Inspect the machine and project workspaces.</li>
                            <li>Open Git Actions, Database, Backups, and Assistants.</li>
                            <li>Try simulated actions and confirm the demo banner remains visible.</li>
                            <li>Review Administration to see the planned commercial controls.</li>
                        </ol>
                        <p class="mt-2 mb-0 text-muted">Demo data automatically resets at <?= e($demo['reset_at']) ?>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

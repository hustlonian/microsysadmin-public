<div class="content-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <div class="lp-page-kicker">MicroSysAdmin</div>
                <h1 class="m-0">Command Center</h1>
            </div>
            <div class="col-sm-6 text-sm-end text-muted">Live refresh every <span data-refresh-label></span></div>
        </div>
    </div>
</div>
<section class="content">
    <div class="container-fluid" data-dashboard>
        <?php if (!empty(\SysAdmin\Auth::user()['force_password_change'])): ?>
            <div class="alert alert-warning">Default password is still active. Change it from the Settings area.</div>
        <?php endif; ?>
        <div class="lp-action-strip mb-3">
            <div>
                <strong><?= e(gethostname() ?: 'localhost') ?></strong>
                <span><?= e($metrics['uptime']) ?> uptime</span>
            </div>
            <div class="lp-action-strip-actions">
                <a class="btn btn-sm btn-primary" href="<?= e(url('sites')) ?>"><i class="fas fa-window-restore"></i> Sites</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('explorer')) ?>"><i class="fas fa-folder-open"></i> Files</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('mysql')) ?>"><i class="fas fa-database"></i> MySQL</a>
                <?php if (\SysAdmin\Auth::can('terminal.access')): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('sandbox')) ?>"><i class="fas fa-terminal"></i> Terminal</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="sys-metric-grid mb-3">
            <?php
            $hostingSummary = $hosting['summary'] ?? [];
            $cards = [
                ['CPU', $metrics['cpu'] . '%', 'cpu', 'primary', 'fa-microchip', null, null],
                ['RAM', $metrics['ram']['used_human'] . ' / ' . $metrics['ram']['total_human'], 'ram.percent', 'success', 'fa-memory', null, null],
                ['Swap', $metrics['swap']['used_human'] . ' / ' . $metrics['swap']['total_human'], 'swap.percent', 'warning', 'fa-repeat', null, null],
                ['Storage', $metrics['disk']['summary'], 'disk.summary', 'danger', 'fa-hard-drive', null, null],
                ['Load average', implode(' ', array_map(static fn ($v) => round((float) $v, 2), $metrics['load'])), 'load', 'info', 'fa-chart-line', null, null],
                ['Sites', (string) ($hostingSummary['sites'] ?? 0), 'hosting.sites', 'primary', 'fa-window-restore', url('sites'), 'Open hosted localhost sites'],
                ['Apache', $metrics['services']['apache']['label'], 'services.apache.label', 'secondary', 'fa-server', null, null],
                ['MySQL', $metrics['services']['mysql']['label'], 'services.mysql.label', 'secondary', 'fa-database', url('mysql'), 'Open MySQL database CRUD'],
                ['Uptime', $metrics['uptime'], 'uptime', 'dark', 'fa-clock', null, null],
            ];
            ?>
            <?php foreach ($cards as [$label, $value, $key, $color, $icon, $href, $ariaLabel]): ?>
                <?php if ($href): ?>
                    <a class="small-box bg-<?= e($color) ?> sys-metric-link" href="<?= e($href) ?>" aria-label="<?= e($ariaLabel ?: $label) ?>">
                <?php else: ?>
                    <div class="small-box bg-<?= e($color) ?>">
                <?php endif; ?>
                    <div class="inner">
                        <h3 data-metric="<?= e($key) ?>"><?= e($value) ?></h3>
                        <p><?= e($label) ?></p>
                    </div>
                    <div class="icon"><i class="fas <?= e($icon) ?>"></i></div>
                <?php if ($href): ?>
                    </a>
                <?php else: ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Resource history</h2></div>
                    <div class="card-body">
                        <canvas
                            id="resourceChart"
                            height="118"
                            data-initial-cpu="<?= e($metrics['cpu']) ?>"
                            data-initial-ram="<?= e($metrics['ram']['percent']) ?>"
                            data-initial-disk="<?= e($metrics['disk']['percent']) ?>"
                        ></canvas>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Top processes</h2></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm table-hover mb-0" id="topProcessTable">
                            <thead><tr><th>PID</th><th>CPU</th><th>Memory</th><th>Owner</th><th>Command</th></tr></thead>
                            <tbody data-process-rows>
                            <?php foreach ($metrics['top_processes'] as $process): ?>
                                <tr><td><?= e($process['pid']) ?></td><td><?= e($process['cpu']) ?>%</td><td><?= e($process['memory']) ?>%</td><td><?= e($process['owner']) ?></td><td><code><?= e($process['command']) ?></code></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Network activity</h2></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between"><span>Received</span><strong data-metric="network.rx_human"><?= e($metrics['network']['rx_human']) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Sent</span><strong data-metric="network.tx_human"><?= e($metrics['network']['tx_human']) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Temperature</span><strong data-metric="temperature"><?= e($metrics['temperature']['celsius'] ?? 'Unavailable') ?></strong></div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Local sites</h2>
                        <div class="card-tools">
                            <a class="btn btn-tool" href="<?= e(url('sites')) ?>" aria-label="Open sites" title="Open sites"><i class="fas fa-arrow-up-right-from-square"></i></a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach (array_slice($hosting['sites'] ?? [], 0, 5) as $site): ?>
                                <li class="list-group-item small">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong><?= e($site['name']) ?></strong>
                                        <span class="badge bg-<?= !empty($site['managed']) ? 'success' : 'secondary' ?>"><?= !empty($site['managed']) ? 'Managed' : 'Discovered' ?></span>
                                    </div>
                                    <?php if (!empty($site['url'])): ?>
                                        <a href="<?= e($site['url']) ?>" target="_blank" rel="noopener"><?= e($site['url']) ?></a>
                                    <?php else: ?>
                                        <code><?= e($site['document_root']) ?></code>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($hosting['sites'])): ?>
                                <li class="list-group-item small text-muted">No local sites found.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Recent log events</h2></div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush" data-log-events>
                            <?php foreach ($metrics['recent_logs'] as $event): ?>
                                <li class="list-group-item small"><strong><?= e($event['source']) ?></strong><br><?= e($event['line']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Git repositories</h2></div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush" data-git-summary>
                            <?php foreach ($metrics['git'] as $repo): ?>
                                <li class="list-group-item small"><?= e($repo['path']) ?><br><span class="badge bg-<?= $repo['dirty'] ? 'warning' : 'success' ?>"><?= e($repo['branch']) ?> <?= $repo['dirty'] ? 'dirty' : 'clean' ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

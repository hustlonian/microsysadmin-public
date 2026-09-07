<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0"><?= e($title) ?></h1>
    </div>
</div>
<section class="content">
    <div class="container-fluid">
        <?php if ($mode === 'health'): ?>
            <div class="row">
                <?php foreach ($checks as $check): ?>
                    <div class="col-md-4">
                        <div class="info-box">
                            <span class="info-box-icon bg-<?= $check['ok'] ? 'success' : 'danger' ?>"><i class="fas <?= $check['ok'] ? 'fa-check' : 'fa-triangle-exclamation' ?>"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text"><?= e($check['name']) ?></span>
                                <span class="info-box-number"><?= e($check['value']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Host</h2></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-5">Hostname</dt><dd class="col-7"><?= e($info['hostname']) ?></dd>
                            <dt class="col-5">Kernel</dt><dd class="col-7"><?= e($info['kernel']) ?></dd>
                            <dt class="col-5">Distribution</dt><dd class="col-7"><?= e($info['distribution']) ?></dd>
                            <dt class="col-5">Uptime</dt><dd class="col-7"><?= e($info['uptime']) ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Versions</h2></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-5">PHP</dt><dd class="col-7"><?= e($info['php_version']) ?></dd>
                            <dt class="col-5">Apache</dt><dd class="col-7"><code><?= e(strtok($info['apache_version'], "\n")) ?></code></dd>
                            <dt class="col-5">MySQL</dt><dd class="col-7"><code><?= e($info['mysql_version']) ?></code></dd>
                            <dt class="col-5">Composer</dt><dd class="col-7"><code><?= e($info['composer_version']) ?></code></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Network interfaces</h2></div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Name</th><th>State</th><th>MAC</th></tr></thead>
                            <tbody>
                            <?php foreach ($info['network_interfaces'] as $iface): ?>
                                <tr><td><?= e($iface['name']) ?></td><td><?= e($iface['state']) ?></td><td><code><?= e($iface['mac']) ?></code></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <?php
            $panels = match ($mode) {
                'storage' => ['Storage scope' => $info['storage_scope'], 'Filesystem for scope' => $info['disk_layout']],
                'networking' => ['IP addresses' => $info['ip_addresses'], 'Open ports' => $info['open_ports']],
                'apache' => ['Apache version' => $info['apache_version'], 'Virtual host config' => is_readable(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-vhosts.conf') ? file_get_contents(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-vhosts.conf') : 'No readable vhost file found.'],
                'virtual-hosts' => ['Virtual hosts' => is_readable(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-vhosts.conf') ? file_get_contents(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-vhosts.conf') : 'No readable vhost file found.'],
                'ssl' => ['SSL config' => is_readable(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-ssl.conf') ? file_get_contents(\SysAdmin\EnvironmentConfig::path('xampp_root') . '/etc/extra/httpd-ssl.conf') : 'No readable SSL config found.'],
                'domains' => ['Website roots' => implode("\n", array_map(static fn ($p) => is_dir($p) ? $p : $p . ' missing', \SysAdmin\EnvironmentConfig::paths('document_roots'))), 'Document root directories' => implode("\n", array_slice(array_map('basename', glob((\SysAdmin\EnvironmentConfig::paths('document_roots')[0] ?? dirname(APP_ROOT)) . '/*', GLOB_ONLYDIR) ?: []), 0, 120))],
                default => ['Storage scope' => $info['storage_scope'], 'IP addresses' => $info['ip_addresses'], 'Open ports' => $info['open_ports'], 'PHP modules' => $info['php_modules']],
            };
            ?>
            <?php foreach ($panels as $label => $text): ?>
                <div class="col-lg-<?= count($panels) > 2 ? '6' : '12' ?>">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title"><?= e($label) ?></h2></div>
                        <div class="card-body"><pre class="sys-output"><?= e($text) ?></pre></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

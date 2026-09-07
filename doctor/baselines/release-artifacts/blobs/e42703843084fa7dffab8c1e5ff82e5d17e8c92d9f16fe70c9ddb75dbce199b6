<?php
$navigation = $navigation ?? require APP_ROOT . '/config/navigation.php';
$user = \SysAdmin\Auth::user();
$settings = \SysAdmin\Models\Setting::all();
$appName = (string) config_value('app.name', 'MicroSysAdmin');
$title = $title ?? 'Dashboard';
$active = $active ?? 'dashboard';
$darkMode = ($settings['dark_mode'] ?? '0') === '1';
$theme = in_array(($settings['theme'] ?? 'default'), ['default', 'blue', 'green'], true) ? $settings['theme'] : 'default';
$assetVersion = static function (string $path): string {
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    return asset_url($path) . '?v=' . (is_file($file) ? filemtime($file) : time());
};
$navItemActive = null;
$navItemActive = static function (array $item) use (&$navItemActive, $active): bool {
    if (($item['slug'] ?? null) === $active) {
        return true;
    }
    foreach (($item['children'] ?? []) as $child) {
        if ($navItemActive($child)) {
            return true;
        }
    }
    return false;
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(\SysAdmin\Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(base_url()) ?>">
    <meta name="refresh-interval" content="<?= e($settings['refresh_interval'] ?? config_value('app.refresh_interval', 5000)) ?>">
    <title><?= e($title) ?> | <?= e($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.min.css">
    <?php if (in_array($active, ['sandbox', 'code-runner'], true)): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e($assetVersion('css/sysadmin.css')) ?>">
</head>
<body class="hold-transition sidebar-mini layout-fixed microsysadmin-shell theme-<?= e($theme) ?> <?= $darkMode ? 'dark-mode' : '' ?>">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light lp-topbar">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="<?= e(url('dashboard')) ?>" class="nav-link">Command Center</a>
            </li>
        </ul>
        <div class="lp-host-pill d-none d-md-flex">
            <span class="lp-host-dot"></span>
            <span><?= e(gethostname() ?: 'localhost') ?></span>
            <span class="lp-host-divider"></span>
            <span>PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></span>
        </div>
        <form class="form-inline ms-auto me-3" action="<?= e(url('search')) ?>" method="get">
            <div class="input-group input-group-sm">
                <input class="form-control" name="q" placeholder="Global search" aria-label="Global search">
                <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
            </div>
        </form>
        <span class="navbar-text small me-3"><?= e($user['username'] ?? '') ?> · <?= e($user['role'] ?? '') ?></span>
        <form method="post" action="<?= e(url('logout')) ?>" class="me-2">
            <?= \SysAdmin\Csrf::field() ?>
            <button class="btn btn-sm btn-outline-danger" type="submit">Logout</button>
        </form>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4 lp-sidebar">
        <a href="<?= e(url('dashboard')) ?>" class="brand-link lp-brand">
            <span class="lp-brand-mark"><i class="fas fa-cube"></i></span>
            <span class="brand-text font-weight-light">
                <span><?= e($appName) ?></span>
                <small>A Local Dev Workspace</small>
            </span>
        </a>
        <div class="sidebar">
            <div class="sys-sidebar-action">
                <button class="nav-link sys-sidebar-refresh" type="button" data-page-refresh aria-label="Refresh current page" title="Refresh current page">
                    <i class="nav-icon fas fa-arrows-rotate"></i>
                    <p>Refresh</p>
                </button>
            </div>
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column nav-compact" data-widget="treeview" role="menu" data-accordion="false">
                    <?php foreach ($navigation as $section => $items): ?>
                        <li class="nav-header"><?= e($section) ?></li>
                        <?php foreach ($items as $item): ?>
                            <?php if (!empty($item['children'])): ?>
                                <?php $isOpen = $navItemActive($item); ?>
                                <li class="nav-item <?= $isOpen ? 'menu-open' : '' ?>">
                                    <a href="#" class="nav-link <?= $isOpen ? 'active' : '' ?>">
                                        <i class="nav-icon fas <?= e($item['icon']) ?>"></i>
                                        <p>
                                            <?= e($item['label']) ?>
                                            <i class="right fas fa-angle-left"></i>
                                        </p>
                                    </a>
                                    <ul class="nav nav-treeview">
                                        <?php foreach ($item['children'] as $child): ?>
                                            <li class="nav-item">
                                                <a href="<?= e(url($child['slug'])) ?>" class="nav-link <?= $active === $child['slug'] ? 'active' : '' ?>">
                                                    <i class="nav-icon fas <?= e($child['icon']) ?>"></i>
                                                    <p><?= e($child['label']) ?></p>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </li>
                            <?php else: ?>
                                <li class="nav-item">
                                    <a href="<?= e(url($item['slug'])) ?>" class="nav-link <?= $active === $item['slug'] ? 'active' : '' ?>">
                                        <i class="nav-icon fas <?= e($item['icon']) ?>"></i>
                                        <p><?= e($item['label']) ?></p>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </aside>

    <main class="content-wrapper">
        <?php if (\SysAdmin\DemoMode::enabled()): ?>
            <div class="alert alert-info rounded-0 mb-0 text-center">
                <strong>Demonstration mode:</strong> all data and actions are simulated. This interface is not connected to the host machine.
            </div>
        <?php endif; ?>
        <?= $content ?>
    </main>

    <footer class="main-footer small">
        <strong><?= e($appName) ?></strong>
        <span class="float-right d-none d-sm-inline">Self-hosted · <?= e(gethostname() ?: 'localhost') ?></span>
    </footer>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.3/dist/sweetalert2.all.min.js"></script>
<?php if (in_array($active, ['sandbox', 'code-runner'], true)): ?>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.js"></script>
<?php endif; ?>
<script src="<?= e($assetVersion('js/sysadmin.js')) ?>"></script>
</body>
</html>

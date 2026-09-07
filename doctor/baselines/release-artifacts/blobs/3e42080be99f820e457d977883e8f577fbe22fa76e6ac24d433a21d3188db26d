<?php
$hosting = $hosting ?? ['summary' => [], 'roots' => [], 'sites' => []];
$summary = $hosting['summary'] ?? [];
$roots = $hosting['roots'] ?? [];
$sites = $hosting['sites'] ?? [];
$sizesLoaded = $sizesLoaded ?? false;
$canWriteHosting = \SysAdmin\Auth::can('hosting.write');
$canEditSitePages = $canWriteHosting || \SysAdmin\Auth::can('file.edit');
$canRunArtisan = \SysAdmin\Auth::can('command.execute');
$canRunAnyArtisan = \SysAdmin\Auth::can('terminal.access');
$hasWritableRoot = count(array_filter($roots, static fn (array $root): bool => !empty($root['writable']))) > 0;
$artisanSuggestions = array_values(array_unique(array_merge(
    (require APP_ROOT . '/config/commands.php')['artisan'] ?? [],
    [
        'about',
        'list',
        'route:list',
        'migrate:status',
        'migrate:fresh',
        'db:show',
        'make:command',
        'make:controller',
        'make:migration',
        'make:model',
        'optimize:clear',
        'queue:failed',
        'schedule:list',
        'test',
    ]
)));
?>
<div class="content-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6"><h1 class="m-0"><?= e($title) ?></h1></div>
            <div class="col-sm-6 text-sm-end text-muted"><?= e(gethostname() ?: 'localhost') ?></div>
        </div>
    </div>
</div>
<section class="content">
    <div class="container-fluid">
        <div class="sys-metric-grid mb-3">
            <?php
            $cards = [
                ['Sites', (string) ($summary['sites'] ?? 0), 'primary', 'fa-window-restore'],
                ['Managed', (string) ($summary['managed'] ?? 0), 'success', 'fa-circle-check'],
                ['Discovered', (string) ($summary['discovered'] ?? 0), 'info', 'fa-magnifying-glass'],
                ['Writable roots', (string) ($summary['writable_roots'] ?? 0), 'warning', 'fa-folder-tree'],
            ];
            ?>
            <?php foreach ($cards as [$label, $value, $color, $icon]): ?>
                <div class="small-box bg-<?= e($color) ?>">
                    <div class="inner">
                        <h3><?= e($value) ?></h3>
                        <p><?= e($label) ?></p>
                    </div>
                    <div class="icon"><i class="fas <?= e($icon) ?>"></i></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Create Localhost Site</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/hosting.php')) ?>" data-reload-on-success="1" data-submitting-label="Creating Laravel site">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="create">
                            <label class="form-label" for="siteName">Site name</label>
                            <input class="form-control mb-2" id="siteName" name="name" placeholder="client-portal" required <?= $canWriteHosting ? '' : 'disabled' ?>>
                            <label class="form-label" for="siteRoot">Document root parent</label>
                            <select class="form-select mb-2" id="siteRoot" name="root" <?= ($canWriteHosting && $hasWritableRoot) ? '' : 'disabled' ?>>
                                <?php foreach ($roots as $root): ?>
                                    <option value="<?= e($root['path']) ?>" <?= !empty($root['writable']) ? '' : 'disabled' ?>>
                                        <?= e($root['path']) ?><?= !empty($root['writable']) ? '' : ' (read-only)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary w-100" type="submit" <?= ($canWriteHosting && $hasWritableRoot) ? '' : 'disabled' ?>>
                                <i class="fas fa-folder-plus"></i> Create Site
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Sites</h2>
                        <div class="card-tools">
                            <a class="btn btn-tool" href="<?= e(url($sizesLoaded ? 'sites' : 'sites?sizes=1')) ?>" aria-label="<?= $sizesLoaded ? 'Hide site sizes' : 'Calculate site sizes' ?>" title="<?= $sizesLoaded ? 'Hide site sizes' : 'Calculate site sizes' ?>">
                                <i class="fas <?= $sizesLoaded ? 'fa-gauge-high' : 'fa-calculator' ?>"></i>
                            </a>
                            <a class="btn btn-tool" href="<?= e(url('sites')) ?>" aria-label="Refresh sites" title="Refresh sites">
                                <i class="fas fa-arrows-rotate"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm table-hover mb-0 data-table hosting-site-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>URL</th>
                                    <th>Document root</th>
                                    <th>Status</th>
                                    <th>PHP</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sites as $site): ?>
                                <?php
                                $canDeleteSite = $canWriteHosting && !empty($site['deletable']);
                                $deleteConfirmText = 'Move ' . $site['name'] . ' and its site folder to trash? Selected database cleanup is permanent.';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($site['name']) ?></strong><br>
                                        <span class="badge bg-<?= !empty($site['managed']) ? 'success' : 'secondary' ?>">
                                            <?= !empty($site['managed']) ? 'Managed' : 'Discovered' ?>
                                        </span>
                                        <span class="badge bg-<?= !empty($site['has_index']) ? 'primary' : 'warning' ?>">
                                            <?= !empty($site['has_index']) ? 'Index' : 'No index' ?>
                                        </span>
                                        <span class="badge bg-<?= !empty($site['writable']) ? 'success' : 'secondary' ?>">
                                            <?= !empty($site['writable']) ? 'Writable' : 'Read-only' ?>
                                        </span>
                                        <?php if (!empty($site['laravel'])): ?>
                                            <span class="badge bg-danger">Laravel</span>
                                        <?php endif; ?>
                                        <?php if (!empty($site['database_name'])): ?>
                                            <a class="badge bg-info text-decoration-none" href="<?= e(url('mysql?database=' . rawurlencode($site['database_name']))) ?>">
                                                MySQL: <?= e($site['database_name']) ?>
                                            </a>
                                        <?php endif; ?>
                                        <div class="site-crud-actions mt-2 d-flex flex-wrap gap-1">
                                            <?php if (!empty($site['editable']) && $canWriteHosting): ?>
                                                <button class="btn btn-xs btn-outline-success js-site-pages" type="button" data-site="<?= e($site['document_root']) ?>" data-site-name="<?= e($site['name']) ?>" data-open-create="1">
                                                    <i class="fas fa-plus"></i> Create
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($site['url'])): ?>
                                                <a class="btn btn-xs btn-outline-primary" href="<?= e($site['url']) ?>" target="_blank" rel="noopener">
                                                    <i class="fas fa-eye"></i> Read
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($site['editable']) && $canEditSitePages): ?>
                                                <button class="btn btn-xs btn-outline-warning js-site-pages" type="button" data-site="<?= e($site['document_root']) ?>" data-site-name="<?= e($site['name']) ?>">
                                                    <i class="fas fa-pen-to-square"></i> Update
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($site['artisan_ready']) && $canRunArtisan): ?>
                                                <button class="btn btn-xs btn-outline-info js-site-migrate" type="button" data-site="<?= e($site['document_root']) ?>" data-site-name="<?= e($site['name']) ?>" title="Run pending database migrations">
                                                    <i class="fas fa-database"></i> Migrate
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($site['artisan_ready']) && $canRunAnyArtisan): ?>
                                                <button class="btn btn-xs btn-outline-dark js-site-artisan" type="button" data-site="<?= e($site['document_root']) ?>" data-site-name="<?= e($site['name']) ?>" title="Run an Artisan command">
                                                    <i class="fas fa-terminal"></i> Artisan
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canDeleteSite): ?>
                                                <form class="js-api-form d-inline" data-endpoint="<?= e(url('api/hosting.php')) ?>" data-output="#hostingOutput" data-reload-on-success="1" data-confirm-title="Move site to trash?" data-confirm-text="<?= e($deleteConfirmText) ?>" data-confirm-button="Move to trash" data-confirm-icon="warning">
                                                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="document_root" value="<?= e($site['document_root']) ?>">
                                                    <?php if (!empty($site['database_name']) && str_starts_with($site['database_name'], 'lp_')): ?>
                                                        <label class="small d-block"><input type="checkbox" name="cleanup_database" value="1"> Drop database</label>
                                                        <label class="small d-block"><input type="checkbox" name="cleanup_database_user" value="1"> Drop DB user</label>
                                                    <?php endif; ?>
                                                    <button class="btn btn-xs btn-outline-danger" type="submit">
                                                        <i class="fas fa-trash-can"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($site['url'])): ?>
                                            <a href="<?= e($site['url']) ?>" target="_blank" rel="noopener"><?= e($site['url']) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">Unmapped</span>
                                        <?php endif; ?>
                                        <?php if (!empty($site['domain'])): ?>
                                            <br><code><?= e($site['domain']) ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="hosting-path"><?= e($site['document_root']) ?></code></td>
                                    <td><?= e($site['status']) ?></td>
                                    <td><?= e($site['php_version'] ?: PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></td>
                                    <td><?= $site['size_human'] !== '' ? e($site['size_human']) : '<span class="text-muted">On demand</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$sites): ?>
                                <tr><td colspan="6" class="text-muted">No sites found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="info-box">
                            <span class="info-box-icon bg-info"><i class="fas fa-database"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Databases</span>
                                <a class="info-box-number" href="<?= e(url('mysql')) ?>">Open MySQL</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-box">
                            <span class="info-box-icon bg-dark"><i class="fas fa-terminal"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Terminal</span>
                                <a class="info-box-number" href="<?= e(url('sandbox')) ?>">Open Shell</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="sitePagesModal" tabindex="-1" aria-hidden="true" data-can-edit-pages="<?= $canEditSitePages ? '1' : '0' ?>" data-can-manage-pages="<?= $canWriteHosting ? '1' : '0' ?>">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5" id="sitePagesModalTitle">Site Pages</h2>
                    <div class="small text-muted">
                        <span id="sitePagesSiteName"></span>
                        <code class="ms-2" id="sitePagesRoot"></code>
                    </div>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <?php if ($canWriteHosting): ?>
                            <form class="site-page-create-form mb-3" id="sitePageCreateForm">
                                <input type="hidden" name="action" value="create-page">
                                <input type="hidden" name="site" value="">
                                <div class="row g-2">
                                    <div class="col-sm-5">
                                        <label class="form-label" id="sitePageUriLabel" for="sitePageUri">Route</label>
                                        <input class="form-control form-control-sm" id="sitePageUri" name="uri" placeholder="/about" required>
                                    </div>
                                    <div class="col-sm-7" id="sitePageViewGroup">
                                        <label class="form-label" id="sitePageViewLabel" for="sitePageView">Blade view</label>
                                        <input class="form-control form-control-sm" id="sitePageView" name="view" placeholder="pages.about" required>
                                    </div>
                                </div>
                                <label class="form-label mt-2" for="sitePageContent">Blade content</label>
                                <textarea class="form-control site-page-create-content" id="sitePageContent" name="content" rows="4" spellcheck="false"></textarea>
                                <button class="btn btn-sm btn-primary mt-2" type="submit">
                                    <i class="fas fa-plus"></i> Create Page
                                </button>
                            </form>
                        <?php endif; ?>
                        <div class="site-pages-list" id="sitePagesList"></div>
                    </div>
                    <div class="col-lg-7">
                        <div class="site-pages-empty" id="sitePagesEmpty">Select a page.</div>
                        <form class="site-page-editor d-none" id="sitePageEditorForm">
                            <input type="hidden" name="action" value="update-page">
                            <input type="hidden" name="site" value="">
                            <input type="hidden" name="view" value="">
                            <div class="site-page-editor-toolbar d-flex align-items-center justify-content-between gap-2 mb-2">
                                <div class="site-page-editor-heading">
                                    <label class="small text-muted" id="sitePageEditorLabel" for="sitePageEditorContent">Blade</label>
                                    <code id="sitePageEditorPath"></code>
                                </div>
                                <?php if ($canEditSitePages): ?>
                                    <button class="btn btn-sm btn-primary site-page-editor-save" type="submit">
                                        <i class="fas fa-floppy-disk"></i> Save Page
                                    </button>
                                <?php endif; ?>
                            </div>
                            <textarea class="form-control site-blade-editor" id="sitePageEditorContent" name="content" rows="24" spellcheck="false" <?= $canEditSitePages ? '' : 'readonly' ?>></textarea>
                        </form>
                    </div>
                </div>
                <pre class="sys-output compact mt-3" id="sitePagesOutput" hidden></pre>
            </div>
        </div>
    </div>
</div>

<?php if ($canRunArtisan): ?>
    <div class="modal fade" id="siteArtisanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5">Artisan</h2>
                        <div class="small text-muted">
                            <span id="siteArtisanSiteName"></span>
                            <code class="ms-2" id="siteArtisanRoot"></code>
                        </div>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="site-artisan-quick">
                        <div>
                            <h3 class="h6 mb-1">Database migrations</h3>
                            <span class="small text-muted">php artisan migrate</span>
                        </div>
                        <button class="btn btn-primary" id="siteArtisanMigrate" type="button">
                            <i class="fas fa-database"></i> Migrate
                        </button>
                    </div>
                    <?php if ($canRunAnyArtisan): ?>
                        <form class="site-artisan-runner" id="siteArtisanForm">
                            <label class="form-label" for="siteArtisanCommand">Artisan command</label>
                            <div class="input-group">
                                <span class="input-group-text">php artisan</span>
                                <input class="form-control font-monospace" id="siteArtisanCommand" name="command" list="siteArtisanSuggestions" placeholder="route:list" autocomplete="off" required>
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-play"></i> Run
                                </button>
                            </div>
                            <datalist id="siteArtisanSuggestions">
                                <?php foreach ($artisanSuggestions as $command): ?>
                                    <option value="<?= e((string) $command) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </form>
                    <?php endif; ?>
                    <div class="site-artisan-output-wrap">
                        <pre class="sys-output site-artisan-output mb-0" id="siteArtisanOutput" aria-live="polite" hidden></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

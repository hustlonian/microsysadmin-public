<?php
$canEditFiles = \SysAdmin\Auth::can('file.edit');
$canUploadFiles = \SysAdmin\Auth::can('file.upload');
$canDeleteFiles = \SysAdmin\Auth::can('file.delete');
?>
<div class="content-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6"><h1 class="m-0"><?= e($title) ?></h1></div>
            <div class="col-sm-6 text-sm-end"><code><?= e($listing['path']) ?></code></div>
        </div>
    </div>
</div>
<section class="content">
    <div class="container-fluid file-manager" data-current-path="<?= e($listing['path']) ?>" data-selected-path="<?= e((string) ($_GET['select'] ?? '')) ?>">
        <div class="row">
            <div class="col-lg-3">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Roots</h2></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($listing['roots'] as $root): ?>
                            <a class="list-group-item list-group-item-action" href="<?= e(url($active . '?path=' . rawurlencode($root))) ?>"><?= e($root) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($canEditFiles || $canUploadFiles || $canDeleteFiles): ?>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Actions</h2></div>
                        <div class="card-body">
                            <input type="hidden" id="selectedPath" value="">
                            <div class="small text-muted mb-2">Selected: <code id="selectedPathLabel">None</code></div>
                            <?php if ($canEditFiles || $canUploadFiles || $canDeleteFiles): ?>
                                <div class="d-grid gap-2">
                                    <?php if ($canEditFiles): ?>
                                        <button class="btn btn-outline-primary js-file-action" type="button" data-action="create-folder"><i class="fas fa-folder-plus"></i> Create folder</button>
                                        <button class="btn btn-outline-primary js-file-action" type="button" data-action="create-file"><i class="fas fa-file-circle-plus"></i> Create file</button>
                                        <button class="btn btn-outline-secondary js-file-action" type="button" data-action="edit"><i class="fas fa-pen-to-square"></i> Edit file</button>
                                        <button class="btn btn-outline-secondary js-file-action" type="button" data-action="rename"><i class="fas fa-i-cursor"></i> Rename</button>
                                        <button class="btn btn-outline-secondary js-file-action" type="button" data-action="chmod"><i class="fas fa-lock"></i> Permissions</button>
                                        <button class="btn btn-outline-secondary js-file-action" type="button" data-action="copy"><i class="fas fa-copy"></i> Copy</button>
                                        <button class="btn btn-outline-secondary js-file-action" type="button" data-action="move"><i class="fas fa-up-down-left-right"></i> Move</button>
                                        <button class="btn btn-outline-warning js-file-action" type="button" data-action="zip"><i class="fas fa-file-zipper"></i> Zip</button>
                                    <?php endif; ?>
                                    <?php if ($canUploadFiles): ?>
                                        <button class="btn btn-outline-warning js-file-action" type="button" data-action="unzip"><i class="fas fa-box-open"></i> Unzip</button>
                                    <?php endif; ?>
                                    <?php if ($canDeleteFiles): ?>
                                        <button class="btn btn-outline-danger js-file-action" type="button" data-action="delete"><i class="fas fa-trash-can"></i> Delete to trash</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($canUploadFiles): ?>
                                <hr>
                                <form class="js-upload-form" enctype="multipart/form-data">
                                    <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="upload">
                                    <input type="hidden" name="path" value="<?= e($listing['path']) ?>">
                                    <label class="form-label">Upload</label>
                                    <input class="form-control mb-2" type="file" name="file" required>
                                    <button class="btn btn-primary w-100" type="submit">Upload file</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Explorer</h2>
                        <div class="card-tools">
                            <?php foreach ($listing['breadcrumbs'] as $crumb): ?>
                                <a href="<?= e(url($active . '?path=' . rawurlencode($crumb['path']))) ?>"><?= e($crumb['label']) ?></a>
                                <span class="text-muted">/</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover table-sm mb-0 data-table">
                            <thead><tr><th>Name</th><th>Size</th><th>Permissions</th><th>Owner</th><th>Modified</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($listing['entries'] as $entry): ?>
                                <tr
                                    class="js-file-row"
                                    data-path="<?= e($entry['path']) ?>"
                                    data-type="<?= e($entry['type']) ?>"
                                    data-permissions="<?= e($entry['permissions']) ?>"
                                    <?php if ($entry['type'] === 'folder'): ?>
                                        data-open-url="<?= e(url($active . '?path=' . rawurlencode($entry['path']))) ?>"
                                    <?php endif; ?>
                                >
                                    <td><i class="fas <?= $entry['type'] === 'folder' ? 'fa-folder text-warning' : 'fa-file text-secondary' ?>"></i> <?= e($entry['name']) ?></td>
                                    <td><?= e($entry['size_human']) ?></td>
                                    <td><code><?= e($entry['permissions']) ?></code></td>
                                    <td><?= e($entry['owner']) ?>:<?= e($entry['group']) ?></td>
                                    <td><?= e($entry['modified']) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($entry['type'] === 'folder'): ?>
                                            <a class="btn btn-xs btn-outline-primary" href="<?= e(url($active . '?path=' . rawurlencode($entry['path']))) ?>"><i class="fas fa-folder-open"></i> Open</a>
                                        <?php else: ?>
                                            <?php if ($canEditFiles): ?>
                                                <button class="btn btn-xs btn-outline-secondary js-file-action" type="button" data-action="edit" data-path="<?= e($entry['path']) ?>" data-type="<?= e($entry['type']) ?>"><i class="fas fa-pen-to-square"></i> Edit</button>
                                            <?php endif; ?>
                                            <a class="btn btn-xs btn-outline-secondary" href="<?= e(url('api/file.php?action=download&path=' . rawurlencode($entry['path']))) ?>"><i class="fas fa-download"></i> Download</a>
                                        <?php endif; ?>
                                        <?php if ($canEditFiles): ?>
                                            <button class="btn btn-xs btn-outline-secondary js-file-action" type="button" data-action="rename" data-path="<?= e($entry['path']) ?>" data-type="<?= e($entry['type']) ?>"><i class="fas fa-i-cursor"></i> Rename</button>
                                            <button class="btn btn-xs btn-outline-secondary js-file-action" type="button" data-action="chmod" data-path="<?= e($entry['path']) ?>" data-type="<?= e($entry['type']) ?>" data-permissions="<?= e($entry['permissions']) ?>"><i class="fas fa-lock"></i> Permissions</button>
                                        <?php endif; ?>
                                        <?php if ($canDeleteFiles): ?>
                                            <button class="btn btn-xs btn-outline-danger js-file-action" type="button" data-action="delete" data-path="<?= e($entry['path']) ?>" data-type="<?= e($entry['type']) ?>"><i class="fas fa-trash-can"></i> Trash</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Preview</h2></div>
                    <div class="card-body" id="filePreview">Select a file or folder.</div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Search inside root</h2></div>
                    <div class="card-body">
                        <form action="<?= e(url('search')) ?>" method="get">
                            <input type="hidden" name="root" value="<?= e($listing['path']) ?>">
                            <div class="input-group">
                                <input class="form-control" name="q" placeholder="file name">
                                <button class="btn btn-outline-primary">Search</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

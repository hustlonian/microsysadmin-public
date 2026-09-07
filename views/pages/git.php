<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Repositories</h2></div>
            <div class="card-body table-responsive">
                <table class="table table-sm data-table">
                    <thead><tr><th>Path</th><th>Branch</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($repositories as $repo): ?>
                        <tr>
                            <td><code><?= e($repo['path']) ?></code></td>
                            <td><?= e($repo['branch']) ?></td>
                            <td><span class="badge bg-<?= $repo['dirty'] ? 'warning' : 'success' ?>"><?= $repo['dirty'] ? 'dirty' : 'clean' ?></span><pre class="sys-output compact"><?= e($repo['status']) ?></pre></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php foreach (['fetch', 'pull', 'diff', 'push'] as $action): ?>
                                        <button class="btn btn-outline-primary js-git-action" data-path="<?= e($repo['path']) ?>" data-action="<?= e($action) ?>"><?= e(ucfirst($action)) ?></button>
                                    <?php endforeach; ?>
                                    <button class="btn btn-outline-success js-git-action" data-path="<?= e($repo['path']) ?>" data-action="commit">Commit</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <pre class="sys-output" id="gitOutput"></pre>
    </div>
</section>

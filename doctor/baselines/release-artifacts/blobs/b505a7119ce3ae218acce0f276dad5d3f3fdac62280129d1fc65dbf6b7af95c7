<div class="content-header"><div class="container-fluid"><h1 class="m-0">Bash Scripts</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Approved scripts in storage/scripts</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Script</th><th>Description</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($scripts as $script): ?>
                                <tr><td><code><?= e($script['name']) ?></code></td><td><?= e($script['description']) ?></td><td><button class="btn btn-sm btn-primary js-script-run" data-script="<?= e($script['name']) ?>">Run</button></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <pre class="sys-output" id="scriptOutput"></pre>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Execution history</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm data-table">
                            <thead><tr><th>Script</th><th>Status</th><th>Exit</th><th>Run by</th><th>Created</th><th>Finished</th></tr></thead>
                            <tbody>
                            <?php foreach ($runs as $run): ?>
                                <tr><td><?= e($run['script_name']) ?></td><td><?= e($run['status']) ?></td><td><?= e($run['exit_code']) ?></td><td><?= e($run['username']) ?></td><td><?= e($run['created_at']) ?></td><td><?= e($run['finished_at']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

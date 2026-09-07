<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Detected Laravel projects</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/artisan.php')) ?>" data-output="#artisanOutput" data-output-format="command">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Project</label>
                            <select class="form-select mb-2" name="project">
                                <?php foreach ($projects as $project): ?><option value="<?= e($project['path']) ?>"><?= e($project['name']) ?> · <?= e($project['path']) ?></option><?php endforeach; ?>
                            </select>
                            <label class="form-label">Artisan command</label>
                            <select class="form-select mb-2" name="command">
                                <?php foreach ($commands['artisan'] as $command): ?><option><?= e($command) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" name="type" value="artisan">Run Artisan</button>
                        </form>
                        <hr>
                        <form class="js-api-form" data-endpoint="<?= e(url('api/artisan.php')) ?>" data-output="#artisanOutput" data-output-format="command">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Project</label>
                            <select class="form-select mb-2" name="project">
                                <?php foreach ($projects as $project): ?><option value="<?= e($project['path']) ?>"><?= e($project['name']) ?></option><?php endforeach; ?>
                            </select>
                            <label class="form-label">Composer command</label>
                            <select class="form-select mb-2" name="command">
                                <?php foreach ($commands['composer'] as $command): ?><option><?= e($command) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary" name="type" value="composer">Run Composer</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Project status</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm data-table">
                            <thead><tr><th>Name</th><th>Path</th><th>.env</th><th>Storage link</th></tr></thead>
                            <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr><td><?= e($project['name']) ?></td><td><code><?= e($project['path']) ?></code></td><td><?= e($project['env']) ?></td><td><?= e($project['storage_link']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <pre class="sys-output" id="artisanOutput"></pre>
            </div>
        </div>
    </div>
</section>

<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Create scheduled task</h2></div>
                    <div class="card-body">
                        <form class="js-api-form" data-endpoint="<?= e(url('api/cron.php')) ?>" data-output="#cronOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <input type="hidden" name="action" value="create">
                            <label class="form-label">Name</label>
                            <input class="form-control mb-2" name="name" required>
                            <label class="form-label">Cron schedule</label>
                            <input class="form-control mb-2" name="schedule" value="*/15 * * * *" required>
                            <label class="form-label">Script</label>
                            <select class="form-select mb-3" name="script">
                                <?php foreach ($scripts as $script): ?><option value="<?= e($script['name']) ?>"><?= e($script['name']) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary">Create task</button>
                        </form>
                    </div>
                </div>
                <pre class="sys-output" id="cronOutput"></pre>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Managed scheduled tasks</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm data-table">
                            <thead><tr><th>Name</th><th>Schedule</th><th>Script</th><th>Enabled</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($managed as $task): ?>
                                <tr>
                                    <td><?= e($task['name']) ?></td>
                                    <td><code><?= e($task['schedule']) ?></code></td>
                                    <td><?= e($task['script_name']) ?></td>
                                    <td><span class="badge bg-<?= $task['enabled'] ? 'success' : 'secondary' ?>"><?= $task['enabled'] ? 'enabled' : 'disabled' ?></span></td>
                                    <td class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary js-cron-action" data-action="run" data-id="<?= e($task['id']) ?>" data-script="<?= e($task['script_name']) ?>">Run now</button>
                                        <button class="btn btn-outline-secondary js-cron-action" data-action="<?= $task['enabled'] ? 'disable' : 'enable' ?>" data-id="<?= e($task['id']) ?>"><?= $task['enabled'] ? 'Disable' : 'Enable' ?></button>
                                        <button class="btn btn-outline-danger js-cron-action" data-action="delete" data-id="<?= e($task['id']) ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">System cron jobs</h2></div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Source</th><th>Line</th></tr></thead>
                            <tbody>
                            <?php foreach ($systemJobs as $job): ?>
                                <tr><td><?= e($job['source']) ?></td><td><code><?= e($job['line']) ?></code></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

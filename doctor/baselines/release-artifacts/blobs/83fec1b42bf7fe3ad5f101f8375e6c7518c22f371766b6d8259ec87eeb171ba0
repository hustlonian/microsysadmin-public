<div class="content-header"><div class="container-fluid"><h1 class="m-0">Processes</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Process monitor</h2></div>
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm data-table">
                    <thead><tr><th>PID</th><th>CPU</th><th>Memory</th><th>Owner</th><th>Name</th><th>Command</th></tr></thead>
                    <tbody>
                    <?php foreach ($processes as $process): ?>
                        <tr>
                            <td><?= e($process['pid']) ?></td>
                            <td><?= e($process['cpu']) ?>%</td>
                            <td><?= e($process['memory']) ?>%</td>
                            <td><?= e($process['owner']) ?></td>
                            <td><?= e($process['name']) ?></td>
                            <td><code><?= e($process['command']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

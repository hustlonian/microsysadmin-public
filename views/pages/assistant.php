<div class="content-header"><div class="container-fluid"><h1 class="m-0">Command Assistant</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="alert alert-secondary">Command Assistant maps common tasks to approved MicroSysAdmin pages and whitelisted commands. It does not execute arbitrary shell input.</div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Approved command map</h2></div>
            <div class="card-body table-responsive">
                <table class="table table-sm data-table">
                    <thead><tr><th>Intent</th><th>Approved command path</th><th>Page</th></tr></thead>
                    <tbody>
                    <?php foreach ($commands as $command): ?>
                        <tr><td><?= e($command['intent']) ?></td><td><code><?= e($command['command']) ?></code></td><td><a href="<?= e(url($command['page'])) ?>"><?= e($command['page']) ?></a></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

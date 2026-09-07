<div class="content-header"><div class="container-fluid"><h1 class="m-0">Services</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Approved service controls</h2></div>
            <div class="card-body table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Service</th><th>Status</th><th>Approved actions</th><th>Output</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $key => $service): ?>
                        <tr>
                            <td><strong><?= e($service['label']) ?></strong><br><code><?= e($key) ?></code></td>
                            <td><span class="badge bg-<?= !empty($states[$key]['running']) ? 'success' : 'secondary' ?>" data-service-state="<?= e($key) ?>"><?= e($states[$key]['label'] ?? 'Unknown') ?></span></td>
                            <td>
                                <div class="service-action-grid">
                                <?php foreach (['status', 'start', 'stop', 'restart', 'reload'] as $action): ?>
                                    <?php $supported = in_array($action, $service['actions'] ?? [], true); ?>
                                    <button
                                        class="btn btn-sm <?= $action === 'stop' ? 'btn-outline-danger' : 'btn-outline-primary' ?> js-service-action"
                                        type="button"
                                        data-service="<?= e($key) ?>"
                                        data-action="<?= e($action) ?>"
                                        <?= $supported ? '' : 'disabled title="Unavailable with the configured service-control driver"' ?>
                                    ><?= e(ucfirst($action)) ?></button>
                                <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Driver: <?= e($service['driver'] ?? 'inspection') ?></small>
                            </td>
                            <td><pre class="sys-output compact" data-service-output="<?= e($key) ?>">Click Status to inspect <?= e($service['label']) ?>.</pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <form class="card card-body mb-3" method="get">
            <div class="row">
                <div class="col-md-5 mb-2">
                    <label class="form-label">Log file</label>
                    <select class="form-select" name="log">
                        <?php foreach ($files as $label => $path): ?><option value="<?= e($label) ?>" <?= $label === $selected ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 mb-2">
                    <label class="form-label">Search</label>
                    <input class="form-control" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="error, warning, IP, path">
                </div>
                <div class="col-md-2 mb-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Filter</button>
                </div>
            </div>
        </form>
        <?php if ($mode === 'log-analyzer'): ?>
            <div class="alert alert-info">
                <strong>Analysis:</strong> <?= e($analysis['summary']) ?>
                <span class="badge bg-danger ms-2"><?= e($analysis['counts']['error']) ?> errors</span>
                <span class="badge bg-warning text-dark"><?= e($analysis['counts']['warning']) ?> warnings</span>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title"><?= e($read['label']) ?></h2><div class="card-tools"><code><?= e($read['path']) ?></code></div></div>
            <div class="card-body">
                <div class="log-window">
                    <?php foreach ($read['rows'] as $row): ?>
                        <div class="log-line log-<?= e($row['level']) ?>"><?= e($row['line']) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

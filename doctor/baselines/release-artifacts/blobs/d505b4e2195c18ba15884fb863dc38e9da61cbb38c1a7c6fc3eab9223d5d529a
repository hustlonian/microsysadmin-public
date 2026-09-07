<div class="content-header"><div class="container-fluid"><h1 class="m-0">Search</h1></div></div>
<section class="content">
    <div class="container-fluid">
        <form class="card card-body mb-3" method="get">
            <label class="form-label">Global search</label>
            <div class="input-group">
                <input class="form-control" name="q" value="<?= e($query) ?>" placeholder="Search files, repositories, databases, logs, scripts">
                <button class="btn btn-primary">Search</button>
            </div>
        </form>
        <div class="row">
            <?php
            $groups = [
                'Files' => $results['files'] ?? [],
                'Repositories' => $results['repos'] ?? [],
                'Databases' => $results['dbs'] ?? [],
                'Logs' => $results['logs'] ?? [],
                'Scripts' => $results['scripts'] ?? [],
            ];
            ?>
            <?php foreach ($groups as $label => $rows): ?>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title"><?= e($label) ?></h2></div>
                        <div class="card-body table-responsive">
                            <table class="table table-sm">
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $primary = (string) ($row['path'] ?? $row['name'] ?? $row['source'] ?? '');
                                    $secondary = (string) ($row['line'] ?? $row['branch'] ?? $row['description'] ?? $row['modified'] ?? '');
                                    $href = '';
                                    if ($label === 'Files' && $primary !== '') {
                                        $folder = dirname($primary);
                                        $href = url('explorer?path=' . rawurlencode($folder) . '&select=' . rawurlencode($primary));
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($href !== ''): ?>
                                                <a class="sys-search-result" href="<?= e($href) ?>"><code><?= e($primary) ?></code></a>
                                            <?php else: ?>
                                                <code><?= e($primary) ?></code>
                                            <?php endif; ?>
                                            <br><span class="small text-muted"><?= e($secondary) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$rows): ?><tr><td class="text-muted">No matches.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

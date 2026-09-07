<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Historical metrics</h2></div>
                    <div class="card-body"><canvas id="analyticsChart" height="130"></canvas></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Series</h2></div>
                    <div class="card-body">
                        <div class="list-group">
                            <a class="list-group-item list-group-item-action <?= $mode === 'analytics-cpu' ? 'active' : '' ?>" href="<?= e(url('analytics-cpu')) ?>">CPU</a>
                            <a class="list-group-item list-group-item-action <?= $mode === 'analytics-memory' ? 'active' : '' ?>" href="<?= e(url('analytics-memory')) ?>">Memory</a>
                            <a class="list-group-item list-group-item-action <?= $mode === 'analytics-disk' ? 'active' : '' ?>" href="<?= e(url('analytics-disk')) ?>">Storage</a>
                            <a class="list-group-item list-group-item-action <?= $mode === 'analytics-traffic' ? 'active' : '' ?>" href="<?= e(url('analytics-traffic')) ?>">Traffic</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

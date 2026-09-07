<div class="content-header"><div class="container-fluid"><h1 class="m-0"><?= e($title) ?></h1></div></div>
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Media conversion</h2></div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-4">ffmpeg</dt><dd class="col-8"><code><?= e($tools['ffmpeg'] ?? 'not installed') ?></code></dd>
                            <dt class="col-4">ffprobe</dt><dd class="col-8"><code><?= e($tools['ffprobe'] ?? 'not installed') ?></code></dd>
                        </dl>
                        <form class="js-api-form" data-endpoint="<?= e(url('api/media.php')) ?>" data-output="#mediaOutput">
                            <input type="hidden" name="_csrf" value="<?= e(\SysAdmin\Csrf::token()) ?>">
                            <label class="form-label">Allowed input file path</label>
                            <input class="form-control mb-2" name="path" placeholder="<?= e((\SysAdmin\EnvironmentConfig::paths('document_roots')[0] ?? '/srv/www') . '/example.mp4') ?>" required>
                            <label class="form-label">Format</label>
                            <select class="form-select mb-3" name="format">
                                <option>mp3</option><option>wav</option><option>mp4</option><option>webm</option><option>gif</option>
                            </select>
                            <button class="btn btn-primary">Convert</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Allowed media roots</h2></div>
                    <div class="card-body">
                        <?php foreach ($roots as $root): ?><div><code><?= e($root) ?></code></div><?php endforeach; ?>
                    </div>
                </div>
                <pre class="sys-output" id="mediaOutput"></pre>
            </div>
        </div>
    </div>
</section>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install MicroSysAdmin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(asset_url('css/sysadmin.css')) ?>">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h1 class="h4 mb-0">Install MicroSysAdmin</h1>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?= \SysAdmin\Csrf::field() ?>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Database host</label>
                                <input class="form-control" name="host" value="127.0.0.1" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port</label>
                                <input class="form-control" name="port" type="number" value="3306" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database name</label>
                            <input class="form-control" name="name" value="microsysadmin" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Database user</label>
                                <input class="form-control" name="user" value="root" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Database password</label>
                                <input class="form-control" name="password" type="password">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Base URL</label>
                            <input class="form-control" name="base_url" value="/microsysadmin" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Install and seed admin user</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>

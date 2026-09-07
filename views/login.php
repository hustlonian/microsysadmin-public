<?php $appName = (string) config_value('app.name', 'MicroSysAdmin'); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="<?= e(asset_url('css/sysadmin.css')) ?>">
</head>
<body class="login-page sysadmin-login">
<main class="login-box">
    <div class="card card-outline card-primary shadow-sm">
        <div class="card-header text-center">
            <h1 class="h3 mb-0"><?= e($appName) ?></h1>
        </div>
        <div class="card-body">
            <?php if (\SysAdmin\DemoMode::enabled()): ?>
                <div class="alert alert-info">
                    <strong>Try the demo</strong><br>
                    Username: <code><?= e(\SysAdmin\DemoAccount::USERNAME) ?></code><br>
                    Password: <code><?= e(\SysAdmin\DemoAccount::PASSWORD) ?></code><br>
                    <small>Explore sample data. Server actions are simulated.</small>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['timeout'])): ?>
                <div class="alert alert-warning">Your session expired after being idle.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($mfaRequired)): ?>
                <form method="post" action="<?= e(url('login')) ?>">
                    <?= \SysAdmin\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="mfa_code">Authenticator or recovery code</label>
                        <input class="form-control" id="mfa_code" name="mfa_code" pattern="[A-Za-z0-9-]{6,20}" autocomplete="one-time-code" required autofocus>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Verify</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('login')) ?>">
                    <?= \SysAdmin\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" name="username" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <span class="text-muted small">Idle timeout protected</span>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>

<?php $title = 'Change Password'; $active = 'settings'; ?>
<div class="content-header">
    <div class="container-fluid">
        <h1 class="m-0">Change password</h1>
    </div>
</div>
<section class="content">
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Required password change</h2></div>
                    <div class="card-body">
                        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?> <a href="<?= e(url('dashboard')) ?>">Continue to dashboard</a>.</div><?php endif; ?>
                        <form method="post">
                            <?= \SysAdmin\Csrf::field() ?>
                            <div class="mb-3">
                                <label class="form-label">New password</label>
                                <input class="form-control" name="password" type="password" minlength="<?= e(config_value('security.password_min_length', 12)) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm password</label>
                                <input class="form-control" name="password_confirmation" type="password" minlength="<?= e(config_value('security.password_min_length', 12)) ?>" required>
                            </div>
                            <button class="btn btn-primary" type="submit">Update password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

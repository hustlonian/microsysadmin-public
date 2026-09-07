<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\Csrf;
use SysAdmin\Database;
use SysAdmin\View;

final class InstallerController
{
    public function handle(): void
    {
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $config = require APP_ROOT . '/config/config.sample.php';
            $config['database'] = [
                'host' => trim((string) ($_POST['host'] ?? '127.0.0.1')),
                'port' => (int) ($_POST['port'] ?? 3306),
                'name' => trim((string) ($_POST['name'] ?? 'microsysadmin')),
                'user' => trim((string) ($_POST['user'] ?? 'root')),
                'password' => (string) ($_POST['password'] ?? ''),
                'charset' => 'utf8mb4',
            ];
            $config['app']['base_url'] = trim((string) ($_POST['base_url'] ?? '/microsysadmin')) ?: '/microsysadmin';
            $config['security']['app_key'] = bin2hex(random_bytes(32));

            try {
                $GLOBALS['sysadmin_config'] = $config;
                $GLOBALS['sysadmin_config_exists'] = true;
                Database::init($config);
                $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
                file_put_contents(APP_ROOT . '/config/config.php', $export, LOCK_EX);
                redirect_to('login');
            } catch (\Throwable $exception) {
                $GLOBALS['sysadmin_config_exists'] = false;
                $error = $exception->getMessage();
            }
        }

        View::render('installer', ['error' => $error], null);
    }
}

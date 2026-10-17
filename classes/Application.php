<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Controllers\AuthController;
use SysAdmin\Controllers\DashboardController;
use SysAdmin\Controllers\InstallerController;
use SysAdmin\Controllers\PageController;

final class Application
{
    public function handle(): void
    {
        if (is_file(STORAGE_PATH . '/maintenance.flag') && PHP_SAPI !== 'cli') {
            http_response_code(503);
            header('Retry-After: 60');
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>MicroSysAdmin maintenance</title><body><h1>MicroSysAdmin is being updated</h1><p>Try again shortly.</p></body></html>';
            return;
        }

        if (!($GLOBALS['sysadmin_config_exists'] ?? false)) {
            (new InstallerController())->handle();
            return;
        }

        $path = current_path();
        if (\SysAdmin\DemoMode::enabled() && (
            $path === 'agent/v1' || str_starts_with($path, 'agent/v1/')
            || $path === 'api/v1' || str_starts_with($path, 'api/v1/')
        )) {
            json_response(['success' => false, 'error' => ['message' => 'External APIs are disabled in demonstration mode.']], 404);
        }
        if ($path === 'agent/v1' || str_starts_with($path, 'agent/v1/')) {
            if (!(bool) config_value('agent.enabled', true)) {
                json_response(['success' => false, 'error' => ['message' => 'Agent API is disabled.']], 404);
            }
            (new \SysAdmin\Controllers\AgentController())->handle($path);
        }
        if ($path === 'api/v1' || str_starts_with($path, 'api/v1/')) {
            (new \SysAdmin\Controllers\ProductApiController())->handle($path);
        }
        if ($path === 'login') {
            (new AuthController())->login();
            return;
        }

        if ($path === 'logout') {
            Csrf::verify();
            Auth::logout();
            redirect_to('login');
        }

        Auth::requireLogin();

        if (\SysAdmin\DemoMode::enabled() && $path === 'change-password') {
            http_response_code(403);
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>MicroSysAdmin demonstration</title><body><h1>Demonstration mode</h1><p>Password changes are disabled in the public demonstration.</p></body></html>';
            return;
        }

        if ($path === 'change-password') {
            (new AuthController())->changePassword();
            return;
        }

        $user = Auth::user();
        if ($user && (int) $user['force_password_change'] === 1 && $path !== 'settings') {
            redirect_to('change-password');
        }

        if ($path === 'development-command-center' || str_starts_with($path, 'development-command-center/')) {
            (new \SysAdmin\Controllers\DevelopmentController())->handle($path);
            return;
        }

        if (str_starts_with($path, 'workspace-preview/')) {
            if (\SysAdmin\DemoMode::enabled()) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Workspace preview is disabled in demonstration mode.';
                return;
            }
            if (!Auth::can('command.execute')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Permission denied.';
                return;
            }

            (new SandboxService())->sendWebFile(substr($path, strlen('workspace-preview/')));
        }

        $slug = $path === '' ? 'dashboard' : $path;
        if ($slug === 'dashboard') {
            (new DashboardController())->index();
            return;
        }

        (new PageController())->show($slug);
    }
}

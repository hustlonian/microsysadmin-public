<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\Auth;
use SysAdmin\DevelopmentCommandCenter;
use SysAdmin\HttpException;
use SysAdmin\View;

final class DevelopmentController
{
    public function handle(string $path): void
    {
        Auth::requireLogin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new HttpException(405, 'This development view is read-only.');
        }
        $service = new DevelopmentCommandCenter();
        $project = $_GET['project'] ?? '';
        if (!is_string($project)) {
            throw new HttpException(422, 'Invalid project.');
        }
        $view = $_GET['view'] ?? 'overview';
        if (!is_string($view) || !in_array($view, ['overview','history','working','roadmap','gates','evidence','reports'], true)) {
            throw new HttpException(422, 'Invalid development view.');
        }
        $data = ['title' => 'Development Command Center', 'active' => 'development-command-center',
            'navigation' => require APP_ROOT . '/config/navigation.php', 'projects' => $service->projects(),
            'selectedProject' => $project, 'tab' => $view, 'report' => null, 'partial' => ($_GET['partial'] ?? '') === '1'];
        header('Cache-Control: private, no-store');
        if ($project !== '') {
            $data['report'] = $service->filter($service->report($project), $_GET);
        }
        if ($path !== 'development-command-center') {
            if ($project === '') {
                throw new HttpException(422, 'A project is required.');
            }
            header('X-Content-Type-Options: nosniff');
            if ($path === 'development-command-center/snapshot' || $path === 'development-command-center/export.json') {
                header('Content-Type: application/json; charset=utf-8');
                if (str_ends_with($path, 'export.json')) {
                    header('Content-Disposition: attachment; filename="development-report.json"');
                }
                echo json_encode($data['report'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
                return;
            }
            if ($path === 'development-command-center/export.md') {
                header('Content-Type: text/markdown; charset=utf-8');
                header('Content-Disposition: attachment; filename="development-report.md"');
                echo $service->markdown($data['report']);
                return;
            }
            throw new HttpException(404, 'Development endpoint not found.');
        }
        View::render('pages/development-command-center', $data, $data['partial'] ? null : 'layout');
    }
}

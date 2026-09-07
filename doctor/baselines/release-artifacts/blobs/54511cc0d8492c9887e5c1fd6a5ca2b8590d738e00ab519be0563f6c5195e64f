<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\Auth;
use SysAdmin\AgentManager;
use SysAdmin\View;

final class DashboardController
{
    public function index(): void
    {
        Auth::requirePermission('dashboard.view');
        View::render('pages/dashboard', [
            'title' => 'Dashboard',
            'active' => 'dashboard',
            'metrics' => AgentManager::client()->request('metrics'),
            'hosting' => AgentManager::client()->request('websites.list', ['include_sizes' => false]),
        ]);
    }
}

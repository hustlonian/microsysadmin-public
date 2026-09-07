<?php

declare(strict_types=1);

return [
    'services' => (new \SysAdmin\ServiceControlManager())->definitions(),
    'system' => [
        'df' => ['df', '-hT'],
        'mounts' => ['findmnt'],
        'ports' => ['ss', '-ltnp'],
        'network' => ['ip', '-brief', 'address'],
        'routes' => ['ip', 'route'],
        'apache_version' => [\SysAdmin\EnvironmentConfig::path('xampp_root') . '/bin/httpd', '-v'],
        'mysql_version' => [\SysAdmin\EnvironmentConfig::path('mysql_binary'), '--version'],
        'composer_version' => [\SysAdmin\EnvironmentConfig::path('composer_binary'), '--version'],
        'php_modules' => [\SysAdmin\EnvironmentConfig::path('php_binary'), '-m'],
    ],
    'artisan' => [
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
        'storage:link',
        'queue:restart',
        'optimize',
        'migrate',
    ],
    'composer' => [
        'install',
        'update',
        'dump-autoload',
        'audit',
        'show',
    ],
];

<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

SysAdmin\Database::migrate();
SysAdmin\Database::seed();
echo "MicroSysAdmin database is installed and seeded.\n";

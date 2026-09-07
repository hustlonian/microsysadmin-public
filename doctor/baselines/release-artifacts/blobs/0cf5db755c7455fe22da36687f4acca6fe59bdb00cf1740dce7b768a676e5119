<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$database = $argv[1] ?? (string) config_value('database.name', 'microsysadmin');
$result = (new SysAdmin\BackupService())->database($database);
echo 'Backup complete: ' . ($result['archive'] ?? '') . PHP_EOL;

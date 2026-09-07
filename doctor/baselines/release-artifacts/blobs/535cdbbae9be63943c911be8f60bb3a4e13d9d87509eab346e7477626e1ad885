<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

\SysAdmin\Auth::requireLogin();
(new \SysAdmin\Controllers\PageController())->show('doctor');

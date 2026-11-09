<?php
require __DIR__ . '/bootstrap.php';
(new SysAdmin\Controllers\ApiController())->handle('owner-setup');

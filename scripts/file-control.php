<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');

$configPath = APP_ROOT . '/config/config.php';
$GLOBALS['sysadmin_config_exists'] = false;
$GLOBALS['sysadmin_config'] = is_file($configPath)
    ? require $configPath
    : require APP_ROOT . '/config/config.sample.php';

date_default_timezone_set($GLOBALS['sysadmin_config']['app']['timezone'] ?? 'UTC');

require APP_ROOT . '/classes/helpers.php';
require APP_ROOT . '/classes/PathGuard.php';
require APP_ROOT . '/classes/AuditLogger.php';
require APP_ROOT . '/classes/FileVault.php';

use SysAdmin\FileVault;

function file_control_response(array $payload, int $status = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($status);
}

try {
    $action = (string) ($argv[1] ?? '');
    $encodedPayload = (string) ($argv[2] ?? '');
    $json = base64_decode($encodedPayload, true);
    if ($action === '' || $json === false) {
        throw new InvalidArgumentException('Invalid file helper request.');
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid file helper payload.');
    }

    $vault = new FileVault(!empty($payload['_allow_sites']));
    $data = match ($action) {
        'read' => $vault->read((string) ($payload['path'] ?? '')),
        'upload' => ['path' => $vault->installStagedUpload((string) ($payload['source'] ?? ''), (string) ($payload['directory'] ?? ''), (string) ($payload['name'] ?? ''))],
        'create-folder' => ['path' => $vault->createFolder((string) ($payload['path'] ?? ''), (string) ($payload['name'] ?? ''))],
        'create-file' => ['path' => $vault->createFile((string) ($payload['path'] ?? ''), (string) ($payload['name'] ?? ''), (string) ($payload['content'] ?? ''))],
        'save-file' => ['path' => $vault->saveFile((string) ($payload['path'] ?? ''), (string) ($payload['content'] ?? ''))],
        'rename' => ['path' => $vault->rename((string) ($payload['path'] ?? ''), (string) ($payload['name'] ?? ''))],
        'copy' => ['path' => $vault->copy((string) ($payload['path'] ?? ''), (string) ($payload['target'] ?? ''))],
        'move' => ['path' => $vault->move((string) ($payload['path'] ?? ''), (string) ($payload['target'] ?? ''))],
        'delete' => ['trash' => $vault->delete((string) ($payload['path'] ?? ''))],
        'chmod' => $vault->changePermissions((string) ($payload['path'] ?? ''), (string) ($payload['mode'] ?? '')),
        'zip' => ['archive' => $vault->zip((string) ($payload['path'] ?? ''))],
        'unzip' => ['target' => $vault->unzip((string) ($payload['path'] ?? ''), (string) ($payload['target'] ?? ''))],
        default => throw new InvalidArgumentException('Unknown file helper action.'),
    };

    file_control_response(['ok' => true, 'data' => $data]);
} catch (Throwable $exception) {
    file_control_response(['ok' => false, 'error' => $exception->getMessage()], 1);
}

<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

if (($argv[1] ?? '') !== 'rotate') {
    fwrite(STDERR, "Usage: php scripts/agent-credential.php rotate\n");
    exit(64);
}

$credential = (new SysAdmin\AgentCredentialService())->rotate();
echo "Machine UUID: {$credential['machine_uuid']}\n";
echo "Key ID: {$credential['key_id']}\n";
echo "Secret (shown once): {$credential['secret']}\n";

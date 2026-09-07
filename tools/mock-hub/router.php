<?php

declare(strict_types=1);

// Development contract mock only. Never use this router as a production hub.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
$statePath = sys_get_temp_dir() . '/microsysadmin-mock-hub-state.json';
$state = is_file($statePath) ? (json_decode((string) file_get_contents($statePath), true) ?: []) : [];

if ($path === '/v1/machines/enroll') {
    $expected = getenv('MICROSYSADMIN_MOCK_ENROLLMENT_TOKEN') ?: 'microsysadmin-development-enrollment';
    if (!hash_equals($expected, (string) ($payload['enrollment_token'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid enrollment token']);
        exit;
    }
    $machineId = 'mock_' . bin2hex(random_bytes(10));
    $credential = 'mhc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $state[$machineId] = ['credential' => $credential, 'heartbeats' => []];
    file_put_contents($statePath, json_encode($state), LOCK_EX);
    echo json_encode(['machine_id' => $machineId, 'credential' => $credential]);
    exit;
}

$machineId = (string) ($_SERVER['HTTP_X_MICROSYSADMIN_MACHINE'] ?? '');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$credential = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
if (!isset($state[$machineId]) || !hash_equals((string) $state[$machineId]['credential'], $credential)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid machine credential']);
    exit;
}

if ($path === '/v1/ping') {
    echo json_encode(['ok' => true, 'mock' => true]);
    exit;
}
if ($path === '/v1/machines/credential/rotate') {
    $credential = 'mhc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $state[$machineId]['credential'] = $credential;
    file_put_contents($statePath, json_encode($state), LOCK_EX);
    echo json_encode(['ok' => true, 'credential' => $credential]);
    exit;
}
if ($path === '/v1/machines/heartbeat') {
    $state[$machineId]['heartbeats'][] = $payload;
    $state[$machineId]['heartbeats'] = array_slice($state[$machineId]['heartbeats'], -10);
    file_put_contents($statePath, json_encode($state), LOCK_EX);
    echo json_encode(['ok' => true, 'received_at' => gmdate(DATE_ATOM), 'mock' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);

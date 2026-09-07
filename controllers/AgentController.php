<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\AgentRequestAuthenticator;
use SysAdmin\HttpException;
use SysAdmin\LocalMachineAgent;
use SysAdmin\MachineIdentity;
use SysAdmin\RequestContext;
use SysAdmin\Security;

final class AgentController
{
    public function handle(string $path): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respond(false, $path, RequestContext::requestId(), null, null, 'POST is required.', 405);
        }

        $raw = (string) file_get_contents('php://input');
        $authenticator = new AgentRequestAuthenticator();
        $request = null;
        try {
            $request = $authenticator->authenticate('POST', '/' . trim($path, '/'), $raw);
            $payload = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Agent payload must be a JSON object.');
            }

            $action = str_replace('/', '.', trim(substr($path, strlen('agent/v1')), '/'));
            if ($action === '') {
                throw new \InvalidArgumentException('Agent action is required.');
            }
            $result = (new LocalMachineAgent())->execute($action, $payload, $request);
            $authenticator->complete((string) $request['request_id'], 'completed');
            $this->respond(true, $action, (string) $request['request_id'], $result);
        } catch (\Throwable $exception) {
            if (is_array($request) && isset($request['request_id'])) {
                $authenticator->complete((string) $request['request_id'], 'failed');
            }
            $status = $exception instanceof HttpException ? $exception->statusCode() : 400;
            $message = Security::productionSafeMessage($exception, 'Agent request failed.');
            $this->respond(
                false,
                str_replace('/', '.', trim(substr($path, strlen('agent/v1')), '/')),
                (string) ($request['request_id'] ?? RequestContext::requestId()),
                null,
                null,
                $message,
                $status
            );
        }
    }

    private function respond(
        bool $success,
        string $action,
        string $requestId,
        mixed $result = null,
        ?string $safeCode = null,
        ?string $safeMessage = null,
        int $status = 200
    ): never {
        $identity = (new MachineIdentity())->get();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => $success,
            'action' => $action,
            'machine_id' => $identity['machine_uuid'],
            'request_id' => $requestId,
            'requested_at' => gmdate(DATE_ATOM),
            'completed_at' => gmdate(DATE_ATOM),
            'result' => $result,
            'error' => $success ? null : [
                'code' => $safeCode ?? 'agent_request_failed',
                'message' => $safeMessage ?? 'Agent request failed.',
            ],
            'audit_correlation_id' => RequestContext::auditCorrelationId(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

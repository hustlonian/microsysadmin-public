<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\AgentManager;
use SysAdmin\ApiTokenService;
use SysAdmin\AuditLogger;
use SysAdmin\CommercialContext;
use SysAdmin\Database;
use SysAdmin\HttpException;
use SysAdmin\JobQueue;
use SysAdmin\MachineIdentity;
use SysAdmin\RateLimiter;
use SysAdmin\RequestContext;
use SysAdmin\Security;

final class ProductApiController
{
    public function handle(string $path): never
    {
        try {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (!str_starts_with($authorization, 'Bearer ')) {
                throw new HttpException(401, 'Bearer API token is required.');
            }
            $tokens = new ApiTokenService();
            $token = $tokens->authenticate(substr($authorization, 7));
            RateLimiter::hit(
                'product-api:' . $token['id'],
                (string) $token['id'],
                (int) $token['rate_limit'],
                60
            );
            if ((int) $token['organization_id'] !== CommercialContext::organizationId()) {
                throw new HttpException(403, 'Organization access denied.');
            }

            $resource = trim(substr($path, strlen('api/v1')), '/');
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $result = $this->route($method, $resource, $body, $token, $tokens);
            AuditLogger::info('product_api.request', [
                'token_id' => $token['id'],
                'method' => $method,
                'resource' => $resource,
            ]);
            $this->respond(true, $result);
        } catch (\Throwable $exception) {
            $status = $exception instanceof HttpException ? $exception->statusCode() : 400;
            $this->respond(false, null, Security::productionSafeMessage($exception, 'API request failed.'), $status);
        }
    }

    private function route(string $method, string $resource, array $body, array $token, ApiTokenService $tokens): mixed
    {
        if ($method === 'GET') {
            return match ($resource) {
                'machine/health' => $this->scoped($tokens, $token, 'machine.health:read', fn () => AgentManager::client()->request('health')),
                'services' => $this->scoped($tokens, $token, 'services.status:read', fn () => AgentManager::client()->request('services.status')),
                'projects' => $this->scoped($tokens, $token, 'projects.inventory:read', fn () => AgentManager::client()->request('websites.list', ['include_sizes' => false])),
                'backups' => $this->scoped($tokens, $token, 'backups.status:read', fn () => AgentManager::client()->request('backups.list')),
                'version' => $this->scoped($tokens, $token, 'version:read', fn () => [
                    'version' => MachineIdentity::version(),
                    'machine_id' => (new MachineIdentity())->get()['machine_uuid'],
                ]),
                'audit/summary' => $this->scoped($tokens, $token, 'audit.summary:read', fn () => $this->auditSummary()),
                default => throw new HttpException(404, 'API resource not found.'),
            };
        }

        if ($method === 'POST' && $resource === 'health-checks') {
            return $this->scoped($tokens, $token, 'health:run', fn () => AgentManager::client()->request('health'));
        }
        if ($method === 'POST' && $resource === 'backups') {
            return $this->scoped($tokens, $token, 'backups:write', function () use ($body): array {
                $type = (string) ($body['type'] ?? 'database');
                return match ($type) {
                    'database' => (new JobQueue())->dispatch('backup.database', ['database' => (string) ($body['database'] ?? '')]),
                    'folder' => (new JobQueue())->dispatch('backup.folder', ['path' => (string) ($body['path'] ?? '')]),
                    default => throw new \InvalidArgumentException('Only approved database and folder backup jobs are supported.'),
                };
            });
        }

        throw new HttpException(404, 'API resource not found.');
    }

    private function scoped(ApiTokenService $service, array $token, string $scope, callable $callback): mixed
    {
        $service->requireScope($token, $scope);
        return $callback();
    }

    private function auditSummary(): array
    {
        $organizationId = CommercialContext::organizationId();
        $statement = Database::pdo()->prepare(
            'SELECT level, action, COUNT(*) AS occurrences, MAX(created_at) AS last_seen_at
             FROM audit_logs
             WHERE organization_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY level, action
             ORDER BY occurrences DESC
             LIMIT 50'
        );
        $statement->execute([$organizationId]);
        return ['window_days' => 7, 'events' => $statement->fetchAll()];
    }

    private function respond(bool $success, mixed $data = null, ?string $error = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => $success,
            'request_id' => RequestContext::requestId(),
            'timestamp' => gmdate(DATE_ATOM),
            'data' => $data,
            'error' => $success ? null : ['message' => $error],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

# Local machine agent API v1

The agent boundary is used locally through `AgentClientInterface`. The default `InProcessAgentClient` avoids HTTP overhead on a single machine. `SignedHttpAgentClient` can call the same actions through:

```text
POST {base_url}/agent/v1/{action/path}
```

The agent endpoint is disabled by `agent.enabled=false` and entirely unavailable in demo mode. It does not accept browser sessions or anonymous localhost traffic.

## Credentials and signing

Create or rotate a credential locally:

```bash
/opt/lampp/bin/php scripts/agent-credential.php rotate
```

The secret is shown once and stored encrypted. Rotation revokes the previous active credential.

Required headers:

```text
Content-Type: application/json
X-MicroSysAdmin-Machine: MACHINE-UUID
X-MicroSysAdmin-Key-ID: agk_...
X-MicroSysAdmin-Request-ID: unique-client-request-id
X-MicroSysAdmin-Timestamp: UNIX-SECONDS
X-MicroSysAdmin-Signature: v1=HEX-HMAC
```

Canonical value:

```text
UPPERCASE_METHOD
/agent/v1/action/path
UNIX_TIMESTAMP
REQUEST_ID
SHA256_HEX_OF_RAW_BODY
```

`X-MicroSysAdmin-Signature` is `v1=` plus HMAC-SHA256 of that canonical value using the agent secret. Timestamps may differ by at most 300 seconds. A request ID is inserted before execution and cannot be replayed.

## Response

```json
{
  "success": true,
  "action": "machine.metadata",
  "machine_id": "uuid",
  "request_id": "client-id",
  "requested_at": "ISO-8601",
  "completed_at": "ISO-8601",
  "result": {},
  "error": null,
  "audit_correlation_id": "aud_..."
}
```

Errors use the same envelope with a safe code/message and no command line, stack trace, credential, or sensitive payload.

## Actions

Read actions:

- `health`, `metrics`, `processes`, `machine/metadata`, `environment/status`;
- `services/status`;
- `files/list`, `files/read`, `files/preview`, `files/search`;
- `databases/list`, `databases/tables`, `databases/rows`;
- `git/repositories`, `git/detail`;
- `backups/list`;
- `scheduled-tasks/list`, `scripts/list`;
- `logs/files`, `logs/read`, `logs/analyze`;
- `websites/list`;
- `terminal/status`.

Mutation actions supported by the internal contract:

- `services/action`;
- `files/save`, `files/delete`;
- `git/action`, `composer/run`, `artisan/run`;
- `backups/database`, `backups/folder`, `backups/restore`;
- `scheduled-tasks/create|enable|disable|delete`;
- `scripts/run`;
- `websites/create|delete`;
- `notifications/test`.

Terminal session actions exist internally but `/agent/v1` rejects them unless `agent.allow_terminal`, the security profile, environment, role, and entitlement all permit access. Keep this setting false for commercial deployments.

The agent contract is broader than the external product API. Do not give agent credentials to third-party integrations.

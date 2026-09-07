# Product API v1

Base path: `/api/v1`. OpenAPI source: `docs/openapi.yaml`.

Create a token in Administration → API tokens. The plaintext begins `lpt_` and is shown once. MicroSysAdmin stores SHA-256, prefix, organization, owner, scopes, expiry, revocation, last use, and per-minute rate limit.

```http
Authorization: Bearer lpt_PREFIX_SECRET
```

Read endpoints:

| Method and path | Scope |
|---|---|
| `GET /api/v1/machine/health` | `machine.health:read` |
| `GET /api/v1/services` | `services.status:read` |
| `GET /api/v1/projects` | `projects.inventory:read` |
| `GET /api/v1/backups` | `backups.status:read` |
| `GET /api/v1/version` | `version:read` |
| `GET /api/v1/audit/summary` | `audit.summary:read` |

Approved triggers:

| Method and path | Scope |
|---|---|
| `POST /api/v1/health-checks` | `health:run` |
| `POST /api/v1/backups` | `backups:write` |

A backup body is either `{"type":"database","database":"approved_name"}` or `{"type":"folder","path":"/allowed/root"}`. It creates a durable job.

Responses include success, request ID, timestamp, data, and a safe error. Tokens are organization checked, rate limited, audited, expirable, and revocable.

The product API intentionally has no raw command, terminal, arbitrary SQL, unrestricted file mutation, license mutation, user administration, or update-apply endpoint.

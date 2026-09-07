# Architecture

## Runtime layers

```text
Browser UI / constrained product API
                 |
                 v
Controllers and application services
  authorization, entitlements, jobs, audit, events
                 |
                 v
AgentClientInterface / MachineAgentInterface
  in-process local client or signed HTTP client
                 |
                 v
Operation contracts and drivers
 files, database, Git, Composer, Artisan, backup,
 logs, scheduler, sites, terminal, metrics, services
                 |
                 v
Configured Linux / XAMPP / MySQL / filesystem
```

Controllers no longer need to know whether a metric, service state, or approved operation comes from the local process or a future remote agent. `AgentManager` selects the demo agent when demo mode is active, otherwise the current in-process local agent. `SignedHttpAgentClient` and `/agent/v1` provide the same contract over authenticated HTTP.

## Configuration and machine identity

`EnvironmentConfig` is the only source of operational paths. Values come from detected defaults, `config/config.php`, then database administrator overrides. `/opt/lampp` remains a compatibility default, not an exclusive requirement.

`MachineIdentity` stores random v4 machine and installation UUIDs outside the public application directory. Runtime metadata is joined to those persistent fields. The machine UUID does not depend on MAC, IP, hostname, or current XAMPP path.

## Commercial context

One installation currently boots one local organization and maps existing users and records into it. `CommercialContext` resolves organization and machine IDs. Organization-scoped repositories and API authorization use those IDs. Tables support organizations, members, machines, groups, invitations, plans, licenses, entitlements, and support state.

`LicensingServiceInterface` separates entitlement decisions from storage or a future licensing service. A licensing outage disables premium/network actions but preserves essential owner access. Update eligibility is based on the release date and `updates.until`; installed versions keep running.

## Agent security

The local HTTP agent requires:

- machine UUID and key ID;
- timestamp within five minutes;
- unique request ID;
- HMAC-SHA256 over method, path, timestamp, request ID, and body hash;
- an active encrypted-at-rest credential;
- replay insertion into `agent_requests`.

Responses contain success, action, machine ID, request ID, timestamps, result or safe error, and audit correlation ID. Terminal calls are denied unless the installation profile, license, environment, and `agent.allow_terminal` all allow them.

## Jobs, events, and observability

Long work can enter the database queue with type, payload, status, progress, attempts, timeout, owner, machine, organization, output, timestamps, and audit correlation. The worker reserves rows transactionally and applies bounded retry backoff.

Application events contain an allow-listed event type and sanitized payload. Enabled webhook subscriptions create delivery rows and durable delivery jobs. Request, agent, command, backup, job, update, event, and heartbeat identifiers join logs and audits.

## Updates

An update provider returns a versioned manifest. The manager validates semantic version/channel, SHA-256, RSA-SHA256 or Ed25519 signature, and update entitlement. The CLI creates application and database snapshots, enters maintenance mode, rejects unsafe archive paths, stages and synchronizes files while preserving customer configuration/storage, migrates, applies an installation-critical health gate, and records history. Failure attempts automatic rollback.

## Hub boundary

The local machine initiates enrollment and HTTPS requests. The heartbeat builder adds only enabled summary categories. It never includes credentials, private keys, environment files, source/file contents, SQL contents, or terminal output. The included mock validates the contract only; a fleet hub remains a separate product.

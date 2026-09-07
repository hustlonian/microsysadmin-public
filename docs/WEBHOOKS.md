# Webhooks

Webhooks are optional and created disabled. An administrator chooses an HTTPS endpoint and event types, stores the one-time secret securely, then enables delivery.

Events:

- `machine.health_changed`;
- `service.failed`;
- `backup.completed`, `backup.failed`;
- `update.available`, `update.completed`, `update.failed`;
- `disk.threshold_exceeded`;
- `login.lockout`;
- `site.provisioned`, `site.provisioning_failed`;
- `scheduled_task.failed`;
- `license.entitlement_changed`.

Delivery headers:

```text
X-MicroSysAdmin-Delivery: DELIVERY-UUID
X-MicroSysAdmin-Timestamp: UNIX-SECONDS
X-MicroSysAdmin-Signature: v1=HMAC-SHA256
```

The signature input is `timestamp + "." + raw_body`. Consumers should reject timestamps older than five minutes, compare the HMAC in constant time, and remember delivery UUIDs to prevent replay.

Payload keys suggesting passwords, secrets, tokens, credentials, private data, file contents, SQL, or source are removed before storage. Endpoint validation requires HTTPS and blocks credential-bearing or private/reserved targets by default. Deliveries use bounded connect/total timeouts and database-backed retries with history.

The application must run the job worker for asynchronous webhook delivery.

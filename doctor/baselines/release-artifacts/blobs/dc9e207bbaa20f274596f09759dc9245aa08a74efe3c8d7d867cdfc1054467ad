# Optional hub connection

MicroSysAdmin works fully with no hub. When enabled and entitled, the local installation makes outbound HTTPS calls; customers do not expose MicroSysAdmin or its terminal publicly.

## Enrollment

In Administration → Hub, enter:

- HTTPS hub URL;
- remote organization ID;
- single-use machine enrollment token;
- approved data-sharing categories.

The installer also accepts:

```bash
./install.sh \
  --hub-url=https://hub.example.com \
  --hub-organization=org_123 \
  --hub-token=ONE_TIME_TOKEN
```

Enrollment sends machine UUID, installation UUID, display name, hostname, OS, architecture, MicroSysAdmin version, and PHP version. The returned machine credential is encrypted locally. Administrators can test the connection, rotate credentials, send a heartbeat, or disconnect.

## Heartbeat allow-list

Always sent: machine UUID, MicroSysAdmin version, and send timestamp.

Individually optional:

- health-check summaries;
- running/label service summaries;
- CPU/RAM/disk/network numeric summaries;
- latest backup type/status/progress/times;
- update version/channel;
- last activity time.

Never sent:

- passwords, private keys, enrollment or machine credentials;
- database connection credentials or environment files;
- source code, file content/paths, SQL contents;
- terminal or command output.

The mock in `tools/mock-hub/router.php` validates the development contract only. It has no production identity, tenant, billing, retention, scaling, or security controls and must never be presented as the hosted hub.

## Future hosted hub

A real hub must implement enrollment-token lifecycle, organization isolation, credential rotation, request authentication, replay protection, retention/consent controls, audit/export/deletion, regional availability, monitoring, and fleet UI. It can be built separately because MicroSysAdmin already depends on `HubClientInterface`.

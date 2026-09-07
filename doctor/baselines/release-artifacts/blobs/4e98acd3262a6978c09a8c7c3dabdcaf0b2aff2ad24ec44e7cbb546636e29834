# Entitlements

Application code asks the licensing service for a capability key. It does not branch on plan names.

| Entitlement | Type | Meaning |
|---|---|---|
| `machines.max` | integer / `unlimited` / `configurable` | canonical licensed machine allowance |
| `machine.max` | integer / `unlimited` / `configurable` | backward-compatible machine allowance alias |
| `users.max` | integer / `unlimited` | active MicroSysAdmin users |
| `terminal.enabled` | boolean | terminal may be enabled if the profile/environment also allow |
| `code_runner.enabled` | boolean | Code Runner may be enabled if the profile also allows |
| `notifications.enabled` | boolean | SMTP notifications and test delivery |
| `backups.scheduled` | boolean | managed scheduled backups |
| `audit.extended` | boolean | extended audit retention/history |
| `hub.enabled` | boolean | enrollment and heartbeat |
| `branding.enabled` | boolean | organization branding controls |
| `support.priority` | boolean | priority support status |
| `updates.until` | date / `unlimited` / `community` | newest eligible release date |

Plan defaults are stored in `plan_entitlements`; a particular license can override a key through `license_entitlements`. Unknown or unavailable entitlements use the caller's safe default.

Profile, permission, environment, and entitlement checks are cumulative:

```text
available = role permits
         AND security profile permits
         AND environment supports
         AND license entitlement permits
```

An entitlement never grants a role permission and cannot make an unsupported host operation safe.

# Licensing behavior

Licensing is an entitlement service, not a remote lockout mechanism. The local service reads cached/local license rows and plan entitlements. A future remote provider can implement `LicensingServiceInterface`.

## Editions

- **Community:** one machine and Administrator; basic dashboard/health; read-only project discovery and logs; limited files; community updates.
- **Developer:** one licensed machine; current single-machine project, database, Git, Composer, Artisan, backup, script, scheduler, and explicitly enabled terminal features; one year of commercial updates.
- **Studio:** up to five machines; multiple users; roles; extended audit; scheduled backups; notifications; commercial team use.
- **Agency:** configurable machines; organization/client grouping; central summaries; branding; priority support; future hub features.
- **Development:** local testing only, unlimited test entitlements, never a saleable edition.

The current schema and UI display these plans. Enforcement is active for terminal, scheduled backups, hub access, user limits, and update dates. The hosted checkout, license issuance/refresh server, billing, and fleet allowance reconciliation are not included.

## Failure and expiry

- If licensing data is unavailable, essential local server-management access remains.
- Premium/network capabilities fail safely.
- No endpoint can remotely disable an installed MicroSysAdmin.
- An expired update entitlement permits continued use of the installed version.
- A release is eligible when its immutable `released_at` is on or before `updates.until`.
- Cached license status should be shown with its last verification time.

Development mode is controlled by local configuration and must be disabled in customer builds.

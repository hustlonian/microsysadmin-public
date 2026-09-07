# MicroSysAdmin Doctor integration

Doctor is an application subsystem, not a separate framework. It shares the
MicroSysAdmin bootstrap, database connection, authenticated session, RBAC,
CSRF protection, request IDs, audit facilities, URL helpers, view layout, and
environment configuration.

## Integration points

- `classes/bootstrap.php` loads the `SysAdmin\Doctor` namespace from `doctor/`.
- `config/navigation.php` exposes the authenticated Doctor workspace.
- `controllers/PageController.php` renders Doctor data through the shared view.
- `controllers/ApiController.php` authorizes and dispatches Doctor actions.
- `database/migrations/` persists executions, approvals, reports, and audit events.
- `doctor/bin/doctor` boots the same engine used by the web application.
- `storage/doctor/` stores execution locks, filesystem backups, and generated reports.

Repair logic is prohibited in controllers, views, JavaScript, and command
dispatchers. Those layers call `DoctorApplication`, which delegates to the
registry, engine, policy, execution, verification, rollback, and reporting
services.

## Extension boundary

Modules are discovered from `doctor/modules/*/module.json`. Independent PHP
classes and declarative library units under a module's `Checks`, `Diagnoses`,
`Repairs`, `Verifications`, and `Rollbacks` directories are discovered without
editing a central switch. Permanent IDs are validated against the module
manifest and inventory before registration.

## Safety boundary

All commands use structured arguments through Doctor's process runner. All
paths pass through Doctor's allowed-root filesystem service. Mutating sessions
hold a global execution lock, evaluate policy, preserve evidence, create any
required backup, verify independently, and roll back automatically when the
repair supports rollback and verification fails.

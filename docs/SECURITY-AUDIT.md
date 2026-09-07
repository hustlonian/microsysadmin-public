# MicroSysAdmin Security Audit

Date: 2026-07-25

Scope: current PHP/MySQL application in `/opt/lampp/htdocs/sysadmin`, excluding third-party `vendor/` code and unreadable runtime data under `storage/`.

This audit is a beta-readiness working document. It does not claim the product is fully secure. It identifies known risks, remediation priority, and controls required before a controlled commercial beta.

## Surface Map

- Authentication: `AuthController`, `Auth`, `User`, login/change-password views.
- Authorization: `Auth::can()`, scattered controller checks in `ApiController`, limited frontend hiding.
- Sessions: PHP native sessions configured in `bootstrap.php`, remember-me cookie table.
- Terminal and command execution: `SandboxService`, `terminal-worker.php`, `CommandRunner`, `service-control`, `ScriptService`, Laravel/composer runners, Git actions.
- File operations: `FileVault`, `PathGuard`, `file-control.php`, file manager view/JS.
- Database operations: `MysqlService`, database CRUD/import/export views/API.
- Services: `CommandRunner::service()`, `config/commands.php`, `scripts/service-control`.
- Git: `GitService`, `CommandRunner::git()`.
- Backups and restore: `BackupService`, `BackupJob`, backup views/API, CLI backup script.
- Configuration/settings: `config/config.php`, settings API, installer.
- API/AJAX endpoints: `api/*.php` routed through `ApiController`.
- WebSocket services: none present. Terminal currently uses authenticated HTTP polling and POSTed input queues, not WebSockets.
- Cron/background jobs: `cron/runner.php`, `scheduled_tasks`, `ScriptService`.
- Dependency usage: Composer, Monolog, Symfony Process, PHPMailer, frontend CDNs.
- Error handling: global exception handler exists; production API currently returns raw exception messages in several places.
- Logging: Monolog app log and database `audit_logs`.
- Installer assumptions: XAMPP/LAMPP paths are hard-coded in several commands; web installer writes config and historically seeded default admin credentials.

## Critical Findings

1. Browser terminal provides an unrestricted interactive shell.
   - Impact: administrators can intentionally or accidentally run destructive host commands; command deny/allow policy is not enforceable once arbitrary shell input is accepted.
   - Affected files: `SandboxService`, `terminal-worker.php`, `ApiController`.
   - Remediation: restrict to Administrator, add terminal policy checks, time limits, auditing, rate limiting, kill-on-expiry, and document remaining shell limitations. Consider disabling terminal by default in production.

2. RBAC is coarse and does not enforce required granular permissions.
   - Impact: Power User can perform broad destructive operations; Viewer boundaries are incomplete across pages/API.
   - Affected files: `Auth`, `ApiController`, `PageController`, views.
   - Remediation: replace role map with Administrator/Operator/Viewer permissions, deny by default, and check every server-side action.

3. File archive extraction is vulnerable to zip path traversal.
   - Impact: crafted archives can write outside the intended target directory.
   - Affected files: `FileVault::unzip()`, `BackupService::restore()`.
   - Remediation: preflight archive members, block absolute paths, `..`, drive paths, and symlink-style unsafe entries.

4. SQL import executes uploaded SQL without statement or operation restrictions.
   - Impact: destructive or privilege-changing SQL can be executed by any role with current MySQL write permission.
   - Affected files: `MysqlService::importSql()`, MySQL API.
   - Remediation: restrict to Administrator/database modification permission, require destructive confirmation where applicable, size-limit imports, audit, and document that SQL import is high risk.

## High Findings

1. Login has no rate limiting or temporary lockout.
   - Remediation: add DB-backed rate limiting and failed-login lockout keyed by user/IP.

2. No absolute session lifetime or session-version logout-all control.
   - Remediation: add absolute lifetime, session version, and session invalidation on password/security changes.

3. Default installer credentials can exist in production.
   - Remediation: require first-admin password during install, enforce password policy, block known default credential login in production.

4. Uploaded file validation is extension-only and allows risky extensions in sample config.
   - Remediation: centralize filename/extension/MIME validation, block executable/web-executable uploads, enforce upload rate limits.

5. Sensitive files can be edited through broad allowed roots.
   - Remediation: block mutation of MicroSysAdmin config, environment files, private keys, backups, logs, vendor, and VCS metadata from browser file tools.

6. Production API errors disclose raw exception messages.
   - Remediation: return safe messages in production while logging full diagnostics.

7. Service controls do not distinguish status/restart/start-stop permissions.
   - Remediation: split service viewing, restart/reload, and start/stop permissions.

8. Command execution audit is incomplete.
   - Remediation: audit all command attempts, denied attempts, terminal starts/stops/input-command lines, service changes, script runs, Git actions, and backup restores.

9. Rate limiting is missing for API, command, file upload, database, backup, service, and terminal operations.
   - Remediation: add configurable DB-backed limiter.

10. Secrets and generated artifacts are stored under the web root in development layout.
    - Remediation: keep `.htaccess` protections, add production config examples, support storage/backups outside the public document root, and add `.env.example`.

## Medium Findings

1. Optional MFA is absent.
   - Remediation: add TOTP storage/verification behind user security actions.

2. Audit-log UI has no pagination/filtering and is mixed into settings/users.
   - Remediation: add audit permission, pagination/filter method, and dedicated documentation/UI path.

3. Installer is XAMPP-centric and not production-idempotent.
   - Remediation: add Linux installer/update/rollback/health scripts with environment checks.

4. Health checks are partially implemented but not release-oriented.
   - Remediation: add version/license/pending-migration/backup checks and CLI health script.

5. Licensing abstraction is absent.
   - Remediation: add local license service, license status, development mode, no kill switch.

6. Dependency audit is not part of release flow.
   - Remediation: add documented `composer audit` gate.

7. Security headers are only partially present.
   - Remediation: add application headers and Apache/Nginx examples.

8. Backup restore lacks explicit confirmation at server side.
   - Remediation: require confirmation tokens/flags and pre-restore backup guidance.

## Low Findings

1. Some UI labels still reveal filesystem paths to authenticated users.
   - Acceptable for technical beta, but document as sensitive diagnostics.

2. Frontend uses CDN assets.
   - Acceptable for development; production should pin/SRI or self-host static assets.

3. No formal release/version source of truth.
   - Remediation: add version file and release documentation.

## Remediation Checklist

- [ ] Add granular RBAC with Administrator, Operator, Viewer.
- [ ] Apply server-side permission checks to every API action.
- [ ] Add login/API/action rate limiting and audit denial events.
- [ ] Add password policy, failed-login lockout, absolute session lifetime, session version.
- [ ] Add optional TOTP MFA service and user security actions.
- [ ] Harden terminal with admin-only access, max duration, idle timeout, command policy, command audit, and production-disable option.
- [ ] Harden file upload/edit/delete/move/archive extraction.
- [ ] Harden SQL import and database delete permissions.
- [ ] Add production-safe error handling and security headers.
- [ ] Move secrets toward environment config and add `.env.example`/`.gitignore`.
- [ ] Add release versioning, update, rollback, and health scripts.
- [ ] Add licensing abstraction and local status UI/API.
- [ ] Add backup/recovery safeguards and docs.
- [ ] Add automated security smoke tests and repeatable test command.
- [ ] Add commercial beta documentation set.
- [ ] Create final beta-readiness report and mark readiness honestly.

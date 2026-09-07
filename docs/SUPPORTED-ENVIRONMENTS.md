# Supported environments

## Supported production installation

| Component | Supported value |
|---|---|
| Operating system | Ubuntu 24.04 LTS |
| Architecture | x86_64 or arm64 |
| CPU / RAM | 2 cores / 2 GiB minimum |
| Free disk | 5 GiB installer minimum; more for projects and backups |
| Web | Apache 2.4 on port 80; existing TLS listener on 443 when HTTPS is requested |
| PHP | 8.2+ with PDO MySQL, OpenSSL, JSON, mbstring, ZIP, and cURL |
| Database | managed MariaDB, healthy XAMPP MariaDB/MySQL, or a preprovisioned external MySQL-compatible database |
| Exposure | private network, VPN, or equivalent access control |

Two stack modes are supported: a compatible healthy installation under `/opt/lampp`, or the Ubuntu-managed Apache/PHP 8.3/MariaDB stack installed by MicroSysAdmin. The installer refuses to place the managed stack alongside XAMPP, Nginx, an unrelated port-80 service, or unmanaged local MySQL.

## Automated validation evidence

The repository’s disposable clean Ubuntu 24.04 systemd canary clones a Git origin and follows the exact `sudo ./install.sh` customer path. It validates application response, first-Administrator authentication, release health, permissions, service startup, idempotency, installer upgrade and failed-upgrade recovery, verified database backup/restore, RSA-signed update, manual rollback, forced-update-failure automatic rollback, uninstall preservation, repair, and secret redaction. The final report is copied outside the disposable machine by `./tools/github-test-vm/vm install-clean`.

The legacy XAMPP suite additionally validates migrations, commercial gates, RBAC/MFA, environment overrides, backup/restore, jobs, Safe mode, deterministic signed packaging, and failure rollback against the existing XAMPP architecture.

## Not supported

- Ubuntu releases other than 24.04, Debian, RHEL-family systems, Windows, and macOS;
- 32-bit or unrecognized architectures;
- Nginx or non-Apache routing;
- PHP older than 8.2;
- public browser-terminal or Code Runner exposure;
- shared hosting without root/process/filesystem control;
- hosted multi-tenant/MSP operation;
- Kubernetes or other orchestrators as a customer deployment target;
- undocumented SELinux/AppArmor confinement changes.

An automated container canary does not claim that a separately owned physical/VM release-acceptance machine completed the signed customer-beta checklist. That external acceptance status remains recorded separately in [Second-machine canary results](SECOND-MACHINE-CANARY-RESULTS.md).

# Environment configuration

MicroSysAdmin resolves machine-specific behavior through `EnvironmentConfig`. The browser and operation services should not invent executable or filesystem paths.

## Resolution order

1. Administrator overrides stored as `environment.KEY`.
2. `config/config.php`.
3. Installer detection and legacy-compatible defaults.

The installer detects XAMPP/LAMPP, PHP, Composer, Git, Bash, MySQL/MariaDB clients, Apache, document roots, sockets, service account candidates, and the service-control driver. Detection is advisory and can be overridden.

## Settings

| Setting | Installer option | Purpose |
|---|---|---|
| `xampp_root` | `--xampp-root` | XAMPP/LAMPP root |
| `php_binary` | `--php-binary` | PHP 8.2+ CLI |
| `composer_binary` | `--composer-binary` | Composer executable |
| `git_binary` | `--git-binary` | Git executable |
| `bash_binary` | `--bash-binary` | Bash executable |
| `mysql_binary` | `--mysql-binary` | MySQL/MariaDB client |
| `mysqldump_binary` | `--mysqldump-binary` | Database dump client |
| `document_roots` | `--document-root` | Project discovery roots |
| `allowed_filesystem_roots` | `--allowed-root` | File-operation containment |
| `storage_root` | `--storage-root` | Logs, cache, generated assets, jobs workspace |
| `private_root` | `--private-root` | Machine identity and private installation state |
| `backup_root` | `--backup-root` | Customer backup archives |
| `trash_root` | `--trash-root` | Recoverable file deletions |
| `script_root` | `--scripts-root` | Approved automation scripts |
| `temporary_workspace_root` | `--workspace-root` | Imports and temporary operation data |
| `mysql_host` / `mysql_port` / `mysql_socket` | `--db-host`, `--db-port`, `--db-socket` | Application database transport |
| `service_control_driver` | `--service-driver` | `xampp`, `systemd`, or `inspection` |
| `privileged_helper_path` | `--privileged-helper` | Optional constrained helper |
| `terminal_available` | `--terminal-available=0|1` | Host-level terminal availability |

Database name/user/password use `--db-name`, `--db-user`, and `MICROSYSADMIN_DB_PASSWORD`. Prefer environment variables for secrets.

## Protected paths

`storage_root`, `private_root`, backup, trash, script, and workspace paths are created with restricted permissions. A root-run installer assigns the configured service group to `config/config.php` and the machine identity so the web and worker account can read them without making them world-readable.

Document and allowed roots must be absolute. File operations canonicalize paths and reject escapes. Changing a root does not move existing customer data automatically.

## System integration

Static files under `packaging/` are tokenized reference templates. The authoritative assets are rendered from the active configuration:

```bash
php scripts/render-system-integration.php \
  --output=/tmp/microsysadmin-system \
  --user=daemon \
  --group=daemon
```

Install only after review:

```bash
sudo php scripts/render-system-integration.php \
  --user=daemon \
  --group=daemon \
  --install
```

The renderer writes systemd worker and health units, a health timer, cron runner entry, and logrotate configuration with the actual application, PHP, storage, private, backup, and document-root paths.

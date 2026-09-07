# Environment detection

`EnvironmentDetector` checks `/etc/os-release`, architecture, executable candidates, sockets, Apache/systemd availability, required PHP extensions, and likely document roots. Detection is advisory: administrators can override every operational setting, and diagnostics show missing or unwritable values.

Resolution order is:

1. administrator database override (`environment.KEY`);
2. `config/config.php`;
3. detected/legacy-compatible default.

Supported keys:

| Key | Purpose |
|---|---|
| `xampp_root` | XAMPP/LAMPP installation root |
| `php_binary` | PHP CLI |
| `composer_binary` | Composer |
| `mysql_binary` / `mysqldump_binary` | MySQL clients |
| `git_binary`, `bash_binary`, `ffmpeg_binary` | operational tools |
| `document_roots` | project discovery roots |
| `allowed_filesystem_roots` | file workbench containment |
| `storage_root`, `private_root` | runtime and private state |
| `trash_root`, `backup_root`, `script_root` | managed data locations |
| `temporary_workspace_root` | imports, updates, code-runner staging |
| `mysql_host`, `mysql_port`, `mysql_socket` | database connection |
| `service_control_driver` | `xampp`, `systemd`, or `inspection` |
| `privileged_helper_path` | optional tightly scoped helper |
| `terminal_available` | host-level terminal capability |

Run:

```bash
./install.sh --check
./diagnose.sh
```

The current `/opt/lampp/htdocs/sysadmin` installation requires no manual migration. Legacy `/opt/lampp` values remain defaults only where auto-detection cannot resolve a value.

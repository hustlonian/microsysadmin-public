#!/usr/bin/env bash
set -Eeuo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$app_root/scripts/runtime.sh"
php_binary="$(microsysadmin_resolve_php)"
confirmed=0
assume_yes=0
purge_data=0
remove_application=0
confirm_purge=""

usage() {
    cat <<'USAGE'
Usage: sudo ./uninstall.sh --confirm [--yes]

The default uninstall removes only MicroSysAdmin's web routing and MicroSysAdmin
service registrations. It preserves the application, database, configuration,
backups, projects, customer files, machine identity, and installed stack.

Destructive data removal additionally requires all of:
  --purge-data --remove-application --confirm-purge=DELETE-MICROSYSADMIN-DATA

Shared Apache, PHP, MySQL/MariaDB, Docker, and XAMPP installations are never
removed by this command. The database is preserved even during application-data
purge and must be removed separately by its owner.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --confirm) confirmed=1; shift ;;
        --yes) assume_yes=1; shift ;;
        --purge-data) purge_data=1; shift ;;
        --remove-application) remove_application=1; shift ;;
        --confirm-purge=*) confirm_purge="${1#*=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown uninstall option: $1" >&2; usage >&2; exit 64 ;;
    esac
done

[[ "$confirmed" -eq 1 ]] || { usage >&2; exit 64; }
[[ "$(id -u)" -eq 0 ]] || { echo "Uninstall requires sudo." >&2; exit 1; }
[[ -f "$app_root/config/config.php" ]] || { echo "Active MicroSysAdmin configuration is missing." >&2; exit 1; }

# These values contain paths and an identifier only; secrets are never emitted.
# shellcheck disable=SC2016
mapfile -t installation_values < <("$php_binary" -r '
    $config = require $argv[1];
    $app = $argv[2];
    $expand = static fn(string $v): string => str_replace("{APP_ROOT}", $app, $v);
    echo $expand((string)($config["environment"]["private_root"] ?? "/var/lib/microsysadmin/private")), "\n";
    echo $expand((string)($config["environment"]["storage_root"] ?? "/var/lib/microsysadmin/storage")), "\n";
    echo $expand((string)($config["environment"]["backup_root"] ?? "/var/lib/microsysadmin/backups")), "\n";
    echo (string)($config["database"]["name"] ?? "microsysadmin"), "\n";
    echo (string)($config["environment"]["service_control_driver"] ?? "inspection"), "\n";
' "$app_root/config/config.php" "$app_root")

private_root="${installation_values[0]:-/var/lib/microsysadmin/private}"
storage_root="${installation_values[1]:-/var/lib/microsysadmin/storage}"
backup_root="${installation_values[2]:-/var/lib/microsysadmin/backups}"
database_name="${installation_values[3]:-microsysadmin}"
service_driver="${installation_values[4]:-inspection}"
for protected_path in "$private_root" "$storage_root" "$backup_root"; do
    [[ "$protected_path" == /* && "$protected_path" != "/" && ! "$protected_path" =~ [[:cntrl:]] ]] \
        || { echo "Refusing unsafe configured path: $protected_path" >&2; exit 1; }
done

echo "MicroSysAdmin uninstall proposal:"
echo "  Disable: microsysadmin-worker.service and microsysadmin-health.timer"
echo "  Remove: MicroSysAdmin-only Apache alias and MicroSysAdmin service/cron/logrotate files"
echo "  Preserve application: $app_root"
echo "  Preserve database: $database_name"
echo "  Preserve configuration: $app_root/config/config.php"
echo "  Preserve private state: $private_root"
echo "  Preserve storage: $storage_root"
echo "  Preserve backups: $backup_root"
echo "  Preserve all shared Apache/PHP/MySQL/XAMPP packages and services"

if [[ "$purge_data" -eq 1 || "$remove_application" -eq 1 ]]; then
    [[ "$purge_data" -eq 1 && "$remove_application" -eq 1 && "$confirm_purge" == "DELETE-MICROSYSADMIN-DATA" ]] \
        || { echo "Destructive removal requires --purge-data --remove-application --confirm-purge=DELETE-MICROSYSADMIN-DATA." >&2; exit 64; }
    echo "  DESTRUCTIVE: remove application, private state, and storage after preservation backup"
    echo "  Still preserve: database and backup root"
fi

if [[ "$assume_yes" -ne 1 ]]; then
    [[ -t 0 ]] || { echo "Noninteractive uninstall requires --yes." >&2; exit 64; }
    answer=""
    read -r -p "Proceed with exactly the proposal above? [y/N]: " answer
    [[ "$answer" == "y" || "$answer" == "Y" ]] || { echo "Uninstall aborted without changes."; exit 0; }
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
preserve_root="/var/lib/microsysadmin/uninstalled-$timestamp"
mkdir -p "$preserve_root"
chmod 0700 "$preserve_root"
cp "$app_root/config/config.php" "$preserve_root/config.php"
cp "$app_root/VERSION" "$preserve_root/VERSION"
database_output="$("$php_binary" "$app_root/scripts/backup_database.php")"
database_archive="${database_output##* }"
[[ -f "$database_archive" ]] || { echo "Verified pre-uninstall database backup was not created." >&2; exit 1; }
cp "$database_archive" "$preserve_root/"
unzip -t "$preserve_root/$(basename "$database_archive")" >/dev/null
sha256sum "$preserve_root/config.php" "$preserve_root/VERSION" "$preserve_root/$(basename "$database_archive")" \
    > "$preserve_root/SHA256SUMS"
sha256sum -c "$preserve_root/SHA256SUMS" >/dev/null
chmod 0600 "$preserve_root"/*

if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now microsysadmin-worker.service microsysadmin-health.timer 2>/dev/null || true
fi
rm -f /etc/systemd/system/microsysadmin-worker.service \
    /etc/systemd/system/microsysadmin-health.service \
    /etc/systemd/system/microsysadmin-health.timer \
    /etc/cron.d/microsysadmin /etc/logrotate.d/microsysadmin

if [[ "$service_driver" == "xampp" && -f /opt/lampp/etc/httpd.conf ]]; then
    cp /opt/lampp/etc/httpd.conf "$preserve_root/xampp-httpd.conf"
    sed '/^[[:space:]]*# MicroSysAdmin managed include[[:space:]]*$/d; /^[[:space:]]*Include etc\/extra\/httpd-microsysadmin\.conf[[:space:]]*$/d' \
        /opt/lampp/etc/httpd.conf > "$preserve_root/httpd.conf.updated"
    cp "$preserve_root/httpd.conf.updated" /opt/lampp/etc/httpd.conf
    rm -f /opt/lampp/etc/extra/httpd-microsysadmin.conf
    /opt/lampp/bin/apachectl configtest
    /opt/lampp/lampp restartapache
elif command -v a2disconf >/dev/null 2>&1; then
    a2disconf microsysadmin >/dev/null 2>&1 || true
    rm -f /etc/apache2/conf-available/microsysadmin.conf
    if command -v systemctl >/dev/null 2>&1; then
        systemctl reload apache2.service 2>/dev/null || true
    fi
fi
rm -f /etc/microsysadmin/install.conf
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload 2>/dev/null || true
fi

if [[ "$purge_data" -eq 1 ]]; then
    case "$app_root" in
        /opt/microsysadmin/app|/opt/lampp/htdocs/microsysadmin) ;;
        *) echo "Refusing destructive removal of nonstandard application path: $app_root" >&2; exit 1 ;;
    esac
    case "$private_root" in /var/lib/microsysadmin/private) ;; *) echo "Refusing nonstandard private-root purge: $private_root" >&2; exit 1 ;; esac
    case "$storage_root" in /var/lib/microsysadmin/storage) ;; *) echo "Refusing nonstandard storage-root purge: $storage_root" >&2; exit 1 ;; esac
    rm -rf -- "$private_root" "$storage_root" "$app_root"
fi

echo "MicroSysAdmin web access and MicroSysAdmin-only services were removed."
echo "Verified recovery bundle: $preserve_root"
echo "Database and backups were preserved. Shared stack packages were untouched."

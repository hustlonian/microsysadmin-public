#!/usr/bin/env bash

# MicroSysAdmin clean-machine installer implementation. This file is sourced by
# install.sh so the public entry point remains the documented one-liner.

LP_INSTALLER_VERSION="1"
LP_ACTION="install"
LP_NON_INTERACTIVE=0
LP_ASSUME_YES=0
LP_STACK_REQUEST="${MICROSYSADMIN_STACK_MODE:-auto}"
LP_INSTALL_ROOT_REQUEST="${MICROSYSADMIN_INSTALL_ROOT:-}"
LP_SOURCE_ROOT=""
LP_INSTALL_ROOT=""
LP_STACK_MODE=""
LP_BASE_URL_EXPLICIT=0
[[ -z "${MICROSYSADMIN_BASE_URL:-}" ]] || LP_BASE_URL_EXPLICIT=1
LP_BASE_URL_INPUT="${MICROSYSADMIN_BASE_URL:-http://localhost/microsysadmin}"
LP_BASE_PATH="/microsysadmin"
LP_LOGIN_URL=""
LP_ADMIN_USERNAME="${MICROSYSADMIN_ADMIN_USERNAME:-}"
LP_ADMIN_EMAIL="${MICROSYSADMIN_ADMIN_EMAIL:-}"
LP_ADMIN_PASSWORD="${MICROSYSADMIN_ADMIN_PASSWORD:-}"
LP_ADMIN_GENERATED=0
LP_INITIAL_ADMIN_CREDENTIALS=""
LP_SECURITY_PROFILE="${MICROSYSADMIN_SECURITY_PROFILE:-safe}"
LP_DB_HOST="${MICROSYSADMIN_DB_HOST:-}"
LP_DB_PORT="${MICROSYSADMIN_DB_PORT:-3306}"
LP_DB_NAME="${MICROSYSADMIN_DB_NAME:-microsysadmin}"
LP_DB_USER="${MICROSYSADMIN_DB_USER:-microsysadmin}"
LP_DB_PASSWORD="${MICROSYSADMIN_DB_PASSWORD:-}"
LP_DB_ADMIN_USER="${MICROSYSADMIN_DB_ADMIN_USER:-root}"
LP_DB_ADMIN_PASSWORD="${MICROSYSADMIN_DB_ADMIN_PASSWORD:-}"
LP_DB_SOCKET="${MICROSYSADMIN_DB_SOCKET:-}"
LP_PHP_BINARY=""
LP_COMPOSER_BINARY=""
LP_MYSQL_BINARY=""
LP_MYSQLDUMP_BINARY=""
LP_SERVICE_USER=""
LP_SERVICE_GROUP=""
LP_DOCUMENT_ROOT=""
LP_STORAGE_ROOT="/var/lib/microsysadmin/storage"
LP_PRIVATE_ROOT="/var/lib/microsysadmin/private"
LP_BACKUP_ROOT="/var/lib/microsysadmin/backups"
LP_RELEASE_ROOT="/var/lib/microsysadmin/releases"
LP_STATE_ROOT="/etc/microsysadmin"
LP_STATE_FILE="/etc/microsysadmin/install.conf"
LP_LOG_ROOT="/var/log/microsysadmin"
LP_LOG_FILE=""
LP_STAGE="startup"
LP_FAILURE_HANDLED=0
LP_EXISTING=0
LP_EXISTING_BACKUP=""
LP_ROLLBACK_READY=0
LP_APACHE_CONFIG=""
LP_APACHE_CONFIG_BACKUP=""
LP_APACHE_CONFIG_EXISTED=0
LP_XAMPP_HTTPD_BACKUP=""
LP_SYSTEM_INTEGRATION_SNAPSHOT=""
LP_TEMP_ROOT=""
LP_EXPERT_MODE=0
LP_PURGE_DATA=0
LP_REMOVE_APPLICATION=0
LP_CONFIRM_PURGE=""
LP_FORWARD_ARGS=()
LP_TEST_OS_RELEASE="${MICROSYSADMIN_TEST_OS_RELEASE_FILE:-/etc/os-release}"
LP_MIN_DISK_MB="${MICROSYSADMIN_MIN_DISK_MB:-5120}"

# Keep secrets in non-exported shell variables and expose them only to the
# exact PHP/database process that needs them, not apt, Composer, Git, or logs.
unset MICROSYSADMIN_ADMIN_PASSWORD MICROSYSADMIN_DB_PASSWORD MICROSYSADMIN_DB_ADMIN_PASSWORD

lp_usage() {
    cat <<'USAGE'
MicroSysAdmin production installer

Usage:
  sudo ./install.sh [ACTION] [OPTIONS]

Actions:
  --check              Run read-only host and installation checks
  --repair             Back up and repair an existing installation
  --upgrade            Back up and upgrade from this checkout
  --uninstall          Safely disable MicroSysAdmin and preserve customer data
  --help               Show this help

Options:
  --non-interactive    Never prompt; generate omitted local secrets securely
  --yes                Confirm the non-destructive uninstall action
  --stack=auto|xampp|managed
  --install-root=PATH
  --admin-username=NAME
  --admin-email=EMAIL
  --base-url=URL_OR_PATH
  --security-profile=safe|developer|advanced
  --db-host=HOST --db-port=PORT --db-name=NAME --db-user=USER

Secret values may be supplied through MICROSYSADMIN_ADMIN_PASSWORD,
MICROSYSADMIN_DB_PASSWORD, and MICROSYSADMIN_DB_ADMIN_PASSWORD. Omitted first-
Administrator credentials are generated and stored in a root-only file.
Command-line password options are rejected because process arguments are
visible to other local users.

Automation environment:
  MICROSYSADMIN_ADMIN_USERNAME  MICROSYSADMIN_ADMIN_EMAIL
  MICROSYSADMIN_ADMIN_PASSWORD  MICROSYSADMIN_BASE_URL
  MICROSYSADMIN_SECURITY_PROFILE
  MICROSYSADMIN_DB_HOST          MICROSYSADMIN_DB_PORT
  MICROSYSADMIN_DB_NAME          MICROSYSADMIN_DB_USER
  MICROSYSADMIN_DB_PASSWORD      MICROSYSADMIN_DB_SOCKET
  MICROSYSADMIN_DB_ADMIN_USER    MICROSYSADMIN_DB_ADMIN_PASSWORD

Advanced runtime/path overrides from earlier MicroSysAdmin releases remain
available for isolated deployments. Supplying --php-binary or
--system-integration=skip selects that compatibility path and does not install
operating-system packages.
USAGE
}

lp_log() {
    printf '[MicroSysAdmin] %s\n' "$*"
}

lp_warn() {
    printf '[MicroSysAdmin] WARNING: %s\n' "$*" >&2
}

lp_fail() {
    printf '[MicroSysAdmin] ERROR (%s): %s\n' "$LP_STAGE" "$*" >&2
    return 1
}

lp_stage() {
    LP_STAGE="$1"
    lp_log "Stage: $LP_STAGE"
}

lp_value_argument() {
    local argument="$1" name="$2"
    if [[ "$argument" == "$name="* ]]; then
        printf '%s' "${argument#*=}"
        return 0
    fi
    return 1
}

lp_parse_arguments() {
    local argument value
    while [[ $# -gt 0 ]]; do
        argument="$1"
        case "$argument" in
            --check) LP_ACTION="check"; shift ;;
            --repair) LP_ACTION="repair"; shift ;;
            --upgrade) LP_ACTION="upgrade"; shift ;;
            --uninstall) LP_ACTION="uninstall"; shift ;;
            --non-interactive) LP_NON_INTERACTIVE=1; shift ;;
            --yes|--confirm) LP_ASSUME_YES=1; shift ;;
            --purge-data) LP_PURGE_DATA=1; shift ;;
            --remove-application) LP_REMOVE_APPLICATION=1; shift ;;
            --help|-h) LP_ACTION="help"; shift ;;
            --admin-password|--admin-password=*|--db-password|--db-password=*)
                lp_fail "Password command-line options are unsafe. Use MICROSYSADMIN_ADMIN_PASSWORD or MICROSYSADMIN_DB_PASSWORD." || return
                ;;
            --stack=*) LP_STACK_REQUEST="${argument#*=}"; shift ;;
            --stack) LP_STACK_REQUEST="${2:-}"; shift 2 ;;
            --install-root=*) LP_INSTALL_ROOT_REQUEST="${argument#*=}"; shift ;;
            --install-root) LP_INSTALL_ROOT_REQUEST="${2:-}"; shift 2 ;;
            --admin-username=*) LP_ADMIN_USERNAME="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --admin-username) LP_ADMIN_USERNAME="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --admin-email=*) LP_ADMIN_EMAIL="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --admin-email) LP_ADMIN_EMAIL="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --base-url=*) LP_BASE_URL_INPUT="${argument#*=}"; LP_BASE_URL_EXPLICIT=1; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --base-url) LP_BASE_URL_INPUT="${2:-}"; LP_BASE_URL_EXPLICIT=1; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --security-profile=*) LP_SECURITY_PROFILE="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --security-profile) LP_SECURITY_PROFILE="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --db-host=*) LP_DB_HOST="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --db-host) LP_DB_HOST="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --db-port=*) LP_DB_PORT="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --db-port) LP_DB_PORT="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --db-name=*) LP_DB_NAME="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --db-name) LP_DB_NAME="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --db-user=*) LP_DB_USER="${argument#*=}"; LP_FORWARD_ARGS+=("$argument"); shift ;;
            --db-user) LP_DB_USER="${2:-}"; LP_FORWARD_ARGS+=("$argument" "${2:-}"); shift 2 ;;
            --confirm-purge=*) LP_CONFIRM_PURGE="${argument#*=}"; shift ;;
            --php-binary=*|--php-binary|--xampp-root=*|--xampp-root|--composer-binary=*|--composer-binary|\
            --git-binary=*|--git-binary|--bash-binary=*|--bash-binary|--mysql-binary=*|--mysql-binary|\
            --mysqldump-binary=*|--mysqldump-binary|--document-root=*|--document-root|\
            --allowed-root=*|--allowed-root|--storage-root=*|--storage-root|--private-root=*|--private-root|\
            --backup-root=*|--backup-root|--trash-root=*|--trash-root|--scripts-root=*|--scripts-root|\
            --workspace-root=*|--workspace-root|--service-driver=*|--service-driver|--privileged-helper=*|\
            --privileged-helper|--terminal-available=*|--terminal-available|--service-user=*|--service-user|\
            --service-group=*|--service-group|--system-integration=*|--system-integration|--db-socket=*|\
            --db-socket|--hub-url=*|--hub-url|--hub-organization=*|--hub-organization|--hub-token=*|--hub-token)
                LP_EXPERT_MODE=1
                LP_FORWARD_ARGS+=("$argument")
                if [[ "$argument" != *=* ]]; then
                    [[ $# -ge 2 ]] || { lp_fail "Missing value for $argument" || return; }
                    LP_FORWARD_ARGS+=("$2")
                    shift 2
                else
                    shift
                fi
                ;;
            *) lp_fail "Unknown option: $argument" || return ;;
        esac
    done

    case "$LP_STACK_REQUEST" in auto|xampp|managed) ;; *) lp_fail "Stack must be auto, xampp, or managed." || return ;; esac
}

lp_resolve_compatibility_php() {
    local argument candidate
    for argument in "${LP_FORWARD_ARGS[@]}"; do
        if value="$(lp_value_argument "$argument" --php-binary)"; then
            [[ -x "$value" ]] && { printf '%s\n' "$value"; return 0; }
        fi
    done
    if [[ -n "${MICROSYSADMIN_PHP_BINARY:-}" && -x "${MICROSYSADMIN_PHP_BINARY}" ]]; then
        printf '%s\n' "$MICROSYSADMIN_PHP_BINARY"
        return 0
    fi
    for candidate in /opt/lampp/bin/php /usr/bin/php /usr/local/bin/php; do
        [[ -x "$candidate" ]] && { printf '%s\n' "$candidate"; return 0; }
    done
    return 1
}

lp_run_compatibility_installer() {
    local php_binary
    php_binary="$(lp_resolve_compatibility_php)" || lp_fail "PHP 8.2+ was not found for the runtime-only installation."
    MICROSYSADMIN_INSTALL_CONTEXT=1 MICROSYSADMIN_ADMIN_PASSWORD="$LP_ADMIN_PASSWORD" MICROSYSADMIN_DB_PASSWORD="$LP_DB_PASSWORD" \
        exec "$php_binary" "$LP_SOURCE_ROOT/scripts/install-production.php" "${LP_FORWARD_ARGS[@]}"
}

lp_require_root() {
    [[ "$(id -u)" -eq 0 ]] || lp_fail "Run the production installer with sudo: sudo ./install.sh"
}

lp_load_os() {
    [[ -r "$LP_TEST_OS_RELEASE" ]] || lp_fail "Cannot read operating-system metadata: $LP_TEST_OS_RELEASE"
    local id version_id pretty_name
    id="$(sed -n 's/^ID=//p' "$LP_TEST_OS_RELEASE" | head -n1 | tr -d '"')"
    version_id="$(sed -n 's/^VERSION_ID=//p' "$LP_TEST_OS_RELEASE" | head -n1 | tr -d '"')"
    pretty_name="$(sed -n 's/^PRETTY_NAME=//p' "$LP_TEST_OS_RELEASE" | head -n1 | tr -d '"')"
    [[ "$id" == "ubuntu" && "$version_id" == "24.04" ]] \
        || lp_fail "Unsupported operating system: ${pretty_name:-unknown}. MicroSysAdmin currently supports Ubuntu 24.04 LTS."
    case "$(uname -m)" in
        x86_64|amd64|aarch64|arm64) ;;
        *) lp_fail "Unsupported architecture: $(uname -m). Use x86_64 or arm64." ;;
    esac
}

lp_check_disk() {
    [[ "$LP_MIN_DISK_MB" =~ ^[0-9]+$ ]] || lp_fail "MICROSYSADMIN_MIN_DISK_MB must be an integer."
    local available_kb required_kb capacity_path
    required_kb=$((LP_MIN_DISK_MB * 1024))
    for capacity_path in "$LP_SOURCE_ROOT" /opt /var/lib; do
        available_kb="$(df -Pk "$capacity_path" | awk 'NR==2 {print $4}')"
        [[ "$available_kb" =~ ^[0-9]+$ && "$available_kb" -ge "$required_kb" ]] \
            || lp_fail "Insufficient disk space on the filesystem containing $capacity_path. At least ${LP_MIN_DISK_MB} MiB free is required; $((${available_kb:-0} / 1024)) MiB is available."
    done
}

lp_detect_stack() {
    local xampp_present=0
    [[ -d /opt/lampp ]] && xampp_present=1
    if [[ "$LP_STACK_REQUEST" == "xampp" || ( "$LP_STACK_REQUEST" == "auto" && "$xampp_present" -eq 1 ) ]]; then
        [[ -x /opt/lampp/lampp && -x /opt/lampp/bin/php && -x /opt/lampp/bin/mysql && -x /opt/lampp/bin/mysqldump ]] \
            || lp_fail "/opt/lampp exists but is incomplete. Repair XAMPP or explicitly remove the conflicting partial installation."
        LP_STACK_MODE="xampp"
        LP_PHP_BINARY="/opt/lampp/bin/php"
        LP_MYSQL_BINARY="/opt/lampp/bin/mysql"
        LP_MYSQLDUMP_BINARY="/opt/lampp/bin/mysqldump"
        LP_SERVICE_USER="daemon"
        LP_SERVICE_GROUP="$(id -gn daemon 2>/dev/null || printf 'daemon')"
        LP_DOCUMENT_ROOT="/opt/lampp/htdocs"
        LP_DB_SOCKET="${LP_DB_SOCKET:-/opt/lampp/var/mysql/mysql.sock}"
        [[ -n "$LP_DB_HOST" ]] || LP_DB_HOST="127.0.0.1"
        LP_INSTALL_ROOT="${LP_INSTALL_ROOT_REQUEST:-/opt/lampp/htdocs/microsysadmin}"
        lp_default_xampp_base_url
    else
        [[ "$xampp_present" -eq 0 ]] || lp_fail "Managed mode will not be installed while /opt/lampp exists. This prevents conflicting Apache, PHP, and database stacks."
        LP_STACK_MODE="managed"
        LP_PHP_BINARY="/usr/bin/php"
        LP_MYSQL_BINARY="/usr/bin/mariadb"
        LP_MYSQLDUMP_BINARY="/usr/bin/mariadb-dump"
        LP_SERVICE_USER="www-data"
        LP_SERVICE_GROUP="www-data"
        LP_DOCUMENT_ROOT="/var/www/microsysadmin-sites"
        [[ -n "$LP_DB_HOST" ]] || LP_DB_HOST="127.0.0.1"
        LP_DB_SOCKET="${LP_DB_SOCKET:-/run/mysqld/mysqld.sock}"
        LP_INSTALL_ROOT="${LP_INSTALL_ROOT_REQUEST:-/opt/microsysadmin/app}"
    fi
}

lp_default_xampp_base_url() {
    [[ "$LP_BASE_URL_EXPLICIT" -eq 0 ]] || return 0
    case "$LP_INSTALL_ROOT" in
        /opt/lampp/htdocs/?*)
            LP_BASE_URL_INPUT="http://localhost/${LP_INSTALL_ROOT#/opt/lampp/htdocs/}"
            ;;
    esac
}

lp_read_state_value() {
    local key="$1"
    [[ -r "$LP_STATE_FILE" ]] || return 1
    sed -n "s/^${key}=//p" "$LP_STATE_FILE" | head -n1
}

lp_detect_existing_installation() {
    local state_root state_stack state_base
    if [[ -f "$LP_SOURCE_ROOT/config/config.php" ]]; then
        LP_INSTALL_ROOT="$LP_SOURCE_ROOT"
        LP_EXISTING=1
        return
    fi
    state_root="$(lp_read_state_value INSTALL_ROOT || true)"
    state_stack="$(lp_read_state_value STACK_MODE || true)"
    state_base="$(lp_read_state_value BASE_PATH || true)"
    if [[ -n "$state_root" ]]; then
        [[ "$state_root" == /* && "$state_root" != "/" && ! "$state_root" =~ [[:cntrl:]] ]] \
            || lp_fail "The MicroSysAdmin installation record contains an unsafe path."
        LP_INSTALL_ROOT="$state_root"
        if [[ -f "$state_root/config/config.php" ]]; then
            LP_EXISTING=1
            if [[ "$state_stack" == "xampp" || "$state_stack" == "managed" ]]; then
                [[ -z "$LP_STACK_MODE" || "$state_stack" == "$LP_STACK_MODE" ]] \
                    || lp_fail "The installation record expects the $state_stack stack, but the host now detects $LP_STACK_MODE. Restore the original stack or recover the matching MicroSysAdmin configuration before repair/upgrade."
                LP_STACK_MODE="$state_stack"
            fi
            [[ -n "$state_base" ]] && LP_BASE_PATH="$state_base"
        fi
    fi
    if [[ "$LP_EXISTING" -eq 0 && -f "$LP_INSTALL_ROOT/config/config.php" ]]; then
        LP_EXISTING=1
    fi
}

lp_load_existing_configuration() {
    [[ "$LP_EXISTING" -eq 1 && -r "$LP_INSTALL_ROOT/config/config.php" && -x "$LP_PHP_BINARY" ]] || return 0
    local values
    # Only non-secret paths and identifiers are returned by this probe.
    # shellcheck disable=SC2016
    values="$("$LP_PHP_BINARY" -r '
        $c = require $argv[1];
        $app = $argv[2];
        $expand = static fn(string $v): string => str_replace("{APP_ROOT}", $app, $v);
        $rows = [
            (string)($c["app"]["base_url"] ?? "/microsysadmin"),
            (string)($c["database"]["host"] ?? "127.0.0.1"),
            (string)($c["database"]["port"] ?? "3306"),
            (string)($c["database"]["socket"] ?? ""),
            (string)($c["database"]["name"] ?? "microsysadmin"),
            (string)($c["database"]["user"] ?? "microsysadmin"),
            $expand((string)($c["environment"]["storage_root"] ?? "/var/lib/microsysadmin/storage")),
            $expand((string)($c["environment"]["private_root"] ?? "/var/lib/microsysadmin/private")),
            $expand((string)($c["environment"]["backup_root"] ?? "/var/lib/microsysadmin/backups")),
            (string)($c["installation"]["security_profile"] ?? "safe"),
        ];
        echo implode("\n", $rows), "\n";
    ' "$LP_INSTALL_ROOT/config/config.php" "$LP_INSTALL_ROOT")"
    mapfile -t existing_values <<< "$values"
    LP_BASE_PATH="${existing_values[0]:-/microsysadmin}"
    LP_BASE_URL_INPUT="$LP_BASE_PATH"
    LP_DB_HOST="${existing_values[1]:-127.0.0.1}"
    LP_DB_PORT="${existing_values[2]:-3306}"
    LP_DB_SOCKET="${existing_values[3]:-}"
    LP_DB_NAME="${existing_values[4]:-microsysadmin}"
    LP_DB_USER="${existing_values[5]:-microsysadmin}"
    LP_STORAGE_ROOT="${existing_values[6]:-/var/lib/microsysadmin/storage}"
    LP_PRIVATE_ROOT="${existing_values[7]:-/var/lib/microsysadmin/private}"
    LP_BACKUP_ROOT="${existing_values[8]:-/var/lib/microsysadmin/backups}"
    LP_SECURITY_PROFILE="${existing_values[9]:-safe}"
    LP_LOGIN_URL="http://localhost${LP_BASE_PATH}/login"
}

lp_parse_base_url() {
    local value="$LP_BASE_URL_INPUT" host_path
    [[ -n "$value" && ! "$value" =~ [[:space:]] ]] || lp_fail "MICROSYSADMIN_BASE_URL must not be empty or contain whitespace."
    value="${value%/}"
    if [[ "$value" =~ ^https?://[^/]+(/.*)?$ ]]; then
        host_path="${value#*://}"
        if [[ "$host_path" == */* ]]; then
            LP_BASE_PATH="/${host_path#*/}"
        else
            LP_BASE_PATH="/microsysadmin"
            value="$value$LP_BASE_PATH"
        fi
        LP_LOGIN_URL="$value/login"
    elif [[ "$value" == /* ]]; then
        LP_BASE_PATH="$value"
        LP_LOGIN_URL="http://localhost${LP_BASE_PATH}/login"
    else
        lp_fail "Base URL must be an http(s) URL or an absolute path such as /microsysadmin."
    fi
    [[ "$LP_BASE_PATH" =~ ^/[A-Za-z0-9._~/-]+$ && "$LP_BASE_PATH" != "/" && "$LP_BASE_PATH" != *".."* ]] \
        || lp_fail "The MicroSysAdmin URL path is unsafe."
}

lp_validate_inputs() {
    [[ "$LP_DB_PORT" =~ ^[0-9]+$ && "$LP_DB_PORT" -ge 1 && "$LP_DB_PORT" -le 65535 ]] \
        || lp_fail "Database port must be between 1 and 65535."
    [[ "$LP_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || lp_fail "Database name may contain only letters, numbers, and underscores."
    [[ "$LP_DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || lp_fail "Database user may contain only letters, numbers, and underscores."
    [[ "$LP_DB_ADMIN_USER" =~ ^[A-Za-z0-9_.-]+$ ]] || lp_fail "Database administrator username is invalid."
    case "${LP_SECURITY_PROFILE,,}" in safe|developer|advanced) ;; *) lp_fail "Security profile must be safe, developer, or advanced." ;; esac
    if [[ "$LP_SECURITY_PROFILE" == "advanced" && "${MICROSYSADMIN_ADVANCED_RISK_ACKNOWLEDGED:-0}" != "1" ]]; then
        lp_fail "Advanced profile requires MICROSYSADMIN_ADVANCED_RISK_ACKNOWLEDGED=1."
    fi
    if [[ -n "$LP_INSTALL_ROOT" ]]; then
        [[ "$LP_INSTALL_ROOT" == /* && "$LP_INSTALL_ROOT" != "/" && ! "$LP_INSTALL_ROOT" =~ [[:cntrl:]] ]] \
            || lp_fail "Installation root must be a safe absolute path."
    fi
}

lp_collect_admin() {
    LP_ADMIN_USERNAME="${LP_ADMIN_USERNAME:-owner}"
    LP_ADMIN_EMAIL="${LP_ADMIN_EMAIL:-${LP_ADMIN_USERNAME}@microsysadmin.invalid}"
    if [[ -z "$LP_ADMIN_PASSWORD" ]]; then
        LP_ADMIN_PASSWORD="Lp!$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
        LP_ADMIN_GENERATED=1
    fi
    [[ "$LP_ADMIN_USERNAME" =~ ^[A-Za-z0-9_.-]{3,80}$ ]] \
        || lp_fail "Administrator username must be 3-80 letters, numbers, dots, dashes, or underscores."
    [[ "$LP_ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] \
        || lp_fail "Administrator email is invalid."
    local password="$LP_ADMIN_PASSWORD"
    [[ ${#password} -ge 12 && "$password" =~ [a-z] && "$password" =~ [A-Z] && "$password" =~ [0-9] && "$password" =~ [^A-Za-z0-9] ]] \
        || lp_fail "Administrator password must be at least 12 characters with upper/lowercase, a number, and a symbol."
}

lp_collect_database_credentials() {
    [[ "$LP_NON_INTERACTIVE" -eq 0 && -t 0 ]] || return 0
    local entered_user=""
    if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
        read -r -p "Database administrator username [$LP_DB_ADMIN_USER]: " entered_user
        [[ -z "$entered_user" ]] || LP_DB_ADMIN_USER="$entered_user"
        if [[ -z "$LP_DB_ADMIN_PASSWORD" ]]; then
            read -r -s -p "Database administrator password (leave blank for socket authentication): " LP_DB_ADMIN_PASSWORD
            printf '\n'
        fi
    else
        read -r -p "Database username [$LP_DB_USER]: " entered_user
        [[ -z "$entered_user" ]] || LP_DB_USER="$entered_user"
        if [[ -z "$LP_DB_PASSWORD" ]]; then
            read -r -s -p "Database password: " LP_DB_PASSWORD
            printf '\n'
        fi
    fi
}

lp_start_logging() {
    mkdir -p "$LP_LOG_ROOT"
    chmod 0750 "$LP_LOG_ROOT"
    LP_LOG_FILE="$LP_LOG_ROOT/install-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
    touch "$LP_LOG_FILE"
    chmod 0600 "$LP_LOG_FILE"
    exec > >(tee -a "$LP_LOG_FILE") 2>&1
    lp_log "Detailed log: $LP_LOG_FILE"
}

lp_snapshot_system_integration() {
    LP_SYSTEM_INTEGRATION_SNAPSHOT="$LP_TEMP_ROOT/system-integration.before"
    mkdir -p "$LP_SYSTEM_INTEGRATION_SNAPSHOT"
    local key target
    while IFS='|' read -r key target; do
        if [[ -f "$target" ]]; then
            cp "$target" "$LP_SYSTEM_INTEGRATION_SNAPSHOT/$key"
        else
            touch "$LP_SYSTEM_INTEGRATION_SNAPSHOT/$key.missing"
        fi
    done <<'TARGETS'
worker|/etc/systemd/system/microsysadmin-worker.service
health-service|/etc/systemd/system/microsysadmin-health.service
health-timer|/etc/systemd/system/microsysadmin-health.timer
cron|/etc/cron.d/microsysadmin
logrotate|/etc/logrotate.d/microsysadmin
TARGETS
    if lp_systemctl_usable; then
        if systemctl is-enabled --quiet microsysadmin-worker.service 2>/dev/null; then
            touch "$LP_SYSTEM_INTEGRATION_SNAPSHOT/worker.enabled"
        fi
        if systemctl is-enabled --quiet microsysadmin-health.timer 2>/dev/null; then
            touch "$LP_SYSTEM_INTEGRATION_SNAPSHOT/timer.enabled"
        fi
        if systemctl is-active --quiet microsysadmin-worker.service 2>/dev/null; then
            touch "$LP_SYSTEM_INTEGRATION_SNAPSHOT/worker.active"
        fi
        if systemctl is-active --quiet microsysadmin-health.timer 2>/dev/null; then
            touch "$LP_SYSTEM_INTEGRATION_SNAPSHOT/timer.active"
        fi
    fi
    return 0
}

lp_restore_system_integration() {
    [[ -n "$LP_SYSTEM_INTEGRATION_SNAPSHOT" && -d "$LP_SYSTEM_INTEGRATION_SNAPSHOT" ]] || return 0
    if lp_systemctl_usable; then
        systemctl disable --now microsysadmin-worker.service microsysadmin-health.timer >/dev/null 2>&1 || true
    fi
    local key target
    while IFS='|' read -r key target; do
        if [[ -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/$key" ]]; then
            cp "$LP_SYSTEM_INTEGRATION_SNAPSHOT/$key" "$target" || return 1
        elif [[ -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/$key.missing" ]]; then
            rm -f -- "$target" || return 1
        fi
    done <<'TARGETS'
worker|/etc/systemd/system/microsysadmin-worker.service
health-service|/etc/systemd/system/microsysadmin-health.service
health-timer|/etc/systemd/system/microsysadmin-health.timer
cron|/etc/cron.d/microsysadmin
logrotate|/etc/logrotate.d/microsysadmin
TARGETS
    if lp_systemctl_usable; then
        systemctl daemon-reload >/dev/null 2>&1 || return 1
        [[ ! -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/worker.enabled" ]] \
            || systemctl enable microsysadmin-worker.service >/dev/null 2>&1 || return 1
        [[ ! -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/timer.enabled" ]] \
            || systemctl enable microsysadmin-health.timer >/dev/null 2>&1 || return 1
        [[ ! -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/worker.active" ]] \
            || systemctl start microsysadmin-worker.service >/dev/null 2>&1 || return 1
        [[ ! -f "$LP_SYSTEM_INTEGRATION_SNAPSHOT/timer.active" ]] \
            || systemctl start microsysadmin-health.timer >/dev/null 2>&1 || return 1
    fi
}

lp_failure_trap() {
    local status=$?
    [[ "$status" -ne 0 ]] || return 0
    if [[ "$LP_FAILURE_HANDLED" -eq 1 ]]; then
        exit "$status"
    fi
    LP_FAILURE_HANDLED=1
    trap - ERR
    printf '[MicroSysAdmin] INSTALLATION FAILED at stage: %s\n' "$LP_STAGE" >&2
    if [[ -n "$LP_SYSTEM_INTEGRATION_SNAPSHOT" ]] && lp_systemctl_usable; then
        systemctl disable --now microsysadmin-worker.service microsysadmin-health.timer >/dev/null 2>&1 || true
    fi
    if [[ "$LP_ROLLBACK_READY" -eq 1 && -n "$LP_EXISTING_BACKUP" && -f "$LP_EXISTING_BACKUP/application.tar.gz" ]]; then
        lp_warn "Restoring the pre-change application snapshot. The verified database backup is preserved for manual recovery."
        local rollback_extract
        rollback_extract="$(mktemp -d /tmp/microsysadmin-rollback.XXXXXXXX)"
        if tar -xzf "$LP_EXISTING_BACKUP/application.tar.gz" -C "$rollback_extract"; then
            rsync -a --delete --exclude=/.git/ --exclude=/storage/cache/ --exclude=/storage/workspace/ \
                "$rollback_extract/$(basename "$LP_INSTALL_ROOT")/" "$LP_INSTALL_ROOT/" \
                || lp_warn "Automatic application-file restoration failed."
        else
            lp_warn "The verified application snapshot could not be extracted automatically."
        fi
        rm -rf -- "$rollback_extract"
    fi
    if [[ -n "$LP_APACHE_CONFIG" ]]; then
        if [[ -n "$LP_APACHE_CONFIG_BACKUP" && -f "$LP_APACHE_CONFIG_BACKUP" ]]; then
            cp "$LP_APACHE_CONFIG_BACKUP" "$LP_APACHE_CONFIG" || lp_warn "Apache configuration restoration failed."
        elif [[ "$LP_APACHE_CONFIG_EXISTED" -eq 0 ]]; then
            rm -f -- "$LP_APACHE_CONFIG"
        fi
    fi
    if [[ -n "$LP_XAMPP_HTTPD_BACKUP" && -f "$LP_XAMPP_HTTPD_BACKUP" ]]; then
        cp "$LP_XAMPP_HTTPD_BACKUP" /opt/lampp/etc/httpd.conf || lp_warn "XAMPP Apache configuration restoration failed."
    fi
    if [[ -n "$LP_APACHE_CONFIG" && "$LP_STACK_MODE" == "managed" ]]; then
        if [[ "$LP_APACHE_CONFIG_EXISTED" -eq 0 ]] && command -v a2disconf >/dev/null 2>&1; then
            a2disconf microsysadmin >/dev/null 2>&1 || true
        fi
        if apache2ctl configtest >/dev/null 2>&1; then
            if lp_systemctl_usable; then
                systemctl restart apache2.service || lp_warn "Restored Apache configuration requires a manual restart."
            else
                apache2ctl graceful || lp_warn "Restored Apache configuration requires a manual reload."
            fi
        else
            lp_warn "Restored Apache configuration requires manual validation/restart."
        fi
    elif [[ -n "$LP_APACHE_CONFIG" && "$LP_STACK_MODE" == "xampp" ]]; then
        if /opt/lampp/bin/apachectl configtest >/dev/null 2>&1; then
            /opt/lampp/lampp restartapache >/dev/null 2>&1 \
                || lp_warn "Restored XAMPP Apache configuration requires a manual restart."
        else
            lp_warn "Restored XAMPP Apache configuration requires manual validation/restart."
        fi
    fi
    lp_restore_system_integration \
        || lp_warn "MicroSysAdmin service/cron/logrotate restoration requires manual recovery."
    [[ -z "$LP_LOG_FILE" ]] || printf '[MicroSysAdmin] Log: %s\n' "$LP_LOG_FILE" >&2
    [[ -z "$LP_EXISTING_BACKUP" ]] || printf '[MicroSysAdmin] Recovery backup: %s\n' "$LP_EXISTING_BACKUP" >&2
    exit "$status"
}

lp_preflight_report() {
    lp_stage "preflight"
    lp_load_os
    lp_check_disk
    lp_detect_stack
    lp_detect_existing_installation
    lp_load_existing_configuration
    lp_parse_base_url
    lp_validate_inputs
    lp_detect_conflicts
    lp_log "Operating system: Ubuntu 24.04 LTS"
    lp_log "Architecture: $(uname -m)"
    lp_log "Stack: $LP_STACK_MODE"
    lp_log "Installation root: $LP_INSTALL_ROOT"
    lp_log "Existing MicroSysAdmin: $([[ "$LP_EXISTING" -eq 1 ]] && printf yes || printf no)"
    lp_log "Free disk: $(df -hP "$LP_SOURCE_ROOT" | awk 'NR==2 {print $4}')"
    local component path
    for component in apache2 nginx php mysql mariadb; do
        path="$(command -v "$component" 2>/dev/null || true)"
        lp_log "Detected $component: ${path:-not installed}"
    done
    if command -v ss >/dev/null 2>&1; then
        local listeners
        listeners="$(ss -ltnp 2>/dev/null | awk 'NR>1 && ($4 ~ /:80$/ || $4 ~ /:443$/ || $4 ~ /:3306$/) {print $4 " " $NF}')"
        [[ -z "$listeners" ]] || printf '%s\n' "$listeners" | sed 's/^/[MicroSysAdmin] Existing listener: /'
    fi
}

lp_detect_conflicts() {
    [[ "$LP_STACK_MODE" == "managed" ]] || return 0
    if pgrep -x nginx >/dev/null 2>&1 || lp_package_installed nginx || lp_package_installed nginx-core; then
        lp_fail "Nginx is installed or running. MicroSysAdmin will not overwrite or compete with an unrelated web server. Remove/relocate Nginx or use a dedicated Ubuntu host."
    fi
    if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
        if lp_package_installed mysql-server || lp_package_installed mysql-community-server; then
            lp_fail "An unmanaged MySQL server is installed. Managed MicroSysAdmin uses MariaDB; use an external database host or a dedicated Ubuntu machine instead of replacing the existing server."
        fi
        if command -v ss >/dev/null 2>&1; then
            local database_owner
            database_owner="$(ss -ltnp 2>/dev/null | awk '$4 ~ /:3306$/ {print $NF; exit}')"
            if [[ -n "$database_owner" && "$database_owner" != *mariadbd* ]] \
                && ! lp_package_installed mariadb-server; then
                lp_fail "Port 3306 is already owned by an unmanaged database process: $database_owner. Use an external database or a dedicated Ubuntu host."
            fi
        fi
    fi
    local incompatible_php_modules
    incompatible_php_modules="$(dpkg-query -W -f='${binary:Package} ${Status}\n' 'libapache2-mod-php[0-9]*' 2>/dev/null \
        | awk '$2 == "install" && $4 == "installed" && $1 !~ /libapache2-mod-php8\.3/ {print $1}' || true)"
    if [[ -n "$incompatible_php_modules" ]]; then
        lp_fail "A conflicting Apache PHP module is installed: $incompatible_php_modules. Managed MicroSysAdmin requires the Ubuntu PHP 8.3 module on a dedicated or compatible host."
    fi
    if command -v ss >/dev/null 2>&1; then
        local port_owner
        port_owner="$(ss -ltnp 2>/dev/null | awk '$4 ~ /:80$/ {print $NF; exit}')"
        [[ -z "$port_owner" || "$port_owner" == *apache2* ]] \
            || lp_fail "Port 80 is already owned by an unrelated process: $port_owner"
    fi
    if [[ "$LP_BASE_URL_INPUT" == https://* ]] && ! ss -ltn 2>/dev/null | awk '$4 ~ /:443$/ {found=1} END {exit(found ? 0 : 1)}'; then
        lp_fail "An HTTPS URL was requested, but no TLS listener exists on port 443. Configure TLS first or install with an http:// URL behind a private network/VPN."
    fi
}

lp_check_internet() {
    lp_stage "Internet connectivity"
    local attempt
    for attempt in 1 2 3; do
        if command -v curl >/dev/null 2>&1; then
            if curl --fail --silent --show-error --location --connect-timeout 8 --max-time 20 \
                https://archive.ubuntu.com/ubuntu/dists/noble/Release --output /dev/null; then
                return 0
            fi
        elif getent ahosts archive.ubuntu.com >/dev/null 2>&1; then
            return 0
        fi
        [[ "$attempt" -eq 3 ]] || sleep 2
    done
    lp_fail "DNS/Internet access to Ubuntu package servers is required to install missing dependencies. Verify DNS, proxy, firewall, and default-route configuration."
}

lp_package_installed() {
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'
}

lp_install_packages() {
    lp_stage "operating-system packages"
    local packages=(ca-certificates curl git openssl rsync ripgrep unzip zip cron logrotate iproute2 jq)
    if [[ "$LP_STACK_MODE" == "managed" ]]; then
        packages+=(apache2 mariadb-client php8.3-cli libapache2-mod-php8.3 php8.3-curl php8.3-mbstring php8.3-mysql php8.3-xml php8.3-zip composer)
        if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
            packages+=(mariadb-server)
        fi
    fi
    local missing=() package
    for package in "${packages[@]}"; do
        lp_package_installed "$package" || missing+=("$package")
    done
    if [[ "${#missing[@]}" -gt 0 ]]; then
        lp_check_internet
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing[@]}"
    fi
    command -v git >/dev/null 2>&1 || lp_fail "Git installation did not complete."
    command -v rsync >/dev/null 2>&1 || lp_fail "rsync installation did not complete."
}

lp_systemctl_usable() {
    [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1
}

lp_start_managed_services() {
    [[ "$LP_STACK_MODE" == "managed" ]] || return 0
    lp_stage "managed Apache, database, and scheduler services"
    if lp_systemctl_usable; then
        systemctl enable --now apache2.service cron.service
        if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
            systemctl enable --now mariadb.service
        fi
    else
        service apache2 start
        service cron start || true
        if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
            service mariadb start
        fi
    fi
    if command -v ss >/dev/null 2>&1; then
        local port80
        port80="$(ss -ltnp 2>/dev/null | awk '$4 ~ /:80$/ {print $NF; exit}')"
        [[ -z "$port80" || "$port80" == *apache2* ]] \
            || lp_fail "Port 80 is owned by an unrelated service: $port80. Stop it or select a non-conflicting host before installing."
    fi
}

lp_validate_xampp() {
    [[ "$LP_STACK_MODE" == "xampp" ]] || return 0
    lp_stage "XAMPP compatibility"
    local status
    status="$(/opt/lampp/lampp status 2>&1 || true)"
    [[ "$status" == *"Apache is running"* && ( "$status" == *"MySQL is running"* || "$status" == *"MariaDB is running"* ) ]] \
        || lp_fail "Existing XAMPP is not healthy. Start Apache and MySQL with sudo /opt/lampp/lampp start, then retry."
}

lp_validate_php() {
    lp_stage "PHP runtime"
    [[ -x "$LP_PHP_BINARY" ]] || lp_fail "PHP binary is unavailable: $LP_PHP_BINARY"
    "$LP_PHP_BINARY" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
        || lp_fail "MicroSysAdmin requires PHP 8.2 or newer."
    local extension
    for extension in pdo pdo_mysql openssl json mbstring zip curl; do
        # The single-quoted PHP expression is intentionally interpreted by PHP.
        # shellcheck disable=SC2016
        "$LP_PHP_BINARY" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension" \
            || lp_fail "Required PHP extension is missing: $extension"
    done
}

lp_find_composer() {
    local candidate
    for candidate in /usr/bin/composer /usr/local/bin/composer /usr/local/lib/microsysadmin/composer.phar; do
        if [[ -f "$candidate" && -r "$candidate" ]]; then
            LP_COMPOSER_BINARY="$candidate"
            return 0
        fi
    done
    return 1
}

lp_install_composer() {
    if lp_find_composer; then
        return 0
    fi
    lp_stage "Composer"
    lp_check_internet
    mkdir -p /usr/local/lib/microsysadmin
    local installer="$LP_TEMP_ROOT/composer-setup.php" expected actual
    curl --fail --silent --show-error --location https://getcomposer.org/installer --output "$installer"
    expected="$(curl --fail --silent --show-error --location https://composer.github.io/installer.sig)"
    actual="$(sha384sum "$installer" | awk '{print $1}')"
    [[ "$expected" =~ ^[0-9a-fA-F]{96}$ && "${expected,,}" == "${actual,,}" ]] \
        || lp_fail "Composer installer signature verification failed."
    "$LP_PHP_BINARY" "$installer" --quiet --install-dir=/usr/local/lib/microsysadmin --filename=composer.phar
    LP_COMPOSER_BINARY="/usr/local/lib/microsysadmin/composer.phar"
}

lp_prepare_target() {
    lp_stage "application files"
    if [[ "$LP_SOURCE_ROOT" == "$LP_INSTALL_ROOT" ]]; then
        return 0
    fi
    if [[ -e "$LP_INSTALL_ROOT" && ! -d "$LP_INSTALL_ROOT" ]]; then
        lp_fail "Installation target exists and is not a directory: $LP_INSTALL_ROOT"
    fi
    if [[ -d "$LP_INSTALL_ROOT" && -n "$(find "$LP_INSTALL_ROOT" -mindepth 1 -maxdepth 1 -print -quit)" && ! -f "$LP_INSTALL_ROOT/VERSION" ]]; then
        lp_fail "Installation target contains an unrelated application: $LP_INSTALL_ROOT"
    fi
    mkdir -p "$LP_INSTALL_ROOT"
    rsync -a --delete \
        --exclude=/.git/ --exclude=/.agents/ --exclude=/.codex/ --exclude=/dist/ \
        --exclude=/storage/ --exclude=/config/config.php \
        --exclude=/.env --exclude=/.microsysadmin-git.conf \
        "$LP_SOURCE_ROOT/" "$LP_INSTALL_ROOT/"
}

lp_install_dependencies() {
    lp_stage "locked local production dependencies"
    lp_install_composer
    mkdir -p "$LP_PRIVATE_ROOT/composer"
    chmod 0770 "$LP_PRIVATE_ROOT/composer"
    "$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/materialize-locked-composer-vendor.php" \
        "--root=$LP_INSTALL_ROOT"
    "$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/verify-locked-composer-vendor.php" \
        "--root=$LP_INSTALL_ROOT"
    "$LP_PHP_BINARY" "$LP_COMPOSER_BINARY" validate \
        --working-dir="$LP_INSTALL_ROOT" --no-check-publish --no-interaction
}

lp_protect_initial_admin_credentials() {
    local credentials="$LP_PRIVATE_ROOT/initial-administrator.txt"
    [[ -f "$credentials" ]] || return 0
    chown root:root "$credentials"
    chmod 0600 "$credentials"
}

lp_prepare_runtime_directories() {
    lp_stage "persistent runtime directories"
    mkdir -p "$LP_STORAGE_ROOT/logs" "$LP_STORAGE_ROOT/cache" "$LP_STORAGE_ROOT/scripts" \
        "$LP_STORAGE_ROOT/workspace" "$LP_STORAGE_ROOT/trash" \
        "$LP_STORAGE_ROOT/doctor/executions" "$LP_STORAGE_ROOT/doctor/reports" \
        "$LP_STORAGE_ROOT/doctor/backups" "$LP_STORAGE_ROOT/doctor/logs" \
        "$LP_STORAGE_ROOT/doctor/locks" "$LP_STORAGE_ROOT/doctor/composer-home/cache" \
        "$LP_PRIVATE_ROOT" \
        "$LP_BACKUP_ROOT" "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chown -R "$LP_SERVICE_USER:$LP_SERVICE_GROUP" \
        "$LP_STORAGE_ROOT" "$LP_PRIVATE_ROOT" "$LP_BACKUP_ROOT" "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chmod 0770 "$LP_STORAGE_ROOT" "$LP_PRIVATE_ROOT" "$LP_BACKUP_ROOT" \
        "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chmod 2770 "$LP_STORAGE_ROOT/doctor" "$LP_STORAGE_ROOT/doctor/executions" \
        "$LP_STORAGE_ROOT/doctor/reports" "$LP_STORAGE_ROOT/doctor/backups" \
        "$LP_STORAGE_ROOT/doctor/logs" "$LP_STORAGE_ROOT/doctor/locks" \
        "$LP_STORAGE_ROOT/doctor/composer-home" "$LP_STORAGE_ROOT/doctor/composer-home/cache"
    lp_protect_initial_admin_credentials
}

lp_sql_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\'\'}"
    printf '%s' "$value"
}

lp_create_local_database() {
    lp_stage "database and database user"
    [[ -n "$LP_DB_PASSWORD" ]] || LP_DB_PASSWORD="$(openssl rand -hex 32)"
    local admin_user="$LP_DB_ADMIN_USER"
    local admin_password="$LP_DB_ADMIN_PASSWORD"
    local db_user_escaped db_password_escaped sql_file
    db_user_escaped="$(lp_sql_escape "$LP_DB_USER")"
    db_password_escaped="$(lp_sql_escape "$LP_DB_PASSWORD")"
    local mysql_args=("$LP_MYSQL_BINARY")
    if [[ -n "$LP_DB_SOCKET" ]]; then
        mysql_args+=("--socket=$LP_DB_SOCKET")
    else
        mysql_args+=("--host=127.0.0.1" "--port=$LP_DB_PORT")
    fi
    mysql_args+=("--user=$admin_user")
    local collision_query collision_state
    collision_query="SELECT CONCAT((SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$(lp_sql_escape "$LP_DB_NAME")'), ':', (SELECT COUNT(*) FROM mysql.user WHERE User='$db_user_escaped' AND Host='localhost'));"
    collision_state="$(MYSQL_PWD="$admin_password" "${mysql_args[@]}" --batch --skip-column-names --execute="$collision_query" 2>/dev/null)" \
        || lp_fail "Unable to inspect existing databases/accounts safely. Set valid MICROSYSADMIN_DB_ADMIN_USER and MICROSYSADMIN_DB_ADMIN_PASSWORD."
    [[ "$collision_state" == "0:0" ]] \
        || lp_fail "Database '$LP_DB_NAME' or account '$LP_DB_USER'@'localhost' already exists without an active MicroSysAdmin configuration. Refusing to overwrite potentially unrelated data; restore the matching configuration or choose a dedicated external database."
    sql_file="$LP_TEMP_ROOT/database-bootstrap.sql"
    umask 077
    {
        # Backticks here quote a MySQL identifier; they are printf data, not shell substitution.
        # shellcheck disable=SC2016,SC2006
        printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$LP_DB_NAME"
        printf "CREATE USER '%s'@'localhost' IDENTIFIED BY '%s';\n" "$db_user_escaped" "$db_password_escaped"
        # shellcheck disable=SC2016
        printf 'GRANT ALL PRIVILEGES ON `%s`.* TO '\''%s'\''@'\''localhost'\'';\nFLUSH PRIVILEGES;\n' "$LP_DB_NAME" "$db_user_escaped"
    } > "$sql_file"
    chmod 0600 "$sql_file"
    MYSQL_PWD="$admin_password" "${mysql_args[@]}" < "$sql_file" \
        || lp_fail "Unable to create the MicroSysAdmin database/user. Set MICROSYSADMIN_DB_ADMIN_USER and MICROSYSADMIN_DB_ADMIN_PASSWORD when root socket authentication is unavailable."
    : > "$sql_file"
}

lp_validate_external_database() {
    lp_stage "external database connection"
    [[ -n "$LP_DB_USER" && -n "$LP_DB_PASSWORD" ]] \
        || lp_fail "External database mode requires MICROSYSADMIN_DB_USER and MICROSYSADMIN_DB_PASSWORD."
    local args=("$LP_MYSQL_BINARY" "--host=$LP_DB_HOST" "--port=$LP_DB_PORT" "--user=$LP_DB_USER" "--database=$LP_DB_NAME" "--execute=SELECT 1")
    MYSQL_PWD="$LP_DB_PASSWORD" "${args[@]}" >/dev/null \
        || lp_fail "External database connection failed. Create the database/user or provide valid credentials."
}

lp_prepare_database() {
    if [[ "$LP_DB_HOST" == "127.0.0.1" || "$LP_DB_HOST" == "localhost" ]]; then
        lp_create_local_database
    else
        LP_DB_SOCKET=""
        lp_validate_external_database
    fi
}

lp_existing_action() {
    [[ "$LP_EXISTING" -eq 1 ]] || return 0
    if [[ "$LP_ACTION" != "install" ]]; then
        return 0
    fi
    if [[ -t 0 && "$LP_NON_INTERACTIVE" -eq 0 ]]; then
        local choice=""
        printf 'Existing MicroSysAdmin installation detected at %s.\n' "$LP_INSTALL_ROOT"
        read -r -p "Choose validate, repair, upgrade, or abort [validate]: " choice
        case "${choice,,}" in
            ""|validate) LP_ACTION="validate" ;;
            repair) LP_ACTION="repair" ;;
            upgrade) LP_ACTION="upgrade" ;;
            abort) lp_fail "Installation aborted without changes." ;;
            *) lp_fail "Unknown existing-installation action: $choice" ;;
        esac
    else
        LP_ACTION="validate"
        lp_log "Existing MicroSysAdmin detected; selecting non-destructive validation. Use --repair or --upgrade explicitly to change it."
    fi
}

lp_create_existing_backup() {
    lp_stage "verified pre-change backup"
    local stamp app_parent app_name database_output database_archive
    local app_kb database_bytes database_kb required_kb available_kb
    app_kb="$(du -sk "$LP_INSTALL_ROOT" | awk '{print $1}')"
    # This query returns only a size estimate; no database credentials are emitted.
    # shellcheck disable=SC2016
    database_bytes="$("$LP_PHP_BINARY" -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; echo (int)SysAdmin\Database::pdo()->query("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();' "$LP_INSTALL_ROOT" 2>/dev/null || printf 536870912)"
    [[ "$database_bytes" =~ ^[0-9]+$ ]] || database_bytes=536870912
    database_kb=$((database_bytes / 1024))
    required_kb=$(((app_kb + database_kb) * 2 + 262144))
    available_kb="$(df -Pk /var/lib | awk 'NR==2 {print $4}')"
    [[ "$available_kb" =~ ^[0-9]+$ && "$available_kb" -ge "$required_kb" ]] \
        || lp_fail "Insufficient rollback space. Repair/upgrade requires $((required_kb / 1024)) MiB free under /var/lib; $(( ${available_kb:-0} / 1024 )) MiB is available."
    lp_log "Rollback capacity verified: $((required_kb / 1024)) MiB required, $((available_kb / 1024)) MiB available."
    stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
    LP_EXISTING_BACKUP="/var/lib/microsysadmin/installer-backups/$stamp"
    mkdir -p "$LP_EXISTING_BACKUP"
    chmod 0700 "$LP_EXISTING_BACKUP"
    app_parent="$(dirname "$LP_INSTALL_ROOT")"
    app_name="$(basename "$LP_INSTALL_ROOT")"
    tar --exclude="$app_name/.git" --exclude="$app_name/storage/cache" --exclude="$app_name/storage/workspace" \
        -czf "$LP_EXISTING_BACKUP/application.tar.gz" -C "$app_parent" "$app_name"
    tar -tzf "$LP_EXISTING_BACKUP/application.tar.gz" >/dev/null
    database_output="$("$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/backup_database.php")"
    database_archive="${database_output##* }"
    [[ -f "$database_archive" ]] || lp_fail "The database backup service did not create an archive."
    cp "$database_archive" "$LP_EXISTING_BACKUP/"
    unzip -t "$LP_EXISTING_BACKUP/$(basename "$database_archive")" >/dev/null
    sha256sum "$LP_EXISTING_BACKUP/application.tar.gz" "$LP_EXISTING_BACKUP/$(basename "$database_archive")" \
        > "$LP_EXISTING_BACKUP/SHA256SUMS"
    sha256sum -c "$LP_EXISTING_BACKUP/SHA256SUMS" >/dev/null
    chmod 0600 "$LP_EXISTING_BACKUP"/*
    LP_ROLLBACK_READY=1
    lp_log "Verified rollback material: $LP_EXISTING_BACKUP"
}

lp_run_php_install() {
    lp_stage "configuration, schema, administrator, and security profile"
    local service_driver="systemd" xampp_root="/opt/microsysadmin"
    if [[ "$LP_STACK_MODE" == "xampp" ]]; then
        service_driver="xampp"
        xampp_root="/opt/lampp"
    fi
    MICROSYSADMIN_INSTALL_CONTEXT=1 MICROSYSADMIN_ADMIN_PASSWORD_GENERATED="$LP_ADMIN_GENERATED" \
    MICROSYSADMIN_ADMIN_PASSWORD="$LP_ADMIN_PASSWORD" MICROSYSADMIN_DB_PASSWORD="$LP_DB_PASSWORD" \
    MICROSYSADMIN_ADMIN_USERNAME="$LP_ADMIN_USERNAME" MICROSYSADMIN_ADMIN_EMAIL="$LP_ADMIN_EMAIL" \
    MICROSYSADMIN_BASE_URL="$LP_BASE_URL_INPUT" MICROSYSADMIN_SECURITY_PROFILE="$LP_SECURITY_PROFILE" \
    "$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/install-production.php" \
        --admin-username="$LP_ADMIN_USERNAME" \
        --admin-email="$LP_ADMIN_EMAIL" \
        --security-profile="$LP_SECURITY_PROFILE" \
        --base-url="$LP_BASE_PATH" \
        --db-host="$LP_DB_HOST" --db-port="$LP_DB_PORT" --db-socket="$LP_DB_SOCKET" \
        --db-name="$LP_DB_NAME" --db-user="$LP_DB_USER" \
        --xampp-root="$xampp_root" --php-binary="$LP_PHP_BINARY" \
        --composer-binary="$LP_COMPOSER_BINARY" --git-binary="$(command -v git)" \
        --bash-binary="$(command -v bash)" --mysql-binary="$LP_MYSQL_BINARY" \
        --mysqldump-binary="$LP_MYSQLDUMP_BINARY" \
        --document-root="$LP_DOCUMENT_ROOT" --allowed-root="$LP_DOCUMENT_ROOT" \
        --storage-root="$LP_STORAGE_ROOT" --private-root="$LP_PRIVATE_ROOT" \
        --backup-root="$LP_BACKUP_ROOT" --trash-root="$LP_STORAGE_ROOT/trash" \
        --scripts-root="$LP_STORAGE_ROOT/scripts" --workspace-root="$LP_STORAGE_ROOT/workspace" \
        --service-driver="$service_driver" --terminal-available=0 \
        --service-user="$LP_SERVICE_USER" --service-group="$LP_SERVICE_GROUP" \
        --system-integration=install
}

lp_write_apache_config() {
    lp_stage "Apache routing"
    local escaped_root="$LP_INSTALL_ROOT" escaped_path="$LP_BASE_PATH" temp_config
    [[ "$escaped_root" != *'"'* && "$escaped_root" != *$'\n'* ]] || lp_fail "Installation path cannot contain quotes or newlines."
    temp_config="$LP_TEMP_ROOT/microsysadmin-apache.conf"
    cat > "$temp_config" <<APACHE
# Managed by MicroSysAdmin installer v${LP_INSTALLER_VERSION}.
Alias "${escaped_path}/assets/" "${escaped_root}/assets/"
Alias "${escaped_path}" "${escaped_root}/public"

<Directory "${escaped_root}/assets">
    Options -Indexes
    AllowOverride None
    Require all granted
</Directory>

<Directory "${escaped_root}/public">
    Options -Indexes
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
    FallbackResource "${escaped_path}/index.php"
</Directory>
APACHE
    chmod 0644 "$temp_config"
    if [[ "$LP_STACK_MODE" == "managed" ]]; then
        LP_APACHE_CONFIG="/etc/apache2/conf-available/microsysadmin.conf"
        if [[ -f "$LP_APACHE_CONFIG" ]]; then
            LP_APACHE_CONFIG_EXISTED=1
            LP_APACHE_CONFIG_BACKUP="$LP_TEMP_ROOT/apache-microsysadmin.conf.before"
            cp "$LP_APACHE_CONFIG" "$LP_APACHE_CONFIG_BACKUP"
        fi
        cp "$temp_config" "$LP_APACHE_CONFIG"
        a2enmod alias dir headers rewrite >/dev/null
        a2enconf microsysadmin >/dev/null
        apache2ctl configtest
        if lp_systemctl_usable; then systemctl restart apache2.service; else apache2ctl graceful; fi
    else
        LP_APACHE_CONFIG="/opt/lampp/etc/extra/httpd-microsysadmin.conf"
        if [[ -f "$LP_APACHE_CONFIG" ]]; then
            LP_APACHE_CONFIG_EXISTED=1
            LP_APACHE_CONFIG_BACKUP="$LP_TEMP_ROOT/apache-microsysadmin.conf.before"
            cp "$LP_APACHE_CONFIG" "$LP_APACHE_CONFIG_BACKUP"
        fi
        cp "$temp_config" "$LP_APACHE_CONFIG"
        if ! grep -Fq 'Include etc/extra/httpd-microsysadmin.conf' /opt/lampp/etc/httpd.conf; then
            LP_XAMPP_HTTPD_BACKUP="$LP_TEMP_ROOT/xampp-httpd.conf.before"
            cp /opt/lampp/etc/httpd.conf "$LP_XAMPP_HTTPD_BACKUP"
            printf '\n# MicroSysAdmin managed include\nInclude etc/extra/httpd-microsysadmin.conf\n' >> /opt/lampp/etc/httpd.conf
        fi
        /opt/lampp/bin/apachectl configtest
        /opt/lampp/lampp restartapache
    fi
}

lp_apply_permissions() {
    lp_stage "ownership and permissions"
    mkdir -p "$LP_STORAGE_ROOT/logs" "$LP_STORAGE_ROOT/cache" "$LP_STORAGE_ROOT/scripts" \
        "$LP_STORAGE_ROOT/workspace" "$LP_STORAGE_ROOT/trash" \
        "$LP_STORAGE_ROOT/doctor/executions" "$LP_STORAGE_ROOT/doctor/reports" \
        "$LP_STORAGE_ROOT/doctor/backups" "$LP_STORAGE_ROOT/doctor/logs" \
        "$LP_STORAGE_ROOT/doctor/locks" "$LP_STORAGE_ROOT/doctor/composer-home/cache" \
        "$LP_PRIVATE_ROOT" "$LP_BACKUP_ROOT" \
        "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chown -R "$LP_SERVICE_USER:$LP_SERVICE_GROUP" "$LP_STORAGE_ROOT" "$LP_PRIVATE_ROOT" "$LP_BACKUP_ROOT" "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chmod 0770 "$LP_STORAGE_ROOT" "$LP_PRIVATE_ROOT" "$LP_BACKUP_ROOT" "$LP_RELEASE_ROOT" "$LP_DOCUMENT_ROOT"
    chmod 2770 "$LP_STORAGE_ROOT/doctor" "$LP_STORAGE_ROOT/doctor/executions" \
        "$LP_STORAGE_ROOT/doctor/reports" "$LP_STORAGE_ROOT/doctor/backups" \
        "$LP_STORAGE_ROOT/doctor/logs" "$LP_STORAGE_ROOT/doctor/locks" \
        "$LP_STORAGE_ROOT/doctor/composer-home" "$LP_STORAGE_ROOT/doctor/composer-home/cache"
    lp_protect_initial_admin_credentials
    chown root:"$LP_SERVICE_GROUP" "$LP_INSTALL_ROOT/config/config.php"
    chmod 0640 "$LP_INSTALL_ROOT/config/config.php"
    if [[ "$LP_SOURCE_ROOT" != "$LP_INSTALL_ROOT" ]]; then
        chown -R root:"$LP_SERVICE_GROUP" "$LP_INSTALL_ROOT"
        find "$LP_INSTALL_ROOT" -type d -exec chmod 0750 {} +
        find "$LP_INSTALL_ROOT" -type f -exec chmod 0640 {} +
        find "$LP_INSTALL_ROOT" -type f \( -name '*.sh' -o -name 'microsysadmin-git' \) -exec chmod 0750 {} +
    fi
    chmod 0750 "$LP_INSTALL_ROOT/public" "$LP_INSTALL_ROOT/assets"
}

lp_store_generated_admin_credentials() {
    [[ "$LP_ADMIN_GENERATED" -eq 1 ]] || return 0
    lp_stage "root-only initial administrator credentials"
    LP_INITIAL_ADMIN_CREDENTIALS="$LP_PRIVATE_ROOT/initial-administrator.txt"
    local temporary="$LP_TEMP_ROOT/initial-administrator.txt"
    umask 077
    {
        printf 'username=%s\n' "$LP_ADMIN_USERNAME"
        printf 'email=%s\n' "$LP_ADMIN_EMAIL"
        printf 'password=%s\n' "$LP_ADMIN_PASSWORD"
        printf 'password_change_required=yes\n'
    } > "$temporary"
    chown root:root "$temporary"
    chmod 0600 "$temporary"
    mv "$temporary" "$LP_INITIAL_ADMIN_CREDENTIALS"
    lp_protect_initial_admin_credentials
}

lp_write_state() {
    lp_stage "installation record"
    mkdir -p "$LP_STATE_ROOT"
    chmod 0755 "$LP_STATE_ROOT"
    {
        printf 'SCHEMA_VERSION=%s\n' "$LP_INSTALLER_VERSION"
        printf 'INSTALL_ROOT=%s\n' "$LP_INSTALL_ROOT"
        printf 'STACK_MODE=%s\n' "$LP_STACK_MODE"
        printf 'BASE_PATH=%s\n' "$LP_BASE_PATH"
        printf 'SOURCE_ROOT=%s\n' "$LP_SOURCE_ROOT"
    } > "$LP_STATE_FILE"
    chmod 0644 "$LP_STATE_FILE"
}

lp_initial_backup() {
    lp_stage "initial verified database backup"
    local output archive
    output="$("$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/backup_database.php")"
    archive="${output##* }"
    [[ -f "$archive" ]] || lp_fail "Initial database backup was not created."
    unzip -t "$archive" >/dev/null
    sha256sum "$archive" > "$archive.sha256"
    chmod 0640 "$archive" "$archive.sha256"
    chown "$LP_SERVICE_USER:$LP_SERVICE_GROUP" "$archive" "$archive.sha256"
    lp_log "Initial database backup: $archive"
}

lp_validate_installation() {
    lp_stage "diagnostics, authentication, licensing, health gate, and HTTP"
    MICROSYSADMIN_ADMIN_USERNAME="$LP_ADMIN_USERNAME" MICROSYSADMIN_ADMIN_PASSWORD="$LP_ADMIN_PASSWORD" \
        "$LP_PHP_BINARY" "$LP_INSTALL_ROOT/scripts/install-gate.php"
    "$LP_INSTALL_ROOT/diagnose.sh" > "$LP_LOG_ROOT/diagnostics-latest.json"
    chmod 0600 "$LP_LOG_ROOT/diagnostics-latest.json"
    "$LP_INSTALL_ROOT/health-check.sh" --release-gate > "$LP_LOG_ROOT/health-gate-latest.json"
    chmod 0600 "$LP_LOG_ROOT/health-gate-latest.json"
    local response_file="$LP_TEMP_ROOT/http-response.html" http_url
    http_url="http://127.0.0.1${LP_BASE_PATH}/login"
    curl --fail --silent --show-error --location --max-time 20 "$http_url" --output "$response_file" \
        || lp_fail "MicroSysAdmin did not respond at $http_url. Check the Apache error log."
    grep -qi 'MicroSysAdmin' "$response_file" || lp_fail "The MicroSysAdmin login response was not recognized."
    lp_log "HTTP response verified: $http_url"
}

lp_validate_only() {
    lp_stage "existing installation validation"
    [[ -f "$LP_INSTALL_ROOT/config/config.php" ]] || lp_fail "Active configuration is missing from $LP_INSTALL_ROOT."
    LP_PHP_BINARY="$(lp_resolve_compatibility_php)" || lp_fail "Installed PHP runtime is unavailable."
    "$LP_INSTALL_ROOT/diagnose.sh"
    "$LP_INSTALL_ROOT/health-check.sh" --release-gate
    lp_log "Existing installation validation completed without changing customer data or secrets."
}

lp_repair_or_upgrade() {
    lp_create_existing_backup
    lp_install_packages
    lp_start_managed_services
    lp_validate_xampp
    lp_validate_php
    if [[ "$LP_ACTION" == "upgrade" ]]; then
        lp_prepare_target
    fi
    lp_install_dependencies
    lp_prepare_runtime_directories
    lp_snapshot_system_integration
    lp_run_php_install
    lp_apply_permissions
    lp_write_apache_config
    lp_write_state
    lp_validate_installation
    LP_ROLLBACK_READY=0
    lp_log "MicroSysAdmin $LP_ACTION completed. Verified backup: $LP_EXISTING_BACKUP"
}

lp_uninstall() {
    lp_stage "safe uninstall"
    lp_require_root
    lp_detect_stack
    lp_detect_existing_installation
    [[ "$LP_EXISTING" -eq 1 ]] || lp_fail "No installed MicroSysAdmin configuration was found."
    local args=(--confirm)
    [[ "$LP_ASSUME_YES" -eq 1 ]] && args+=(--yes)
    [[ "$LP_PURGE_DATA" -eq 1 ]] && args+=(--purge-data)
    [[ "$LP_REMOVE_APPLICATION" -eq 1 ]] && args+=(--remove-application)
    [[ -z "$LP_CONFIRM_PURGE" ]] || args+=("--confirm-purge=$LP_CONFIRM_PURGE")
    exec "$LP_INSTALL_ROOT/uninstall.sh" "${args[@]}"
}

lp_finish_success() {
    LP_STAGE="complete"
    LP_ROLLBACK_READY=0
    lp_log "MicroSysAdmin installation completed successfully."
    lp_log "Login URL: $LP_LOGIN_URL"
    lp_log "Stack: $LP_STACK_MODE"
    lp_log "Application: $LP_INSTALL_ROOT"
    lp_log "Configuration: $LP_INSTALL_ROOT/config/config.php"
    lp_log "Data: /var/lib/microsysadmin"
    if [[ -n "$LP_INITIAL_ADMIN_CREDENTIALS" ]]; then
        lp_log "Initial Administrator credentials (root-only): $LP_INITIAL_ADMIN_CREDENTIALS"
        lp_log "Delete that file after the required first-login password change."
    fi
    lp_log "Log: $LP_LOG_FILE"
}

microsysadmin_installer_main() {
    LP_SOURCE_ROOT="$1"
    shift
    lp_parse_arguments "$@"
    if [[ "$LP_ACTION" == "help" ]]; then lp_usage; return 0; fi
    if [[ "$LP_EXPERT_MODE" -eq 1 && "$LP_ACTION" == "install" ]]; then
        lp_run_compatibility_installer
    fi
    if [[ "$LP_ACTION" == "uninstall" ]]; then
        lp_uninstall
    fi

    lp_parse_base_url
    lp_validate_inputs
    if [[ "$LP_ACTION" != "check" ]]; then
        lp_require_root
        lp_start_logging
        LP_TEMP_ROOT="$(mktemp -d /tmp/microsysadmin-install.XXXXXXXX)"
        chmod 0700 "$LP_TEMP_ROOT"
        trap lp_failure_trap ERR
        trap '[[ -z "${LP_TEMP_ROOT:-}" || "$LP_TEMP_ROOT" != /tmp/microsysadmin-install.* ]] || rm -rf -- "$LP_TEMP_ROOT"' EXIT
    fi
    lp_preflight_report
    if [[ "$LP_ACTION" == "check" ]]; then
        if [[ "$LP_EXISTING" -eq 1 ]]; then
            lp_validate_only
        fi
        lp_log "Preflight PASS. No changes were made."
        return 0
    fi
    lp_existing_action
    if [[ "$LP_ACTION" == "validate" ]]; then
        lp_validate_only
        lp_finish_success
        return 0
    fi
    if [[ "$LP_EXISTING" -eq 1 ]]; then
        [[ "$LP_ACTION" == "repair" || "$LP_ACTION" == "upgrade" ]] \
            || lp_fail "Existing installation requires validate, --repair, --upgrade, or abort."
        lp_repair_or_upgrade
        lp_finish_success
        return 0
    fi
    [[ "$LP_ACTION" == "install" ]] || lp_fail "--$LP_ACTION requires an existing MicroSysAdmin installation."

    lp_collect_admin
    lp_collect_database_credentials
    lp_validate_inputs
    lp_install_packages
    lp_start_managed_services
    lp_validate_xampp
    lp_validate_php
    lp_prepare_target
    lp_install_dependencies
    lp_prepare_runtime_directories
    lp_prepare_database
    lp_snapshot_system_integration
    lp_run_php_install
    lp_apply_permissions
    lp_store_generated_admin_credentials
    lp_write_apache_config
    lp_write_state
    lp_initial_backup
    lp_validate_installation
    lp_finish_success
}

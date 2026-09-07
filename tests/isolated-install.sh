#!/usr/bin/env bash
set -euo pipefail

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$source_root/scripts/runtime.sh"
php_binary="$(microsysadmin_resolve_php)"
mysql_binary="${MICROSYSADMIN_TEST_MYSQL_BINARY:-/opt/lampp/bin/mysql}"
mysql_socket="${MICROSYSADMIN_TEST_MYSQL_SOCKET:-/opt/lampp/var/mysql/mysql.sock}"
mysql_user="${MICROSYSADMIN_TEST_DB_USER:-root}"
mysql_password="${MICROSYSADMIN_TEST_DB_PASSWORD:-}"
test_root="$(mktemp -d /tmp/microsysadmin-isolated.XXXXXXXX)"
suffix="$(basename "$test_root" | tr -cd 'A-Za-z0-9')"
database_name="microsysadmin_test_${suffix}"
admin_password="Isolated-Owner-Password-74!"
service_user="${MICROSYSADMIN_TEST_SERVICE_USER:-daemon}"
service_group="${MICROSYSADMIN_TEST_SERVICE_GROUP:-daemon}"

if [[ ! "$database_name" =~ ^microsysadmin_test_[A-Za-z0-9]+$ ]]; then
    echo "Refusing to use an unsafe test database name." >&2
    exit 1
fi
if [[ ! -x "$mysql_binary" ]]; then
    echo "MySQL client not found: $mysql_binary" >&2
    exit 1
fi

cleanup() {
    MYSQL_PWD="$mysql_password" "$mysql_binary" \
        --socket="$mysql_socket" -u "$mysql_user" \
        -e "DROP DATABASE IF EXISTS \`$database_name\`" >/dev/null 2>&1 || true
    if [[ "${MICROSYSADMIN_KEEP_TEST_ARTIFACTS:-0}" == "1" ]]; then
        echo "Preserved isolated test artifacts: $test_root"
    elif [[ "$test_root" == /tmp/microsysadmin-isolated.* ]]; then
        rm -rf -- "$test_root"
    fi
}
trap cleanup EXIT

mkdir -p \
    "$test_root/release-a" \
    "$test_root/release-b" \
    "$test_root/install" \
    "$test_root/projects" \
    "$test_root/storage" \
    "$test_root/private" \
    "$test_root/releases"

export SOURCE_DATE_EPOCH=1785153600
"$source_root/build-release.sh" \
    --output "$test_root/release-a" \
    --channel development \
    --released-at 2026-07-27T12:00:00Z \
    --unsigned-development >/dev/null
"$source_root/build-release.sh" \
    --output "$test_root/release-b" \
    --channel development \
    --released-at 2026-07-27T12:00:00Z \
    --unsigned-development >/dev/null

version="$(tr -d '[:space:]' < "$source_root/VERSION")"
archive_a="$test_root/release-a/microsysadmin-$version.tar.gz"
archive_b="$test_root/release-b/microsysadmin-$version.tar.gz"
checksum_a="$(sha256sum "$archive_a" | awk '{print $1}')"
checksum_b="$(sha256sum "$archive_b" | awk '{print $1}')"
if [[ "$checksum_a" != "$checksum_b" ]]; then
    echo "Release build is not reproducible." >&2
    exit 1
fi
if tar -tzf "$archive_a" | grep -Eq '(^|/)(config/config\.php|\.env|tests)(/|$)|storage/logs/app\.log|storage/backups/.+\.(zip|sql|gz)$'; then
    echo "Release archive contains customer state or development tests." >&2
    exit 1
fi
if ! tar -tzf "$archive_a" | grep -x 'microsysadmin/.htaccess' >/dev/null; then
    echo "Release archive is missing the Apache protection file." >&2
    exit 1
fi
for required_doctor_path in \
    'microsysadmin/doctor/DoctorApplication.php' \
    'microsysadmin/doctor/engine/Registry/DoctorRegistry.php' \
    'microsysadmin/doctor/modules/artifact-integrity/module.json' \
    'microsysadmin/doctor/baselines/release-artifacts/manifest.json' \
    'microsysadmin/doctor/baselines/locked-composer-vendor.json' \
    'microsysadmin/scripts/materialize-locked-composer-vendor.php' \
    'microsysadmin/scripts/verify-locked-composer-vendor.php'; do
    if ! tar -tzf "$archive_a" | grep -Fx "$required_doctor_path" >/dev/null; then
        echo "Release archive is missing required Doctor runtime artifact: $required_doctor_path" >&2
        exit 1
    fi
done

tar -xzf "$archive_a" -C "$test_root/install"
app_root="$test_root/install/microsysadmin"
export MICROSYSADMIN_ADMIN_PASSWORD="$admin_password"
export MICROSYSADMIN_DB_PASSWORD="$mysql_password"
"$app_root/install.sh" \
    --admin-username=owner \
    --admin-email=owner@example.test \
    --security-profile=safe \
    --base-url=/microsysadmin-test \
    --db-host=127.0.0.1 \
    --db-port=3306 \
    --db-socket="$mysql_socket" \
    --db-name="$database_name" \
    --db-user="$mysql_user" \
    --xampp-root=/opt/lampp \
    --php-binary="$php_binary" \
    --composer-binary=/usr/bin/composer \
    --git-binary=/usr/bin/git \
    --bash-binary=/bin/bash \
    --mysql-binary="$mysql_binary" \
    --mysqldump-binary=/opt/lampp/bin/mysqldump \
    --document-root="$test_root/projects" \
    --allowed-root="$test_root/projects" \
    --storage-root="$test_root/storage" \
    --private-root="$test_root/private" \
    --backup-root="$test_root/storage/backups" \
    --trash-root="$test_root/storage/trash" \
    --scripts-root="$test_root/storage/scripts" \
    --workspace-root="$test_root/storage/workspace" \
    --service-driver=inspection \
    --terminal-available=0 \
    --service-user="$service_user" \
    --service-group="$service_group" \
    --system-integration=skip >"$test_root/installer-first.log"
unset MICROSYSADMIN_ADMIN_PASSWORD MICROSYSADMIN_DB_PASSWORD

"$app_root/install.sh" --system-integration=skip >"$test_root/installer-second.log"

export MICROSYSADMIN_ISOLATED_APP_ROOT="$app_root"
export MICROSYSADMIN_ISOLATED_PROJECTS_ROOT="$test_root/projects"
export MICROSYSADMIN_ISOLATED_STORAGE_ROOT="$test_root/storage"
export MICROSYSADMIN_ISOLATED_PRIVATE_ROOT="$test_root/private"
export MICROSYSADMIN_ISOLATED_RELEASE_ROOT="$test_root/releases"
export MICROSYSADMIN_ISOLATED_ADMIN_PASSWORD="$admin_password"
export MICROSYSADMIN_ISOLATED_ORIGINAL_VERSION="$version"
export MICROSYSADMIN_ISOLATED_SERVICE_USER="$service_user"
export MICROSYSADMIN_ISOLATED_SERVICE_GROUP="$service_group"
"$php_binary" "$source_root/tests/isolated-commercial-probe.php" >"$test_root/commercial-probe.json"
"$php_binary" "$source_root/tests/demo-login.php"

mkdir -p "$test_root/update-stage"
tar -xzf "$archive_a" -C "$test_root/update-stage"
printf '%s\n' '1.1.1-dev' >"$test_root/update-stage/microsysadmin/VERSION"
rm -f -- "$test_root/update-stage/microsysadmin/cron/runner.php"
tar --sort=name \
    --mtime="@$SOURCE_DATE_EPOCH" \
    --owner=0 --group=0 --numeric-owner \
    --pax-option=delete=atime,delete=ctime \
    -cf - -C "$test_root/update-stage" microsysadmin \
    | gzip -n >"$test_root/microsysadmin-1.1.1-dev.tar.gz"
update_checksum="$(sha256sum "$test_root/microsysadmin-1.1.1-dev.tar.gz" | awk '{print $1}')"
"$php_binary" "$app_root/scripts/release-manifest.php" \
    --version=1.1.1-dev \
    --channel=development \
    --released-at=2026-07-27T14:00:00Z \
    --sha256="$update_checksum" \
    --package-name=microsysadmin-1.1.1-dev.tar.gz \
    --package-url=https://updates.example.test/microsysadmin-1.1.1-dev.tar.gz \
    --output="$test_root/microsysadmin-1.1.1-dev.manifest.json" \
    --unsigned-development >/dev/null

if "$app_root/update.sh" apply \
    "$test_root/microsysadmin-1.1.1-dev.manifest.json" \
    "$test_root/microsysadmin-1.1.1-dev.tar.gz" >"$test_root/update.log" 2>&1; then
    echo "The deliberately broken update unexpectedly succeeded." >&2
    exit 1
fi
"$php_binary" "$source_root/tests/verify-isolated-rollback.php" >"$test_root/rollback-probe.json"

# The single-quoted program is intentionally interpreted by PHP, not Bash.
# shellcheck disable=SC2016
assertions="$("$php_binary" -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["assertions"]??0);' "$test_root/commercial-probe.json")"
echo "Isolated commercial workflow passed."
echo "  Release SHA-256: $checksum_a"
echo "  Commercial assertions: $assertions"
echo "  Installer idempotency: passed"
echo "  Failed-update rollback: passed"
echo "  Disposable database: $database_name"

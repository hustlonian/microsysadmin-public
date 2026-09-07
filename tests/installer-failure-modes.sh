#!/usr/bin/env bash
set -euo pipefail

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d /tmp/microsysadmin-installer-tests.XXXXXXXX)"
passed=0
failed=0

# shellcheck disable=SC2317
cleanup() {
    if [[ "$test_root" == /tmp/microsysadmin-installer-tests.* ]]; then
        rm -rf -- "$test_root"
    fi
}
trap cleanup EXIT

pass() { printf '[PASS] %s\n' "$1"; passed=$((passed + 1)); }
fail() { printf '[FAIL] %s\n' "$1" >&2; failed=$((failed + 1)); }

expect_failure() {
    local label="$1" expected="$2"
    shift 2
    local output status
    set +e
    output="$("$@" 2>&1)"
    status=$?
    set -e
    if [[ "$status" -ne 0 && "$output" == *"$expected"* ]]; then
        pass "$label"
    else
        printf '%s\n' "$output" | sed -n '1,30p' >&2
        fail "$label"
    fi
}

cat > "$test_root/unsupported-os" <<'OS'
ID=debian
VERSION_ID="12"
PRETTY_NAME="Debian GNU/Linux 12"
OS
expect_failure "unsupported operating system is rejected" "Unsupported operating system" \
    env MICROSYSADMIN_TEST_OS_RELEASE_FILE="$test_root/unsupported-os" "$source_root/install.sh" --check

expect_failure "insufficient disk is rejected" "Insufficient disk space" \
    env MICROSYSADMIN_MIN_DISK_MB=999999999 "$source_root/install.sh" --check

dependency_root="$test_root/fresh-dependencies"
mkdir -p "$dependency_root/scripts" "$dependency_root/doctor/sdk/Generation" "$dependency_root/doctor/baselines"
cp "$source_root/composer.json" "$source_root/composer.lock" "$dependency_root/"
cp "$source_root/scripts/materialize-locked-composer-vendor.php" "$source_root/scripts/verify-locked-composer-vendor.php" "$dependency_root/scripts/"
cp "$source_root/doctor/sdk/Generation/LockedComposerVendorCatalog.php" "$dependency_root/doctor/sdk/Generation/"
cp "$source_root/doctor/baselines/locked-composer-vendor.json" "$dependency_root/doctor/baselines/"
php_binary="${MICROSYSADMIN_PHP_BINARY:-}"
if [[ -z "$php_binary" ]]; then
    if [[ -x /opt/lampp/bin/php ]]; then php_binary=/opt/lampp/bin/php
    else php_binary="$(command -v php)"
    fi
fi
# Only locked dependency blobs are needed to reproduce a clone without vendor/.
# shellcheck disable=SC2016
"$php_binary" -r '
    $manifest = json_decode(file_get_contents($argv[1] . "/doctor/baselines/locked-composer-vendor.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest["artifacts"] as $artifact) {
        $target = $argv[2] . "/" . $artifact["baseline"];
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
        if (!copy($argv[1] . "/" . $artifact["baseline"], $target)) { exit(1); }
    }
' "$source_root" "$dependency_root"
# shellcheck disable=SC2016
if env TEST_SOURCE_ROOT="$source_root" TEST_DEPENDENCY_ROOT="$dependency_root" TEST_PHP_BINARY="$php_binary" bash -c '
    set -Eeuo pipefail
    source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
    LP_INSTALL_ROOT="$TEST_DEPENDENCY_ROOT"
    LP_PRIVATE_ROOT="$TEST_DEPENDENCY_ROOT/private"
    LP_PHP_BINARY="$TEST_PHP_BINARY"
    [[ ! -e "$LP_INSTALL_ROOT/vendor" ]]
    lp_install_dependencies
    [[ -f "$LP_INSTALL_ROOT/vendor/autoload.php" ]]
    lp_install_dependencies
' > "$test_root/dependencies.log" 2>&1; then
    pass "fresh clone restores and verifies locked dependencies idempotently"
else
    sed -n '1,30p' "$test_root/dependencies.log" >&2
    fail "fresh clone restores and verifies locked dependencies idempotently"
fi

secret_marker="Installer-Secret-Must-Remain-Redacted-91!"
set +e
secret_output="$("$source_root/install.sh" --admin-password="$secret_marker" 2>&1)"
secret_status=$?
set -e
if [[ "$secret_status" -ne 0 && "$secret_output" == *"Password command-line options are unsafe"* && "$secret_output" != *"$secret_marker"* ]]; then
    pass "command-line passwords are rejected without disclosure"
else
    fail "command-line passwords are rejected without disclosure"
fi

# The single-quoted programs are intentionally interpreted by the child Bash.
# shellcheck disable=SC2016
expect_failure "invalid administrator input is rejected" "Administrator username" \
    env TEST_SOURCE_ROOT="$source_root" bash -c '
        set -Eeuo pipefail
        source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
        LP_ADMIN_USERNAME=x
        LP_ADMIN_EMAIL=owner@example.test
        LP_ADMIN_PASSWORD="Runtime-Only-Test-Password-91!"
        LP_NON_INTERACTIVE=1
        lp_collect_admin
    '

# The generated password is never printed; root-only file metadata is checked
# by the privileged clean-install canary.
# shellcheck disable=SC2016
if env TEST_SOURCE_ROOT="$source_root" TEST_ROOT="$test_root" bash -c '
    set -Eeuo pipefail
    source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
    LP_ADMIN_USERNAME=""
    LP_ADMIN_EMAIL=""
    LP_ADMIN_PASSWORD=""
    lp_collect_admin
    [[ "$LP_ADMIN_USERNAME" == owner ]]
    [[ "$LP_ADMIN_EMAIL" == owner@microsysadmin.invalid ]]
    [[ "$LP_ADMIN_GENERATED" -eq 1 ]]
    [[ ${#LP_ADMIN_PASSWORD} -ge 12 ]]
'; then
    pass "generated Administrator credentials satisfy policy"
else
    fail "generated Administrator credentials satisfy policy"
fi

fake_bin="$test_root/bin"
mkdir -p "$fake_bin"
cat > "$fake_bin/pgrep" <<'FAKE'
#!/usr/bin/env bash
exit 0
FAKE
chmod +x "$fake_bin/pgrep"
# shellcheck disable=SC2016
expect_failure "running conflicting web server is rejected" "Nginx is installed or running" \
    env TEST_SOURCE_ROOT="$source_root" PATH="$fake_bin:$PATH" bash -c '
        set -Eeuo pipefail
        source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
        LP_STACK_MODE=managed
        lp_detect_conflicts
    '

cat > "$fake_bin/mysql-fail" <<'FAKE'
#!/usr/bin/env bash
exit 1
FAKE
chmod +x "$fake_bin/mysql-fail"
# shellcheck disable=SC2016
expect_failure "database connection failure is actionable" "External database connection failed" \
    env TEST_SOURCE_ROOT="$source_root" bash -c '
        set -Eeuo pipefail
        source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
        LP_MYSQL_BINARY="$1"
        LP_DB_HOST=database.invalid
        LP_DB_PORT=3306
        LP_DB_NAME=microsysadmin
        LP_DB_USER=microsysadmin
        LP_DB_PASSWORD="Runtime-Only-Database-Password-82!"
        lp_validate_external_database
    ' bash "$fake_bin/mysql-fail"

cat > "$fake_bin/mysql-collision" <<'FAKE'
#!/usr/bin/env bash
printf '1:1\n'
FAKE
chmod +x "$fake_bin/mysql-collision"
# shellcheck disable=SC2016
expect_failure "unrelated database and account are never overwritten" "Refusing to overwrite potentially unrelated data" \
    env TEST_SOURCE_ROOT="$source_root" TEST_ROOT="$test_root" bash -c '
        set -Eeuo pipefail
        source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
        LP_MYSQL_BINARY="$1"
        LP_DB_HOST=127.0.0.1
        LP_DB_PORT=3306
        LP_DB_SOCKET=""
        LP_DB_NAME=microsysadmin
        LP_DB_USER=microsysadmin
        LP_DB_PASSWORD="Runtime-Only-Database-Password-82!"
        LP_TEMP_ROOT="$TEST_ROOT"
        lp_create_local_database
    ' bash "$fake_bin/mysql-collision"

mkdir -p "$test_root/existing/config" "$test_root/source"
touch "$test_root/existing/config/config.php"
cat > "$test_root/install.conf" <<STATE
INSTALL_ROOT=$test_root/existing
STACK_MODE=managed
BASE_PATH=/microsysadmin
STATE
# The single-quoted program is intentionally interpreted by the child Bash.
# shellcheck disable=SC2016
expect_failure "changed stack is rejected for an existing installation" "installation record expects the managed stack" \
    env TEST_SOURCE_ROOT="$source_root" TEST_ROOT="$test_root" bash -c '
        set -Eeuo pipefail
        source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
        LP_SOURCE_ROOT="$TEST_ROOT/source"
        LP_STATE_FILE="$TEST_ROOT/install.conf"
        LP_INSTALL_ROOT=/opt/lampp/htdocs/microsysadmin
        LP_STACK_MODE=xampp
        lp_detect_existing_installation
    '

# shellcheck disable=SC2016
if env TEST_SOURCE_ROOT="$source_root" TEST_ROOT="$test_root" bash -c '
    set -Eeuo pipefail
    source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
    LP_SOURCE_ROOT="$TEST_ROOT/source"
    LP_STATE_FILE="$TEST_ROOT/install.conf"
    LP_INSTALL_ROOT=/opt/microsysadmin/app
    LP_STACK_MODE=managed
    lp_detect_existing_installation
    [[ "$LP_EXISTING" -eq 1 && "$LP_INSTALL_ROOT" == "$TEST_ROOT/existing" ]]
'; then
    pass "existing installation record is detected"
else
    fail "existing installation record is detected"
fi

# shellcheck disable=SC2016
if env TEST_SOURCE_ROOT="$source_root" bash -c '
    set -Eeuo pipefail
    source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
    LP_INSTALL_ROOT=/opt/lampp/htdocs/customer-panel
    LP_BASE_URL_EXPLICIT=0
    lp_default_xampp_base_url
    [[ "$LP_BASE_URL_INPUT" == "http://localhost/customer-panel" ]]
    LP_INSTALL_ROOT=/opt/lampp/htdocs/another-panel
    LP_BASE_URL_INPUT=https://panel.example.test/admin
    LP_BASE_URL_EXPLICIT=1
    lp_default_xampp_base_url
    [[ "$LP_BASE_URL_INPUT" == "https://panel.example.test/admin" ]]
'; then
    pass "XAMPP directory name maps to its localhost URL"
else
    fail "XAMPP directory name maps to its localhost URL"
fi

# shellcheck disable=SC2016
if env TEST_SOURCE_ROOT="$source_root" bash -c '
    set -Eeuo pipefail
    source "$TEST_SOURCE_ROOT/scripts/installer-lib.sh"
    [[ "$LP_BASE_URL_INPUT" == "http://localhost/microsysadmin" ]]
    [[ "$LP_DB_NAME" == microsysadmin ]]
'; then
    pass "MicroSysAdmin defaults to its dedicated URL and database"
else
    fail "MicroSysAdmin defaults to its dedicated URL and database"
fi

printf '\n%d passed, %d failed\n' "$passed" "$failed"
exit "$failed"

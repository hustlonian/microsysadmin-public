#!/usr/bin/env bash
set -Eeuo pipefail

source_checkout="${MICROSYSADMIN_CANARY_SOURCE_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
install_root="${MICROSYSADMIN_CANARY_INSTALL_ROOT:-/opt/microsysadmin/app}"
php_binary="${MICROSYSADMIN_PHP_BINARY:-/usr/bin/php}"
admin_username="${MICROSYSADMIN_ADMIN_USERNAME:-}"
admin_password="${MICROSYSADMIN_ADMIN_PASSWORD:-}"
admin_email="${MICROSYSADMIN_ADMIN_EMAIL:-}"
initial_credentials="/var/lib/microsysadmin/private/initial-administrator.txt"
if [[ ( -z "$admin_username" || -z "$admin_password" ) && -r "$initial_credentials" ]]; then
    admin_username="$(sed -n 's/^username=//p' "$initial_credentials" | head -n1)"
    admin_password="$(sed -n 's/^password=//p' "$initial_credentials" | head -n1)"
    admin_email="$(sed -n 's/^email=//p' "$initial_credentials" | head -n1)"
fi
original_version="$(tr -d '[:space:]' < "$install_root/VERSION")"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
report_root="${MICROSYSADMIN_CANARY_REPORT_ROOT:-/var/lib/microsysadmin/canary-reports/$timestamp}"
work_root="$(mktemp -d /tmp/microsysadmin-clean-canary.XXXXXXXX)"
results_tsv="$work_root/results.tsv"
passed=0
failed=0
source_commit="$(git -c safe.directory="$source_checkout" -C "$source_checkout" rev-parse HEAD 2>/dev/null || printf unknown)"

mkdir -p "$report_root"
chmod 0700 "$report_root"
touch "$results_tsv"

cleanup() {
    if [[ "$work_root" == /tmp/microsysadmin-clean-canary.* ]]; then
        rm -rf -- "$work_root"
    fi
}

write_reports() {
    local overall="PASS"
    [[ "$failed" -eq 0 ]] || overall="FAIL"
    jq -Rn \
        --arg overall "$overall" \
        --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg os "$(. /etc/os-release; printf '%s' "${PRETTY_NAME:-unknown}")" \
        --arg architecture "$(uname -m)" \
        --arg source_commit "$source_commit" \
        --arg original_version "$original_version" \
        --argjson passed "$passed" --argjson failed "$failed" \
        '[inputs | split("\t") | {name:.[0], status:.[1], evidence:.[2]}] as $checks |
        {schema_version:1, product:"MicroSysAdmin", overall:$overall, generated_at:$generated_at,
         operating_system:$os, architecture:$architecture, source_commit:$source_commit,
         original_version:$original_version, passed:$passed, failed:$failed, checks:$checks}' \
        < "$results_tsv" > "$report_root/canary-report.json"
    {
        printf '# MicroSysAdmin clean-install canary\n\n'
        printf -- '- Overall: **%s**\n' "$overall"
        printf -- '- Generated: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf -- '- Ubuntu: %s\n' "$(. /etc/os-release; printf '%s' "${PRETTY_NAME:-unknown}")"
        printf -- '- Architecture: %s\n' "$(uname -m)"
        printf -- '- Source commit: %s\n' "$source_commit"
        printf -- '- Checks: %d passed, %d failed\n\n' "$passed" "$failed"
        printf '| Check | Result | Evidence |\n|---|---|---|\n'
        while IFS=$'\t' read -r name status evidence; do
            printf '| %s | %s | %s |\n' "$name" "$status" "$evidence"
        done < "$results_tsv"
    } > "$report_root/canary-report.md"
    chmod 0600 "$report_root/canary-report.json" "$report_root/canary-report.md"
}

on_exit() {
    local status=$?
    if [[ "$status" -ne 0 && "$failed" -eq 0 ]]; then
        failed=1
        printf 'unexpected failure\tFAIL\tcanary exited at an unrecorded stage\n' >> "$results_tsv"
    fi
    write_reports
    cleanup
    printf 'Canary reports: %s\n' "$report_root"
}
trap on_exit EXIT

record_pass() {
    local name="$1" evidence="$2"
    passed=$((passed + 1))
    printf '%s\tPASS\t%s\n' "$name" "$evidence" >> "$results_tsv"
    printf '[PASS] %s\n' "$name"
}

record_fail() {
    local name="$1" evidence="$2"
    failed=$((failed + 1))
    printf '%s\tFAIL\t%s\n' "$name" "$evidence" >> "$results_tsv"
    printf '[FAIL] %s: %s\n' "$name" "$evidence" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || record_fail "required command $1" "command is unavailable"
}

for command in curl git jq openssl tar unzip; do require_command "$command"; done
[[ -x "$php_binary" ]] || record_fail "installed PHP" "$php_binary is unavailable"
[[ -n "$admin_username" && -n "$admin_password" ]] || record_fail "automation credentials" "administrator environment is missing"
[[ -f "$install_root/config/config.php" ]] || record_fail "installed configuration" "config.php is missing"
record_pass "clean installation" "production configuration exists at the installed root"
# The single-quoted PHP expression is intentionally interpreted by PHP.
# shellcheck disable=SC2016
database_binding="$($php_binary -r '$c=require $argv[1]; define("APP_ROOT",dirname(dirname($argv[1]))); require APP_ROOT."/classes/bootstrap.php"; $name=(string)$c["database"]["name"]; $user=(string)$c["database"]["user"]; $active=(string)SysAdmin\Database::pdo()->query("SELECT DATABASE()")->fetchColumn(); $tables=(int)SysAdmin\Database::pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn(); echo $name,"|",$user,"|",$active,"|",$tables;' "$install_root/config/config.php")"
IFS='|' read -r configured_database configured_user active_database migrated_tables <<< "$database_binding"
if [[ "$configured_database" == microsysadmin && "$configured_user" == microsysadmin \
    && "$active_database" == microsysadmin && "$migrated_tables" =~ ^[1-9][0-9]*$ ]]; then
    record_pass "MySQL database binding" "the restricted MicroSysAdmin account is connected to the migrated microsysadmin database"
else
    record_fail "MySQL database binding" "production configuration is not tied to the expected migrated database"
fi
# The single-quoted PHP expression is intentionally interpreted by PHP.
# shellcheck disable=SC2016
generated_force_change="$($php_binary -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; $q=SysAdmin\Database::pdo()->prepare("SELECT force_password_change FROM users WHERE username = ?"); $q->execute([$argv[2]]); echo (int)$q->fetchColumn();' "$install_root" "$admin_username")"
if [[ -f "$initial_credentials" \
    && "$(stat -c '%a:%U:%G' "$initial_credentials")" == "600:root:root" \
    && "$generated_force_change" == "1" ]]; then
    record_pass "generated administrator protection" "initial credentials are root-only, unlogged, and require a first-login password change"
elif [[ -n "${MICROSYSADMIN_ADMIN_PASSWORD:-}" ]]; then
    record_pass "generated administrator protection" "administrator credentials were supplied through the protected automation environment"
else
    record_fail "generated administrator protection" "generated credentials are missing or have unsafe ownership/mode"
fi

# shellcheck disable=SC2016
base_path="$($php_binary -r '$c=require $argv[1]; echo rtrim((string)$c["app"]["base_url"], "/");' "$install_root/config/config.php")"
http_response="$work_root/login.html"
if curl --fail --silent --show-error --location --max-time 20 \
    "http://127.0.0.1${base_path}/login" --output "$http_response" \
    && grep -qi MicroSysAdmin "$http_response"; then
    record_pass "web application response" "login page returned a recognized successful response"
else
    record_fail "web application response" "login page did not respond successfully"
fi

if MICROSYSADMIN_ADMIN_USERNAME="$admin_username" MICROSYSADMIN_ADMIN_PASSWORD="$admin_password" \
    "$php_binary" "$install_root/scripts/install-gate.php" > "$report_root/install-gate.json"; then
    record_pass "first administrator authentication" "password hash and Administrator role verified without logging the password"
else
    record_fail "first administrator authentication" "installation gate rejected the first Administrator"
fi

if "$install_root/diagnose.sh" > "$report_root/diagnostics.json"; then
    record_pass "diagnostics" "diagnostics command completed"
else
    record_fail "diagnostics" "diagnostics command failed"
fi
if "$install_root/health-check.sh" --release-gate > "$report_root/health-before.json"; then
    record_pass "release-critical health gate" "all release-critical checks passed"
else
    record_fail "release-critical health gate" "one or more critical checks failed"
fi

permissions_ok=1
[[ "$(stat -c '%a:%U:%G' "$install_root/config/config.php")" == "640:root:www-data" ]] || permissions_ok=0
for protected_directory in /var/lib/microsysadmin/storage /var/lib/microsysadmin/private /var/lib/microsysadmin/backups /var/lib/microsysadmin/releases; do
    [[ "$(stat -c '%a:%U:%G' "$protected_directory")" == "770:www-data:www-data" ]] || permissions_ok=0
done
if [[ "$permissions_ok" -eq 1 ]]; then
    record_pass "secure permissions" "configuration and persistent data ownership/modes match the managed-stack policy"
else
    record_fail "secure permissions" "one or more protected paths has an unsafe owner or mode"
fi

services_ok=1
if [[ -d /run/systemd/system ]]; then
    systemctl is-active apache2.service mariadb.service cron.service \
        microsysadmin-worker.service microsysadmin-health.timer > "$report_root/service-status.txt" 2>&1 || true
    for service_name in apache2.service mariadb.service cron.service microsysadmin-worker.service microsysadmin-health.timer; do
        systemctl is-active --quiet "$service_name" || services_ok=0
    done
else
    service apache2 status >/dev/null 2>&1 || services_ok=0
    service mariadb status >/dev/null 2>&1 || services_ok=0
    [[ -f /etc/cron.d/microsysadmin && -f /etc/logrotate.d/microsysadmin ]] || services_ok=0
fi
if [[ "$services_ok" -eq 1 ]]; then
    record_pass "service startup" "Apache, MariaDB, scheduler, worker, and health monitoring are active"
else
    record_fail "service startup" "one or more managed services or integrations is inactive"
fi

if MICROSYSADMIN_ADMIN_USERNAME="$admin_username" MICROSYSADMIN_ADMIN_EMAIL="${admin_email:-owner@microsysadmin.invalid}" \
    MICROSYSADMIN_ADMIN_PASSWORD="$admin_password" MICROSYSADMIN_BASE_URL="${MICROSYSADMIN_BASE_URL:-http://localhost/microsysadmin}" \
    "$source_checkout/install.sh" --non-interactive > "$report_root/idempotent-install.log"; then
    # shellcheck disable=SC2016
    user_count="$($php_binary -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; echo (int)SysAdmin\Database::pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn();' "$install_root")"
    if [[ "$user_count" == "1" ]]; then
        record_pass "installer idempotency" "second run selected validation and preserved the single Administrator"
    else
        record_fail "installer idempotency" "second run changed the Administrator count"
    fi
else
    record_fail "installer idempotency" "second sudo install invocation failed"
fi

if MICROSYSADMIN_ADMIN_USERNAME="$admin_username" MICROSYSADMIN_ADMIN_EMAIL="${admin_email:-owner@microsysadmin.invalid}" \
    MICROSYSADMIN_ADMIN_PASSWORD="$admin_password" MICROSYSADMIN_BASE_URL="${MICROSYSADMIN_BASE_URL:-http://localhost/microsysadmin}" \
    "$source_checkout/install.sh" --upgrade --non-interactive > "$report_root/installer-upgrade.log" \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-after-installer-upgrade.json"; then
    # shellcheck disable=SC2016
    user_count="$($php_binary -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; echo (int)SysAdmin\Database::pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn();' "$install_root")"
    if [[ "$user_count" == "1" ]]; then
        record_pass "installer upgrade" "verified pre-change backups were created and the Administrator and healthy installation were preserved"
    else
        record_fail "installer upgrade" "upgrade changed the Administrator count"
    fi
else
    record_fail "installer upgrade" "install.sh --upgrade did not preserve a healthy installation"
fi

broken_installer_source="$work_root/broken-installer-source"
rsync -a --exclude=/.git/ --exclude=/config/config.php --exclude=/storage/ --exclude=/vendor/ \
    "$source_checkout/" "$broken_installer_source/"
rm -f -- "$broken_installer_source/cron/runner.php"
if MICROSYSADMIN_ADMIN_USERNAME="$admin_username" MICROSYSADMIN_ADMIN_EMAIL="${admin_email:-owner@microsysadmin.invalid}" \
    MICROSYSADMIN_ADMIN_PASSWORD="$admin_password" MICROSYSADMIN_BASE_URL="${MICROSYSADMIN_BASE_URL:-http://localhost/microsysadmin}" \
    "$broken_installer_source/install.sh" --upgrade --non-interactive > "$report_root/failed-installer-upgrade.log" 2>&1; then
    record_fail "failed installer-upgrade rollback" "an upgrade missing a release-critical file unexpectedly succeeded"
elif [[ -f "$install_root/cron/runner.php" ]] \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-after-failed-installer-upgrade.json"; then
    record_pass "failed installer-upgrade rollback" "verified installer backup restored the application, Apache, and service integration after a late gate failure"
else
    record_fail "failed installer-upgrade rollback" "install.sh did not restore a healthy pre-upgrade installation"
fi

# shellcheck disable=SC2016
"$php_binary" -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; SysAdmin\Models\Setting::set("canary.controlled_data","before-backup");' "$install_root"
backup_output="$($php_binary "$install_root/scripts/backup_database.php")"
backup_archive="${backup_output##* }"
if [[ -f "$backup_archive" ]] && unzip -t "$backup_archive" >/dev/null; then
    record_pass "backup creation" "database archive integrity verified"
else
    record_fail "backup creation" "database archive is missing or unreadable"
fi

# shellcheck disable=SC2016
"$php_binary" -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; SysAdmin\Models\Setting::set("canary.controlled_data","after-backup");' "$install_root"
if "$php_binary" "$install_root/scripts/restore-database.php" \
    --archive="$(basename "$backup_archive")" --confirm > "$report_root/restore.json"; then
    # shellcheck disable=SC2016
    restored="$($php_binary -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; echo SysAdmin\Models\Setting::all()["canary.controlled_data"] ?? "missing";' "$install_root")"
    if [[ "$restored" == "before-backup" ]]; then
        record_pass "backup restore" "controlled database value returned to its pre-change state"
    else
        record_fail "backup restore" "controlled database value was not restored"
    fi
else
    record_fail "backup restore" "safeguarded database restore failed"
fi

signing_key="$work_root/canary-signing-private.pem"
public_key="$work_root/canary-signing-public.pem"
openssl genpkey -quiet -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$signing_key"
openssl pkey -in "$signing_key" -pubout -out "$public_key"
chmod 0600 "$signing_key" "$public_key"

# The disposable canary uses an ephemeral valid RSA trust chain. Production
# defaults remain unchanged and unsigned updates remain disabled.
# shellcheck disable=SC2016
"$php_binary" -r '
    $path=$argv[1]; $c=require $path; $c["updates"]["public_key"]=file_get_contents($argv[2]);
    $c["updates"]["release_root"]=$argv[3];
    $tmp=$path.".canary"; file_put_contents($tmp,"<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($c,true).";\n",LOCK_EX);
    chmod($tmp,0640); rename($tmp,$path);
' "$install_root/config/config.php" "$public_key" /var/lib/microsysadmin/releases

update_source="$work_root/update-source"
release_output="$work_root/release"
rsync -a --exclude=/.git/ --exclude=/config/config.php --exclude=/storage/ --exclude=/vendor/ \
    "$source_checkout/" "$update_source/"
successful_version="1.1.0-beta.2.canary"
printf '%s\n' "$successful_version" > "$update_source/VERSION"
cp "$public_key" "$update_source/config/release-public-key.pub"
SOURCE_DATE_EPOCH=1785513600 "$update_source/build-release.sh" \
    --output "$release_output" --channel beta --released-at 2026-07-31T16:00:00Z \
    --package-url https://canary.invalid/microsysadmin.tar.gz --signing-key-file "$signing_key" \
    > "$report_root/update-build.log"

successful_package="$release_output/microsysadmin-$successful_version.tar.gz"
successful_manifest="$release_output/microsysadmin-$successful_version.manifest.json"
if "$install_root/update.sh" apply "$successful_manifest" "$successful_package" > "$report_root/update-apply.log" \
    && [[ "$(tr -d '[:space:]' < "$install_root/VERSION")" == "$successful_version" ]] \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-after-update.json"; then
    record_pass "signed update" "ephemeral RSA-signed update applied and remained healthy"
else
    record_fail "signed update" "signed update did not apply cleanly"
fi

# shellcheck disable=SC2016
rollback_archive="$($php_binary -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; echo (string)SysAdmin\Database::pdo()->query("SELECT rollback_path FROM update_history WHERE status=\"completed\" ORDER BY id DESC LIMIT 1")->fetchColumn();' "$install_root")"
if [[ -f "$rollback_archive" ]] \
    && "$install_root/rollback.sh" "$rollback_archive" > "$report_root/manual-rollback.log" \
    && [[ "$(tr -d '[:space:]' < "$install_root/VERSION")" == "$original_version" ]] \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-after-rollback.json"; then
    record_pass "manual rollback" "pre-update application snapshot restored and remained healthy"
else
    record_fail "manual rollback" "manual rollback did not restore the original healthy version"
fi

broken_stage="$work_root/broken-stage"
mkdir -p "$broken_stage"
tar -xzf "$successful_package" -C "$broken_stage"
broken_version="1.1.0-beta.3.canary"
printf '%s\n' "$broken_version" > "$broken_stage/microsysadmin/VERSION"
rm -f -- "$broken_stage/microsysadmin/cron/runner.php"
broken_package="$work_root/microsysadmin-$broken_version.tar.gz"
tar --sort=name --mtime=@1785513600 --owner=0 --group=0 --numeric-owner \
    -cf - -C "$broken_stage" microsysadmin | gzip -n > "$broken_package"
broken_checksum="$(sha256sum "$broken_package" | awk '{print $1}')"
broken_manifest="$work_root/microsysadmin-$broken_version.manifest.json"
"$php_binary" "$source_checkout/scripts/release-manifest.php" \
    --version="$broken_version" --channel=beta --released-at=2026-07-31T16:05:00Z \
    --sha256="$broken_checksum" --package-name="$(basename "$broken_package")" \
    --package-url=https://canary.invalid/broken.tar.gz --output="$broken_manifest" \
    --signing-key-file="$signing_key" >/dev/null
if "$install_root/update.sh" apply "$broken_manifest" "$broken_package" > "$report_root/failed-update.log" 2>&1; then
    record_fail "failed-upgrade rollback" "deliberately broken signed update unexpectedly succeeded"
elif [[ "$(tr -d '[:space:]' < "$install_root/VERSION")" == "$original_version" ]] \
    && [[ -f "$install_root/cron/runner.php" ]] \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-after-auto-rollback.json"; then
    record_pass "failed-upgrade rollback" "critical-gate failure triggered automatic rollback to a healthy tree"
else
    record_fail "failed-upgrade rollback" "automatic rollback did not restore a healthy original tree"
fi

# The single-quoted PHP expression is intentionally interpreted by PHP.
# shellcheck disable=SC2016
if "$install_root/uninstall.sh" --confirm --yes > "$report_root/uninstall.log" \
    && [[ -f "$install_root/config/config.php" && -d /var/lib/microsysadmin/backups ]] \
    && "$php_binary" -r 'define("APP_ROOT",$argv[1]); require APP_ROOT."/classes/bootstrap.php"; exit(SysAdmin\Database::pdo()->query("SELECT 1")->fetchColumn() ? 0 : 1);' "$install_root"; then
    record_pass "uninstall preservation" "configuration, database, backups, and application were preserved; shared stack was untouched"
else
    record_fail "uninstall preservation" "safe uninstall did not preserve customer state"
fi

if MICROSYSADMIN_ADMIN_USERNAME="$admin_username" MICROSYSADMIN_ADMIN_EMAIL="${admin_email:-owner@microsysadmin.invalid}" \
    MICROSYSADMIN_ADMIN_PASSWORD="$admin_password" MICROSYSADMIN_BASE_URL="${MICROSYSADMIN_BASE_URL:-http://localhost/microsysadmin}" \
    "$source_checkout/install.sh" --repair --non-interactive > "$report_root/post-uninstall-repair.log" \
    && "$install_root/health-check.sh" --release-gate > "$report_root/health-final.json"; then
    record_pass "repair behavior" "repair re-established MicroSysAdmin-only integration and final health"
else
    record_fail "repair behavior" "post-uninstall repair failed"
fi

password_pattern="$work_root/admin-password.pattern"
printf '%s' "$admin_password" > "$password_pattern"
chmod 0600 "$password_pattern"
if grep -rF -f "$password_pattern" "$report_root" /var/log/microsysadmin >/dev/null 2>&1; then
    record_fail "secret redaction" "administrator password appeared in canary artifacts"
else
    record_pass "secret redaction" "administrator password absent from logs and reports"
fi

chmod 0600 "$report_root"/*
printf 'Clean-install canary passed: %d checks.\n' "$passed"

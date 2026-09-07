#!/usr/bin/env bash
set -Eeuo pipefail

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d /tmp/microsysadmin-doctor-privileged.XXXXXXXX)"
xampp_root="$test_root/xampp"
storage_root="$test_root/storage"
helper="$source_root/scripts/service-control"
passed=0

cleanup() {
    # Queued reloads wait one second before invoking the inert test lampp.
    sleep 2
    if [[ "$test_root" == /tmp/microsysadmin-doctor-privileged.* ]]; then
        rm -rf -- "$test_root"
    fi
}
trap cleanup EXIT

pass() {
    passed=$((passed + 1))
    printf '[PASS] %s\n' "$1"
}

reference_value() {
    local key="$1" text="$2"
    printf '%s\n' "$text" | sed -n "s/^${key}=//p" | tail -n 1
}

mkdir -p \
    "$xampp_root/bin" \
    "$xampp_root/lib/php/extensions/no-debug-non-zts-20220829" \
    "$xampp_root/etc/ssl.crt" \
    "$xampp_root/etc/ssl.key" \
    "$storage_root/doctor/backups" \
    "$storage_root/logs"

printf '%s\n' '#!/bin/sh' 'exit 0' > "$xampp_root/bin/php"
printf '%s\n' '#!/bin/sh' 'exit 0' > "$xampp_root/lampp"
chmod 0750 "$xampp_root/bin/php" "$xampp_root/lampp"
: > "$xampp_root/lib/php/extensions/no-debug-non-zts-20220829/opcache.so"
printf '%s\n' ';zend_extension=opcache.so' 'memory_limit=256M' > "$xampp_root/etc/php.ini"
opcache_before="$(sha256sum "$xampp_root/etc/php.ini" | awk '{print $1}')"

opcache_output="$(MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" "$helper" doctor enable-opcache)"
opcache_backup="$(reference_value DOCTOR_BACKUP_PATH "$opcache_output")"
opcache_backup_hash="$(reference_value DOCTOR_BACKUP_SHA256 "$opcache_output")"
[[ -f "$opcache_backup" && "$opcache_backup_hash" =~ ^[a-f0-9]{64}$ ]]
grep -Eq '^zend_extension=opcache\.so$' "$xampp_root/etc/php.ini"
pass 'OPcache helper emits an exact validated rollback reference'

MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor restore-opcache "$opcache_backup" "$opcache_backup_hash" >/dev/null
[[ "$(sha256sum "$xampp_root/etc/php.ini" | awk '{print $1}')" == "$opcache_before" ]]
pass 'OPcache helper restores the byte-exact pre-repair configuration'

if MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor restore-opcache "$opcache_backup" "$(printf '0%.0s' {1..64})" >/dev/null 2>&1; then
    printf 'Tampered OPcache rollback reference unexpectedly succeeded.\n' >&2
    exit 1
fi
[[ "$(sha256sum "$xampp_root/etc/php.ini" | awk '{print $1}')" == "$opcache_before" ]]
pass 'OPcache helper rejects a tampered rollback hash without mutation'

/usr/bin/openssl req -new -newkey rsa:2048 -nodes -x509 -sha256 -days 2 \
    -keyout "$xampp_root/etc/ssl.key/server.key" \
    -out "$xampp_root/etc/ssl.crt/server.crt" \
    -subj '/CN=pre-repair.local' >/dev/null 2>&1
tls_before="$(sha256sum "$xampp_root/etc/ssl.crt/server.crt" | awk '{print $1}')"
tls_output="$(MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" "$helper" doctor renew-local-tls)"
tls_backup="$(reference_value DOCTOR_BACKUP_PATH "$tls_output")"
tls_backup_hash="$(reference_value DOCTOR_BACKUP_SHA256 "$tls_output")"
[[ -f "$tls_backup" && "$tls_backup_hash" =~ ^[a-f0-9]{64}$ ]]
[[ "$(sha256sum "$xampp_root/etc/ssl.crt/server.crt" | awk '{print $1}')" != "$tls_before" ]]
pass 'TLS helper renews locally and emits an exact validated rollback reference'

MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor restore-local-tls "$tls_backup" "$tls_backup_hash" >/dev/null
[[ "$(sha256sum "$xampp_root/etc/ssl.crt/server.crt" | awk '{print $1}')" == "$tls_before" ]]
pass 'TLS helper restores the byte-exact pre-repair certificate'

if MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor set-ntp invalid >/dev/null 2>&1; then
    printf 'Invalid time-sync state unexpectedly succeeded.\n' >&2
    exit 1
fi
pass 'Time-sync helper rejects every state outside the exact boolean allowlist'

directory_output="$(MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor create-known-directory xampp-logs)"
directory_path="$(reference_value DOCTOR_DIRECTORY_PATH "$directory_output")"
directory_identity="$(reference_value DOCTOR_DIRECTORY_IDENTITY "$directory_output")"
[[ "$directory_path" == "$xampp_root/logs" && -d "$directory_path" && ! -L "$directory_path" ]]
[[ "$directory_identity" =~ ^[0-9]+:[0-9]+:[0-7]{3,4}:[0-9]+:[0-9]+$ ]]
pass 'Known-directory helper creates only the fixed target and emits its exact identity'

: > "$directory_path/post-repair.log"
if MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor remove-known-directory xampp-logs "$directory_identity" >/dev/null 2>&1; then
    printf 'Non-empty known-directory rollback unexpectedly succeeded.\n' >&2
    exit 1
fi
[[ -d "$directory_path" && -f "$directory_path/post-repair.log" ]]
rm -- "$directory_path/post-repair.log"
pass 'Known-directory helper refuses rollback after the created directory gains content'

MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor remove-known-directory xampp-logs "$directory_identity" >/dev/null
[[ ! -e "$directory_path" && ! -L "$directory_path" ]]
pass 'Known-directory helper removes the exact unchanged empty directory on rollback'

rm -- "$xampp_root/etc/ssl.key/server.key"
rmdir -- "$xampp_root/etc/ssl.key"
ssl_key_directory_output="$(MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor create-known-directory xampp-ssl-key)"
ssl_key_directory_path="$(reference_value DOCTOR_DIRECTORY_PATH "$ssl_key_directory_output")"
ssl_key_directory_identity="$(reference_value DOCTOR_DIRECTORY_IDENTITY "$ssl_key_directory_output")"
[[ "$ssl_key_directory_path" == "$xampp_root/etc/ssl.key" && -d "$ssl_key_directory_path" && ! -L "$ssl_key_directory_path" ]]
MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor remove-known-directory xampp-ssl-key "$ssl_key_directory_identity" >/dev/null
[[ ! -e "$ssl_key_directory_path" && ! -L "$ssl_key_directory_path" ]]
pass 'Known-directory helper creates and exactly rolls back the fixed nested XAMPP private-key directory'

if MICROSYSADMIN_XAMPP_ROOT="$xampp_root" MICROSYSADMIN_APP_ROOT="$source_root" MICROSYSADMIN_STORAGE_ROOT="$storage_root" \
    "$helper" doctor create-known-directory arbitrary-path >/dev/null 2>&1; then
    printf 'Arbitrary known-directory token unexpectedly succeeded.\n' >&2
    exit 1
fi
pass 'Known-directory helper rejects every token outside its compiled allowlist'

printf 'Doctor privileged-helper contracts: %d passed, 0 failed.\n' "$passed"

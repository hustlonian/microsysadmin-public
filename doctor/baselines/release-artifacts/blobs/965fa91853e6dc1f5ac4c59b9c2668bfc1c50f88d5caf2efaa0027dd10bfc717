#!/usr/bin/env bash
set -euo pipefail

version="1.1.0-beta.1"
kit_dir="$(pwd)"
extract_root=""

usage() {
    cat <<'USAGE'
Usage: ./scripts/run-second-machine-canary.sh [--kit DIR] [--extract DIR]

Verifies the MicroSysAdmin 1.1.0-beta.1 canary artifacts and supported-host
prerequisites. With --extract, it also extracts the verified package to an
empty destination and runs the installer check without changing the host.

This command does not install, update, restore, or expose MicroSysAdmin.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --kit) kit_dir="$2"; shift 2 ;;
        --extract) extract_root="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 64 ;;
    esac
done

kit_dir="$(cd "$kit_dir" && pwd)"
archive="$kit_dir/microsysadmin-$version.tar.gz"
checksum="$kit_dir/microsysadmin-$version.tar.gz.sha256"
manifest="$kit_dir/microsysadmin-$version.manifest.json"
signature="$kit_dir/microsysadmin-$version.manifest.sig"
public_key="$kit_dir/microsysadmin-$version.release-public-key.pem"
verifier="$kit_dir/verify-release.php"

pass() { printf '[PASS] %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*" >&2; exit 1; }

for required in "$archive" "$checksum" "$manifest" "$signature" "$public_key" "$verifier"; do
    [[ -r "$required" ]] || fail "Required canary artifact is missing: $required"
done

php_binary=""
for candidate in /opt/lampp/bin/php /usr/bin/php /usr/local/bin/php; do
    if [[ -x "$candidate" ]]; then php_binary="$candidate"; break; fi
done
[[ -n "$php_binary" ]] || fail "PHP 8.2+ is required."
"$php_binary" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.2+ is required."
pass "PHP $("$php_binary" -r 'echo PHP_VERSION;')"

(
    cd "$kit_dir"
    sha256sum -c "$(basename "$checksum")"
) || fail "Package checksum did not verify."
pass "Package checksum verified"

"$php_binary" "$verifier" \
    --manifest "$manifest" \
    --package "$archive" \
    --public-key "$public_key" \
    --detached-signature "$signature" >/dev/null \
    || fail "Manifest or detached signature did not verify."
pass "RSA-SHA256 release signature verified"

tar -tzf "$archive" >/dev/null || fail "Archive is unreadable."
if tar -tzf "$archive" | awk '
    substr($0, 1, 1) == "/" { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    END { exit bad ? 0 : 1 }
'; then
    fail "Archive contains an absolute or traversal path."
fi
pass "Archive paths are safe"

free_kb="$(df -Pk "$kit_dir" | awk 'NR==2 {print $4}')"
[[ "$free_kb" =~ ^[0-9]+$ && "$free_kb" -ge 15728640 ]] \
    || fail "At least 15 GiB free is required for the canary."
pass "At least 15 GiB is free"

[[ -x /opt/lampp/lampp ]] || fail "A clean XAMPP/LAMPP installation is required."
/opt/lampp/bin/httpd -t >/dev/null || fail "Apache configuration test failed."
xampp_status="$(/opt/lampp/lampp status 2>&1 || true)"
grep -q 'Apache is running' <<<"$xampp_status" || fail "Apache is not running."
grep -q 'MySQL is running' <<<"$xampp_status" || fail "MySQL is not running."
pass "XAMPP Apache and MySQL are healthy"

if [[ -n "$extract_root" ]]; then
    [[ "$extract_root" = /* ]] || fail "--extract must be an absolute path."
    if [[ -e "$extract_root" && -n "$(find "$extract_root" -mindepth 1 -print -quit 2>/dev/null)" ]]; then
        fail "Extraction destination must be empty."
    fi
    mkdir -p "$extract_root"
    tar -xzf "$archive" -C "$extract_root" --strip-components=1
    "$extract_root/install.sh" --check
    pass "Verified archive extracted and installer check passed"
fi

cat <<'NEXT'
[PASS] PRE-INSTALL CANARY GATE

Continue manually with docs/SECOND-MACHINE-CANARY.md. Record every fresh
install, workflow, signed update, forced failure, rollback, and security test
in docs/SECOND-MACHINE-CANARY-RESULTS.md. This preflight alone does not validate
the beta.
NEXT

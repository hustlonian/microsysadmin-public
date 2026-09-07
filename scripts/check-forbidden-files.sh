#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$script_dir/.." && pwd)"
mode="proposal"
release_mode=0
list_file="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-forbidden-files.XXXXXXXX")"
findings_file="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-forbidden-findings.XXXXXXXX")"

cleanup() {
    rm -f -- "$list_file" "$findings_file"
}
trap cleanup EXIT

usage() {
    cat <<'USAGE'
Usage: scripts/check-forbidden-files.sh [--staged|--tracked|--all-files|--release] [--root DIR]

--release scans an extracted customer package. It permits the production
dependency tree created from composer.lock while retaining runtime, customer
state, credential, archive, dump, and active-configuration exclusions.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --staged) mode="staged"; shift ;;
        --tracked) mode="tracked"; shift ;;
        --all-files) mode="all"; shift ;;
        --release) mode="release"; release_mode=1; shift ;;
        --root) root="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 64 ;;
    esac
done

root="$(cd "$root" && pwd)"

if git -C "$root" rev-parse --git-dir >/dev/null 2>&1; then
    case "$mode" in
        staged) git -C "$root" diff --cached --name-only --diff-filter=ACMR -z > "$list_file" ;;
        tracked) git -C "$root" ls-files -z > "$list_file" ;;
        proposal) git -C "$root" ls-files -co --exclude-standard -z > "$list_file" ;;
        all|release) find "$root" -type f -printf '%P\0' > "$list_file" ;;
    esac
else
    find "$root" -type f -printf '%P\0' > "$list_file"
fi

forbidden_reason() {
    local path="$1"
    case "$path" in
        database/schema.sql) ;;
        config/config.php|config/config.php.*) echo "active-configuration" ;;
        .env|.env.*) [[ "$path" == ".env.example" ]] || echo "environment-secret" ;;
        .microsysadmin-git.conf) echo "local-git-configuration" ;;
        .agents/*|.codex/*) echo "agent-session-state" ;;
        vendor/*) [[ "$release_mode" -eq 1 ]] || echo "dependency-vendor-tree" ;;
        dist/*) echo "generated-release-artifact" ;;
        storage/*)
            case "$path" in storage/.htaccess|storage/*/.gitkeep) ;; *) echo "runtime-state" ;; esac
            ;;
        *.sql|*.sql.gz|*.dump) echo "database-dump" ;;
        *.pem|*.key|*.p12|*.pfx|*.jks|*/id_rsa|*/id_ed25519) echo "private-key-or-keystore" ;;
        *.log|*.log.*) echo "runtime-log" ;;
        *.bak|*.backup|*.tar|*.tar.gz|*.tgz|*.zip) echo "backup-or-archive" ;;
        machine-identity.json|agent-credentials.json) echo "machine-credential" ;;
        .microsysadmin-site-create.lock|*.tmp|*.temp) echo "temporary-state" ;;
    esac
}

while IFS= read -r -d '' path; do
    path="${path#./}"
    reason="$(forbidden_reason "$path")"
    if [[ -n "$reason" ]]; then
        printf '%s\t%s\n' "$path" "$reason" >> "$findings_file"
    fi

    absolute="$root/$path"
    if [[ -L "$absolute" ]]; then
        target="$(readlink -f "$absolute" 2>/dev/null || true)"
        if [[ -z "$target" || "$target" != "$root"/* ]]; then
            printf '%s\t%s\n' "$path" "external-or-broken-symlink" >> "$findings_file"
        fi
    fi
done < "$list_file"

if git -C "$root" rev-parse --git-dir >/dev/null 2>&1; then
    for probe in \
        config/config.php .env .microsysadmin-git.conf storage/logs/app.log \
        storage/backups/customer.sql dist/microsysadmin.tar.gz vendor/autoload.php \
        .agents/session.json machine-identity.json; do
        if ! git -C "$root" check-ignore -q -- "$probe"; then
            printf '%s\t%s\n' "$probe" "required-ignore-rule-missing" >> "$findings_file"
        fi
    done
fi

sort -u "$findings_file" -o "$findings_file"
count="$(wc -l < "$findings_file" | tr -d '[:space:]')"
if [[ "$count" -gt 0 ]]; then
    echo "Forbidden-file scan FAIL: $count issue(s)." >&2
    while IFS=$'\t' read -r path reason; do
        printf '  %s [%s]\n' "$path" "$reason" >&2
    done < "$findings_file"
    exit 1
fi

echo "Forbidden-file scan PASS (mode=$mode)."

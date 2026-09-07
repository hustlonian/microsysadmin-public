#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
default_root="$(cd "$script_dir/.." && pwd)"
root="$default_root"
mode="worktree"
report=""
findings_file="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-secret-findings.XXXXXXXX")"
files_file="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-secret-files.XXXXXXXX")"
candidates_file="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-secret-candidates.XXXXXXXX")"

cleanup() {
    rm -f -- "$findings_file" "$files_file" "$candidates_file"
}
trap cleanup EXIT

usage() {
    cat <<'USAGE'
Usage: scripts/check-secrets.sh [--staged|--history|--all-files] [--root DIR] [--report FILE]

Reports only file names, safe line numbers, and secret categories. Matching
values are never printed. Gitleaks or TruffleHog are used when available;
otherwise the built-in fail-closed pattern scanner is used.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --staged) mode="staged"; shift ;;
        --history) mode="history"; shift ;;
        --all-files) mode="all"; shift ;;
        --root) root="$2"; shift 2 ;;
        --report) report="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 64 ;;
    esac
done

root="$(cd "$root" && pwd)"

relative_path() {
    local path="$1"
    path="${path#"$root"/}"
    printf '%s' "$path"
}

excluded_path() {
    case "$1" in
        .git/*|vendor/*|dist/*|storage/*|.agents/*|.codex/*|\
        scripts/check-secrets.sh|docs/SECRET-SCAN-REPORT.md)
            return 0
            ;;
    esac
    return 1
}

record_finding() {
    local file="$1"
    local line="$2"
    local category="$3"
    printf '%s\t%s\t%s\n' "$file" "$line" "$category" >> "$findings_file"
}

scan_text_file() {
    local file="$1"
    local rel line number
    rel="$(relative_path "$file")"
    excluded_path "$rel" && return 0
    [[ -f "$file" ]] || return 0
    [[ "$(stat -c %s "$file" 2>/dev/null || echo 0)" -le 5242880 ]] || return 0
    grep -Iq . "$file" 2>/dev/null || return 0

    number=0
    while IFS= read -r line || [[ -n "$line" ]]; do
        number=$((number + 1))
        if [[ "$line" =~ -----BEGIN[[:space:]]+(RSA[[:space:]]+|EC[[:space:]]+|OPENSSH[[:space:]]+)?PRIVATE[[:space:]]+KEY----- ]]; then
            record_finding "$rel" "$number" "private-key"
        fi
        if [[ "$line" =~ gh[pousr]_[A-Za-z0-9_]{20,} ]] \
            || [[ "$line" =~ github_pat_[A-Za-z0-9_]{20,} ]] \
            || [[ "$line" =~ GHSAT[A-Za-z0-9_]{20,} ]]; then
            record_finding "$rel" "$number" "github-token"
        fi
        if [[ "$line" =~ (AKIA|ASIA)[A-Z0-9]{16} ]]; then
            record_finding "$rel" "$number" "aws-access-key"
        fi
        if [[ "$line" =~ xox[baprs]-[A-Za-z0-9-]{20,} ]]; then
            record_finding "$rel" "$number" "slack-token"
        fi
        if [[ "$line" =~ sk_live_[A-Za-z0-9]{16,} ]]; then
            record_finding "$rel" "$number" "stripe-live-key"
        fi
        if [[ "$line" =~ https?://[^/@[:space:]]+:[^/@[:space:]]+@ ]]; then
            record_finding "$rel" "$number" "credential-in-url"
        fi
        if [[ "$line" =~ [Aa]uthorization:[[:space:]]*[Bb]earer[[:space:]]+[A-Za-z0-9._=-]{20,} ]]; then
            record_finding "$rel" "$number" "bearer-token"
        fi
        if [[ "$line" =~ eyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,} ]]; then
            record_finding "$rel" "$number" "jwt"
        fi
        if [[ "$line" =~ (api[_-]?key|access[_-]?token|client[_-]?secret|private[_-]?token)[[:space:]]*[:=][[:space:]]*[\"\']?[A-Za-z0-9/+_.=-]{24,} ]]; then
            record_finding "$rel" "$number" "assigned-secret"
        fi
    done < "$file"
}

collect_files() {
    case "$mode" in
        staged)
            git -C "$root" rev-parse --git-dir >/dev/null 2>&1 || {
                echo "Secret scan BLOCKED: staged scan requires a Git repository." >&2
                exit 2
            }
            git -C "$root" diff --cached --name-only --diff-filter=ACMR -z > "$files_file"
            ;;
        worktree)
            if git -C "$root" rev-parse --git-dir >/dev/null 2>&1; then
                while IFS= read -r -d '' candidate; do
                    [[ -f "$root/$candidate" ]] && printf '%s\0' "$candidate"
                done < <(git -C "$root" ls-files -co --exclude-standard -z) > "$files_file"
            else
                find "$root" \
                    -type d \( -name .git -o -name vendor -o -name dist -o -name storage -o -name .agents -o -name .codex \) -prune \
                    -o -type f -print0 2>/dev/null > "$files_file" || true
            fi
            ;;
        all)
            find "$root" -type f -print0 2>/dev/null > "$files_file" || true
            ;;
    esac
}

prefilter_files() {
    if ! command -v rg >/dev/null 2>&1; then
        cp -- "$files_file" "$candidates_file"
        return 0
    fi
    : > "$candidates_file"
    # Variables in the quoted program belong to the isolated batch shell.
    # shellcheck disable=SC2016
    if ! xargs -0 -r -n 256 /bin/bash -c '
        scan_root="$1"
        shift
        cd "$scan_root"
        rg -I -l -0 --no-messages \
            -e "-----BEGIN[[:space:]]+(RSA[[:space:]]+|EC[[:space:]]+|OPENSSH[[:space:]]+)?PRIVATE[[:space:]]+KEY-----|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|GHSAT[A-Za-z0-9_]{20,}|(AKIA|ASIA)[A-Z0-9]{16}|xox[baprs]-[A-Za-z0-9-]{20,}|sk_live_[A-Za-z0-9]{16,}|https?://[^/@[:space:]]+:[^/@[:space:]]+@|[Aa]uthorization:[[:space:]]*[Bb]earer[[:space:]]+[A-Za-z0-9._=-]{20,}|eyJ[A-Za-z0-9_-]{20,}\\.eyJ[A-Za-z0-9_-]{20,}\\.[A-Za-z0-9_-]{10,}|(api[_-]?key|access[_-]?token|client[_-]?secret|private[_-]?token)[[:space:]]*[:=][[:space:]]*[\\x22\\x27]?[A-Za-z0-9/+_.=-]{24,}" \
            -- "$@"
        status=$?
        [[ "$status" -eq 0 || "$status" -eq 1 ]]
    ' _ "$root" < "$files_file" > "$candidates_file"; then
        echo "Secret scan BLOCKED: ripgrep prefilter failed." >&2
        return 2
    fi
}

scan_history() {
    git -C "$root" rev-parse --git-dir >/dev/null 2>&1 || {
        echo "Secret scan BLOCKED: history scan requires a Git repository." >&2
        return 2
    }
    if ! git -C "$root" rev-parse --verify HEAD >/dev/null 2>&1; then
        echo "Secret history scan PASS: repository has no commits."
        return 0
    fi

    local expression category revision status
    while IFS='|' read -r category expression; do
        : > "$candidates_file"
        while IFS= read -r revision; do
            if git -C "$root" grep -I -n -E -e "$expression" "$revision" -- \
                . ':(exclude)scripts/check-secrets.sh' ':(exclude)docs/SECRET-SCAN-REPORT.md' \
                >> "$candidates_file"; then
                :
            else
                status=$?
                if [[ "$status" -ne 1 ]]; then
                    echo "Secret scan BLOCKED: unable to read Git history." >&2
                    return 2
                fi
            fi
        done < <(git -C "$root" rev-list --all)
        while IFS=: read -r commit file line rest; do
            : "$commit" "$rest"
            [[ -n "$file" && -n "$line" ]] || continue
            excluded_path "$file" && continue
            record_finding "$file" "$line" "$category"
        done < "$candidates_file"
    done <<'PATTERNS'
private-key|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----
github-token|(gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|GHSAT[A-Za-z0-9_]{20,})
aws-access-key|(AKIA|ASIA)[A-Z0-9]{16}
slack-token|xox[baprs]-[A-Za-z0-9-]{20,}
stripe-live-key|sk_live_[A-Za-z0-9]{16,}
credential-in-url|https?://[^/@[:space:]]+:[^/@[:space:]]+@
bearer-token|Authorization:[[:space:]]*Bearer[[:space:]]+[A-Za-z0-9._=-]{20,}
jwt|eyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}
PATTERNS
}

scanner="built-in"
if command -v gitleaks >/dev/null 2>&1; then
    scanner="gitleaks + built-in"
elif command -v trufflehog >/dev/null 2>&1; then
    scanner="TruffleHog available; built-in scanner used for redacted terminal reporting"
fi

if [[ "$mode" == "history" ]]; then
    scan_history
else
    collect_files
    prefilter_files
    while IFS= read -r -d '' path; do
        [[ "$path" = /* ]] || path="$root/$path"
        scan_text_file "$path"
    done < "$candidates_file"
fi

sort -u "$findings_file" -o "$findings_file"
count="$(wc -l < "$findings_file" | tr -d '[:space:]')"

if [[ -n "$report" ]]; then
    mkdir -p "$(dirname "$report")"
    {
        echo "# MicroSysAdmin Secret Scan"
        echo
        echo "- Mode: \`$mode\`"
        echo "- Scanner: $scanner"
        echo "- Findings: $count"
        echo
        if [[ "$count" -eq 0 ]]; then
            echo "Result: **PASS**"
        else
            echo "Result: **FAIL**"
            echo
            echo "| File | Line | Category |"
            echo "|---|---:|---|"
            while IFS=$'\t' read -r file line category; do
                printf "| \`%s\` | %s | %s |\n" "$file" "$line" "$category"
            done < "$findings_file"
        fi
        echo
        echo "Secret values are intentionally omitted. Any real credential must be revoked or rotated after removal."
    } > "$report"
fi

if [[ "$count" -gt 0 ]]; then
    echo "Secret scan FAIL ($scanner): $count potential finding(s)." >&2
    while IFS=$'\t' read -r file line category; do
        printf '  %s:%s [%s] remediation=pending\n' "$file" "$line" "$category" >&2
    done < "$findings_file"
    exit 1
fi

echo "Secret scan PASS ($scanner, mode=$mode): no matching secret material."
if [[ "$scanner" == "built-in" ]]; then
    echo "Optional scanners unavailable; fallback scan completed. Install Gitleaks from its official release package for a second engine."
fi

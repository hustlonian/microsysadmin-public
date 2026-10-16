#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$script_dir/.." && pwd)"
php_binary="${MICROSYSADMIN_PHP_BINARY:-}"
composer_binary="${MICROSYSADMIN_COMPOSER_BINARY:-}"
failures=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --root) root="$2"; shift 2 ;;
        -h|--help)
            echo "Usage: scripts/check-repository.sh [--root DIR]"
            exit 0
            ;;
        *) echo "Unknown option: $1" >&2; exit 64 ;;
    esac
done
root="$(cd "$root" && pwd)"

for candidate in /opt/lampp/bin/php /usr/bin/php /usr/local/bin/php; do
    if [[ -z "$php_binary" && -x "$candidate" ]]; then php_binary="$candidate"; fi
done
for candidate in /usr/bin/composer /usr/local/bin/composer; do
    if [[ -z "$composer_binary" && -x "$candidate" ]]; then composer_binary="$candidate"; fi
done

run_check() {
    local label="$1"
    shift
    echo "==> $label"
    if "$@"; then
        echo "[PASS] $label"
    else
        echo "[FAIL] $label" >&2
        failures=$((failures + 1))
    fi
}

run_php_lint() {
    [[ -n "$php_binary" ]] || return 1
    local output
    output="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-php-lint.XXXXXXXX")"
    if find "$root" \
        -type d \( -name .git -o -name vendor -o -path '*/storage/*' -o -name dist \) -prune \
        -o -type f -name '*.php' -print0 \
        | xargs -0 -r -n1 "$php_binary" -l >"$output" 2>&1; then
        rm -f -- "$output"
        return 0
    fi
    sed -n '1,80p' "$output" >&2
    rm -f -- "$output"
    return 1
}

run_shell_lint() {
    local output
    output="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-shell-lint.XXXXXXXX")"
    if find "$root" \
        -type d \( -name .git -o -name vendor -o -path '*/storage/*' -o -name dist \) -prune \
        -o -type f \( -name '*.sh' -o -name 'microsysadmin-git' \) -print0 \
        | xargs -0 -r -n1 bash -n >"$output" 2>&1; then
        if command -v shellcheck >/dev/null 2>&1; then
            find "$root" \
                -type d \( -name .git -o -name vendor -o -path '*/storage/*' -o -name dist \) -prune \
                -o -type f \( -name '*.sh' -o -name 'microsysadmin-git' \) -print0 \
                | xargs -0 -r shellcheck -x >>"$output" 2>&1 || {
                    sed -n '1,120p' "$output" >&2
                    rm -f -- "$output"
                    return 1
                }
        else
            echo "ShellCheck unavailable; bash -n completed." >&2
        fi
        rm -f -- "$output"
        return 0
    fi
    sed -n '1,120p' "$output" >&2
    rm -f -- "$output"
    return 1
}

check_docs() {
    local missing=0
    for path in \
        README.md SECURITY.md CHANGELOG.md CONTRIBUTING.md LICENSE \
        docs/TERMINAL-GIT-WORKFLOW.md \
        docs/GITHUB-REMOTE-RECOVERY-REPORT.md \
        docs/GITHUB-TERMINAL-READINESS-REPORT.md \
        docs/STAGED-FILE-MANIFEST.md docs/SECRET-SCAN-REPORT.md; do
        if [[ ! -s "$root/$path" ]]; then
            echo "Missing documentation: $path" >&2
            missing=1
        fi
    done
    return "$missing"
}

check_release_artifacts() {
    local archive checksum listing
    listing="$(mktemp "${TMPDIR:-/tmp}/microsysadmin-release-listing.XXXXXXXX")"
    shopt -s nullglob
    local archives=("$root"/dist/microsysadmin-*.tar.gz)
    shopt -u nullglob
    for archive in "${archives[@]}"; do
        checksum="$archive.sha256"
        [[ -s "$checksum" ]] || { echo "Missing checksum: $checksum" >&2; return 1; }
        (cd "$(dirname "$archive")" && sha256sum -c "$(basename "$checksum")" >/dev/null) || return 1
        tar -tzf "$archive" > "$listing" || { rm -f -- "$listing"; return 1; }
        local contaminated
        contaminated="$(
            grep -E \
                '(^|/)(config/config\.php|\.env|\.git|\.agents)(/|$)|storage/(logs|backups|workspace)/.+|machine-identity\.json' \
                "$listing" \
                | grep -Ev 'storage/(logs|backups|workspace)/\.gitkeep$' \
                || true
        )"
        if [[ -n "$contaminated" ]]; then
            echo "Release contamination detected: $archive" >&2
            rm -f -- "$listing"
            return 1
        fi
    done
    rm -f -- "$listing"
}

run_tests() {
    [[ "${MICROSYSADMIN_GIT_SKIP_TESTS:-0}" != "1" ]] || {
        echo "Tests explicitly skipped by MICROSYSADMIN_GIT_SKIP_TESTS=1." >&2
        return 0
    }
    [[ -n "$php_binary" ]] || return 1
    local failed=0
    "$php_binary" "$root/tests/run.php" || failed=1
    python3 "$root/tests/job-recovery-isolated.py" --evidence-dir "$(mktemp -d /tmp/msa-job-evidence.XXXXXXXX)" || failed=1
    "$php_binary" "$root/doctor/tests/sdk.php" || failed=1
    "$php_binary" "$root/doctor/tests/run.php" || failed=1
    "$php_binary" "$root/doctor/tests/time-forward.php" || failed=1
    "$php_binary" "$root/doctor/tests/directory-time-forward.php" || failed=1
    "$root/doctor/tests/privileged-helper.sh" || failed=1
    "$root/tests/isolated-install.sh" || failed=1
    "$root/tests/git-manager-failure-modes.sh" || failed=1
    "$root/tests/installer-failure-modes.sh" || failed=1
    return "$failed"
}

run_check "forbidden files" "$root/scripts/check-forbidden-files.sh" --root "$root"
run_check "worktree secret scan" "$root/scripts/check-secrets.sh" --root "$root"
if git -C "$root" rev-parse --verify HEAD >/dev/null 2>&1; then
    run_check "history secret scan" "$root/scripts/check-secrets.sh" --root "$root" --history
fi
if [[ -n "$composer_binary" ]]; then
    run_check "Composer metadata" "$composer_binary" validate --working-dir="$root" --no-check-publish --no-interaction
else
    echo "[FAIL] Composer metadata: Composer is unavailable." >&2
    failures=$((failures + 1))
fi
run_check "PHP syntax" run_php_lint
run_check "shell syntax" run_shell_lint
run_check "required documentation" check_docs
run_check "release artifact contamination" check_release_artifacts
run_check "automated tests" run_tests

if [[ "$failures" -gt 0 ]]; then
    echo "Repository audit FAIL: $failures check group(s) failed." >&2
    exit 1
fi

echo "Repository audit PASS."

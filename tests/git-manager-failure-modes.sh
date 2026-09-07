#!/usr/bin/env bash
set -euo pipefail

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/microsysadmin-git-tests.XXXXXXXX")"
passed=0
failed=0

# shellcheck disable=SC2317
cleanup() {
    if [[ "$test_root" == "${TMPDIR:-/tmp}"/microsysadmin-git-tests.* ]]; then
        rm -rf -- "$test_root"
    fi
}
trap cleanup EXIT

pass_test() {
    echo "[PASS] $1"
    passed=$((passed + 1))
}

fail_test() {
    echo "[FAIL] $1" >&2
    failed=$((failed + 1))
}

run_expect() {
    local label="$1" expected="$2"
    shift 2
    local output status
    set +e
    output="$("$@" 2>&1)"
    status=$?
    set -e
    if [[ "$status" -eq "$expected" ]]; then
        pass_test "$label"
    else
        printf '%s\n' "$output" | sed -n '1,30p' >&2
        fail_test "$label (expected $expected, got $status)"
    fi
}

make_fixture() {
    local name="$1"
    local root="$test_root/$name"
    mkdir -p "$root/scripts" "$root/docs" "$root/state"
    printf '%s\n' '1.0.0-dev' > "$root/VERSION"
    printf '%s\n' '{"name":"microsysadmin/test"}' > "$root/composer.json"
    cp "$source_root/scripts/check-secrets.sh" "$root/scripts/check-secrets.sh"
    cp "$source_root/scripts/check-forbidden-files.sh" "$root/scripts/check-forbidden-files.sh"
    printf '%s\n' '#!/usr/bin/env bash' 'exit 0' > "$root/scripts/check-repository.sh"
    chmod +x "$root/scripts/"*.sh
    git -C "$root" init -q -b main
    git -C "$root" config user.name "MicroSysAdmin Test"
    git -C "$root" config user.email "test@microsysadmin.invalid"
    git -C "$root" add VERSION composer.json scripts
    git -C "$root" commit -q -m "fixture"
    printf '%s' "$root"
}

fake_bin="$test_root/bin"
mkdir -p "$fake_bin"
cat > "$fake_bin/gh" <<'FAKEGH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "auth" && "${2:-}" == "status" ]]; then
    [[ "${FAKE_GH_AUTH:-0}" == "1" ]]
    exit
fi
if [[ "${1:-}" == "api" && "${2:-}" == "user" ]]; then
    echo "test-owner"
    exit 0
fi
if [[ "${1:-}" == "repo" && "${2:-}" == "view" ]]; then
    echo '{"nameWithOwner":"test-owner/repo","visibility":"PRIVATE","defaultBranch":"main","isArchived":false,"url":"https://github.com/test-owner/repo","viewerPermission":"WRITE"}'
    exit 0
fi
exit 1
FAKEGH
chmod +x "$fake_bin/gh"

missing_auth_root="$(make_fixture missing-auth)"
run_expect "missing GitHub authentication fails closed" 2 \
    env FAKE_GH_AUTH=0 PATH="$fake_bin:$PATH" \
    MICROSYSADMIN_GIT_PROJECT_ROOT="$missing_auth_root" \
    MICROSYSADMIN_GIT_STATE_ROOT="$missing_auth_root/state" \
    "$source_root/microsysadmin-git" auth status

wrong_remote_root="$(make_fixture wrong-remote)"
cat > "$wrong_remote_root/.microsysadmin-git.conf" <<'CONF'
REMOTE_NAME=origin
REPOSITORY_OWNER=test-owner
REPOSITORY_NAME=repo
REMOTE_CONFIDENCE=confirmed
EXPECTED_VISIBILITY=private
CONF
git -C "$wrong_remote_root" remote add origin https://github.com/test-owner/unrelated.git
git -C "$wrong_remote_root" add .microsysadmin-git.conf
git -C "$wrong_remote_root" commit -q -m "incorrect remote fixture"
run_expect "incorrect remote fails closed" 2 \
    env FAKE_GH_AUTH=1 PATH="$fake_bin:$PATH" \
    MICROSYSADMIN_GIT_PROJECT_ROOT="$wrong_remote_root" \
    MICROSYSADMIN_GIT_STATE_ROOT="$wrong_remote_root/state" \
    "$source_root/microsysadmin-git" verify

dirty_root="$(make_fixture dirty)"
cat > "$dirty_root/.microsysadmin-git.conf" <<'CONF'
REMOTE_NAME=origin
REPOSITORY_OWNER=test-owner
REPOSITORY_NAME=repo
REMOTE_CONFIDENCE=confirmed
EXPECTED_VISIBILITY=private
CONF
git -C "$dirty_root" remote add origin https://github.com/test-owner/repo.git
git -C "$dirty_root" add .microsysadmin-git.conf
git -C "$dirty_root" commit -q -m "confirmed remote fixture"
printf '%s\n' "dirty" >> "$dirty_root/VERSION"
run_expect "dirty pull fails closed" 2 \
    env FAKE_GH_AUTH=1 PATH="$fake_bin:$PATH" \
    MICROSYSADMIN_GIT_PROJECT_ROOT="$dirty_root" \
    MICROSYSADMIN_GIT_STATE_ROOT="$dirty_root/state" \
    "$source_root/microsysadmin-git" pull

secret_root="$(make_fixture staged-secret)"
printf 'token=ghp_%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' > "$secret_root/example.txt"
git -C "$secret_root" add example.txt
run_expect "staged secret fails closed without revealing value" 1 \
    "$source_root/scripts/check-secrets.sh" --root "$secret_root" --staged
git -C "$secret_root" commit -q -m "synthetic credential fixture"
printf '%s\n' 'The current file is clean.' > "$secret_root/example.txt"
git -C "$secret_root" add example.txt
git -C "$secret_root" commit -q -m "remove synthetic credential"
run_expect "history scan detects a removed secret across multiple commits" 1 \
    "$source_root/scripts/check-secrets.sh" --root "$secret_root" --history

backup_root="$(make_fixture staged-backup)"
printf '%s\n' 'not a real database' > "$backup_root/customer.sql"
git -C "$backup_root" add customer.sql
run_expect "staged backup fails closed" 1 \
    "$source_root/scripts/check-forbidden-files.sh" --root "$backup_root" --staged

failed_test_root="$(make_fixture failed-test)"
printf '%s\n' '#!/usr/bin/env bash' 'echo "[FAIL] simulated automated test" >&2' 'exit 1' \
    > "$failed_test_root/scripts/check-repository.sh"
chmod +x "$failed_test_root/scripts/check-repository.sh"
run_expect "failed automated test gate fails closed" 1 \
    env FAKE_GH_AUTH=0 PATH="$fake_bin:$PATH" \
    MICROSYSADMIN_GIT_PROJECT_ROOT="$failed_test_root" \
    MICROSYSADMIN_GIT_STATE_ROOT="$failed_test_root/state" \
    "$source_root/microsysadmin-git" audit

divergent_root="$(make_fixture divergent)"
bare="$test_root/divergent-remote.git"
seed="$test_root/divergent-seed"
git init -q --bare "$bare"
git clone -q "$bare" "$seed"
git -C "$seed" switch -q -c main
git -C "$seed" config user.name "MicroSysAdmin Test"
git -C "$seed" config user.email "test@microsysadmin.invalid"
printf '%s\n' "remote-only" > "$seed/remote.txt"
git -C "$seed" add remote.txt
git -C "$seed" commit -q -m "remote root"
git -C "$seed" push -q -u origin main
git -C "$bare" symbolic-ref HEAD refs/heads/main
cat > "$divergent_root/.microsysadmin-git.conf" <<'CONF'
REMOTE_NAME=origin
REPOSITORY_OWNER=test-owner
REPOSITORY_NAME=repo
REMOTE_CONFIDENCE=confirmed
EXPECTED_VISIBILITY=private
CONF
git -C "$divergent_root" remote add origin https://github.com/test-owner/repo.git
git -C "$divergent_root" config "url.file://$bare.insteadOf" https://github.com/test-owner/repo.git
git -C "$divergent_root" add .microsysadmin-git.conf
git -C "$divergent_root" commit -q -m "local root"
run_expect "divergent remote fails closed" 2 \
    env FAKE_GH_AUTH=1 PATH="$fake_bin:$PATH" \
    MICROSYSADMIN_GIT_PROJECT_ROOT="$divergent_root" \
    MICROSYSADMIN_GIT_STATE_ROOT="$divergent_root/state" \
    "$source_root/microsysadmin-git" pull

scanner_root="$(make_fixture scanner-fallback)"
if command -v gitleaks >/dev/null 2>&1 || command -v trufflehog >/dev/null 2>&1; then
    if "$source_root/scripts/check-secrets.sh" --root "$scanner_root" >/dev/null; then
        pass_test "optional scanner path remains operational"
    else
        fail_test "optional scanner path remains operational"
    fi
else
    scanner_output="$("$source_root/scripts/check-secrets.sh" --root "$scanner_root")"
    if [[ "$scanner_output" == *"Optional scanners unavailable"* ]]; then
        pass_test "unavailable optional scanner reports degraded fallback"
    else
        fail_test "unavailable optional scanner reports degraded fallback"
    fi
fi

echo
echo "$passed passed, $failed failed"
exit "$failed"

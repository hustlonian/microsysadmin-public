#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d /tmp/microsysadmin-vm-sync-test.XXXXXXXX)"
source_root="$test_root/source"
clean_workspace="$test_root/clean/workspace"
dirty_workspace="$test_root/dirty/workspace"

cleanup() {
    if [[ "$test_root" == /tmp/microsysadmin-vm-sync-test.* ]]; then
        rm -rf -- "$test_root"
    fi
}
trap cleanup EXIT

mkdir -p "$source_root"
git -C "$source_root" init --quiet
git -C "$source_root" config user.name "MicroSysAdmin VM sync test"
git -C "$source_root" config user.email "vm-sync@microsysadmin.invalid"
printf '%s\n' 'ignored-secret.txt' > "$source_root/.gitignore"
printf '%s\n' 'committed' > "$source_root/tracked.txt"
git -C "$source_root" add .gitignore tracked.txt
git -C "$source_root" commit --quiet -m "test fixture"

MICROSYSADMIN_VM_SOURCE="$source_root" \
MICROSYSADMIN_VM_WORKSPACE="$clean_workspace" \
MICROSYSADMIN_VM_OWNER="$(id -u):$(id -g)" \
    "$repository_root/tools/github-test-vm/seed-workspace.sh"

[[ "$(< "$clean_workspace/tracked.txt")" == "committed" ]]
[[ "$(git -C "$clean_workspace" rev-parse HEAD)" == "$(git -C "$source_root" rev-parse HEAD)" ]]

printf '%s\n' 'staged' > "$source_root/tracked.txt"
git -C "$source_root" add tracked.txt
printf '%s\n' 'unstaged' >> "$source_root/tracked.txt"
printf '%s\n' 'untracked' > "$source_root/untracked.txt"
printf '%s\n' 'must-not-copy' > "$source_root/ignored-secret.txt"

MICROSYSADMIN_VM_SOURCE="$source_root" \
MICROSYSADMIN_VM_WORKSPACE="$dirty_workspace" \
MICROSYSADMIN_VM_OWNER="$(id -u):$(id -g)" \
    "$repository_root/tools/github-test-vm/seed-workspace.sh"

[[ "$(< "$dirty_workspace/tracked.txt")" == $'staged\nunstaged' ]]
[[ "$(< "$dirty_workspace/untracked.txt")" == "untracked" ]]
[[ ! -e "$dirty_workspace/ignored-secret.txt" ]]

echo "GitHub test VM workspace sync checks passed."

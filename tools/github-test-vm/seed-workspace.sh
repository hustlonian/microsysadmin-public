#!/usr/bin/env bash
set -euo pipefail

source_root="${MICROSYSADMIN_VM_SOURCE:-/source}"
workspace_root="${MICROSYSADMIN_VM_WORKSPACE:-/workspace/microsysadmin}"
workspace_owner="${MICROSYSADMIN_VM_OWNER:-tester:tester}"
replace=0

if [[ "${1:-}" == "--replace" ]]; then
    replace=1
elif [[ $# -gt 0 ]]; then
    echo "Usage: seed-workspace.sh [--replace]" >&2
    exit 64
fi

if ! git -c safe.directory="$source_root" -C "$source_root" rev-parse --verify HEAD >/dev/null 2>&1; then
    echo "The mounted source is not a readable Git repository: $source_root" >&2
    exit 1
fi

if [[ "$replace" -eq 1 && -e "$workspace_root" ]]; then
    if [[ "$workspace_root" != /workspace/* ]]; then
        echo "Refusing to replace an unsafe workspace path: $workspace_root" >&2
        exit 1
    fi
    rm -rf -- "$workspace_root"
fi

if [[ -d "$workspace_root/.git" ]]; then
    exit 0
fi
if [[ -e "$workspace_root" ]]; then
    if [[ ! -d "$workspace_root" || -n "$(find "$workspace_root" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        echo "Workspace exists but is not an empty directory or Git checkout: $workspace_root" >&2
        exit 1
    fi
fi

mkdir -p "$(dirname "$workspace_root")"
git -c safe.directory="$source_root" clone \
    --local \
    --no-hardlinks \
    --no-recurse-submodules \
    "$source_root" \
    "$workspace_root" >/dev/null

# Reproduce tracked, staged, and unstaged changes without copying ignored
# machine state or credentials from the host checkout. git apply rejects an
# empty stream, so a clean checkout must skip this step entirely.
if ! git -c safe.directory="$source_root" -C "$source_root" diff --quiet HEAD; then
    git -c safe.directory="$source_root" -C "$source_root" diff \
        --binary \
        --full-index \
        HEAD \
        | git -C "$workspace_root" apply --whitespace=nowarn
fi

untracked_list="$(mktemp /tmp/microsysadmin-vm-untracked.XXXXXXXX)"
cleanup() {
    rm -f -- "$untracked_list"
}
trap cleanup EXIT

git -c safe.directory="$source_root" -C "$source_root" \
    ls-files --others --exclude-standard -z > "$untracked_list"
if [[ -s "$untracked_list" ]]; then
    (
        cd "$source_root"
        tar --null --files-from="$untracked_list" -cf -
    ) | tar -xf - -C "$workspace_root"
fi

git -C "$workspace_root" config user.name "MicroSysAdmin VM"
git -C "$workspace_root" config user.email "vm@microsysadmin.invalid"
chown -R "$workspace_owner" "$(dirname "$workspace_root")"

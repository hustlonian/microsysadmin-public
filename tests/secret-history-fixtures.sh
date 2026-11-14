#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="$(mktemp -d /tmp/microsysadmin-secret-history.XXXXXXXX)"
trap 'rm -rf -- "$fixture"' EXIT
git -C "$fixture" init -q
git -C "$fixture" config user.name 'Synthetic scanner test'
git -C "$fixture" config user.email 'scanner@example.test'
mkdir -p "$fixture/tests"
git -C "$source_root" cat-file blob e4829bda800c115abdc6b8ea9af310282fb958e5 > "$fixture/tests/development-command-center.php"
git -C "$fixture" add tests/development-command-center.php
git -C "$fixture" commit -qm 'Exact historical synthetic fixture'
bash "$source_root/scripts/check-secrets.sh" --root "$fixture" --history > "$fixture/history.log"
if bash "$source_root/scripts/check-secrets.sh" --root "$fixture" > "$fixture/current.log" 2>&1; then
    echo 'Current source was incorrectly exempted.' >&2; exit 1
fi
grep -q '\[credential-in-url\]' "$fixture/current.log"
# Even the same synthetic line in a changed blob must not receive the exception.
printf '\n// Changed source must be scanned.\n' >> "$fixture/tests/development-command-center.php"
git -C "$fixture" add tests/development-command-center.php
git -C "$fixture" commit -qm 'Changed blob is not allowlisted'
if bash "$source_root/scripts/check-secrets.sh" --root "$fixture" --history > "$fixture/changed.log" 2>&1; then
    echo 'Changed historical blob was incorrectly exempted.' >&2; exit 1
fi
grep -q '\[credential-in-url\]' "$fixture/changed.log"
echo 'PASS: exact historical fixture accepted; current source and changed blob rejected.'

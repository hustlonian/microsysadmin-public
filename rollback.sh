#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$app_root/scripts/runtime.sh"
php_binary="$(microsysadmin_resolve_php)"

[[ $# -eq 1 ]] || { echo "Usage: ./rollback.sh /absolute/path/to/rollback.tar.gz" >&2; exit 64; }
exec "$php_binary" "$app_root/scripts/rollback-cli.php" "$1"

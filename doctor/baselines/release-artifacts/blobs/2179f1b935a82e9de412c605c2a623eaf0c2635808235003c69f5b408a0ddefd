#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$app_root/scripts/runtime.sh"
php_binary="$(microsysadmin_resolve_php)"
exec "$php_binary" "$app_root/scripts/repair-cli.php"

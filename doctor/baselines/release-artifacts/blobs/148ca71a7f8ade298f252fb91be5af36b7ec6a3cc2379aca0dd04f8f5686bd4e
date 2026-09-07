#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$app_root/scripts/runtime.sh"
php_binary="$(microsysadmin_resolve_php)"

case "${1:-check}" in
    check)
        exec "$php_binary" "$app_root/scripts/update-cli.php" check
        ;;
    apply)
        [[ $# -eq 3 ]] || { echo "Usage: ./update.sh apply MANIFEST_JSON PACKAGE_TAR_GZ" >&2; exit 64; }
        exec "$php_binary" "$app_root/scripts/update-cli.php" apply "$2" "$3"
        ;;
    *)
        echo "Usage: ./update.sh check|apply MANIFEST_JSON PACKAGE_TAR_GZ" >&2
        exit 64
        ;;
esac

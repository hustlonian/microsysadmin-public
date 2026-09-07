#!/usr/bin/env bash
set -Eeuo pipefail

app_source="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/installer-lib.sh
source "$app_source/scripts/installer-lib.sh"

microsysadmin_installer_main "$app_source" "$@"

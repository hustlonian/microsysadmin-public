#!/usr/bin/env bash
set -euo pipefail

/usr/local/bin/seed-workspace.sh

exec "$@"

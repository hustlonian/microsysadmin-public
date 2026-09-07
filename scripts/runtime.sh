#!/usr/bin/env bash

# Shared shell bootstrap for MicroSysAdmin operational entry points.
microsysadmin_resolve_php() {
    if [[ -n "${MICROSYSADMIN_PHP_BINARY:-}" && -x "${MICROSYSADMIN_PHP_BINARY}" ]]; then
        printf '%s\n' "${MICROSYSADMIN_PHP_BINARY}"
        return 0
    fi

    local candidate
    for candidate in /opt/lampp/bin/php /usr/bin/php /usr/local/bin/php; do
        if [[ -x "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    if command -v php >/dev/null 2>&1; then
        command -v php
        return 0
    fi

    echo "PHP 8.2+ was not found. Set MICROSYSADMIN_PHP_BINARY." >&2
    return 1
}

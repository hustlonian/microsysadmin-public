#!/usr/bin/env bash
set -euo pipefail

source_root="${MICROSYSADMIN_VM_SOURCE:-/source}"
mysql_host="${MICROSYSADMIN_TEST_DB_HOST:-mysql}"
mysql_port="${MICROSYSADMIN_TEST_DB_PORT:-3306}"
mysql_user="${MICROSYSADMIN_TEST_DB_USER:-root}"
mysql_password="${MICROSYSADMIN_TEST_DB_PASSWORD:-microsysadmin-ci-root}"
mode="${1:-all}"
run_root="$(mktemp -d /tmp/microsysadmin-github-test.XXXXXXXX)"
database_name="microsysadmin_ci_$(date +%s)_$$"
database_created=0

git config --global --add safe.directory "$source_root"
git config --global --add safe.directory "$source_root/.git"

case "$mode" in
    all|repository|install) ;;
    *)
        echo "Usage: run-microsysadmin-ci.sh [all|repository|install]" >&2
        exit 64
        ;;
esac

cleanup() {
    if [[ "$database_created" -eq 1 ]]; then
        MYSQL_PWD="$mysql_password" mysql \
            --host="$mysql_host" \
            --port="$mysql_port" \
            --user="$mysql_user" \
            --execute="DROP DATABASE IF EXISTS \`$database_name\`" \
            >/dev/null 2>&1 || true
    fi

    if [[ "${MICROSYSADMIN_KEEP_VM_TEST_ARTIFACTS:-0}" == "1" ]]; then
        echo "Preserved test artifacts: $run_root"
    elif [[ "$run_root" == /tmp/microsysadmin-github-test.* ]]; then
        rm -rf -- "$run_root"
    fi
}
trap cleanup EXIT

prepare_checkout() {
    local name="$1"
    local checkout="$run_root/$name"
    local untracked_list="$run_root/$name.untracked"

    git -c safe.directory="$source_root" clone \
        --local \
        --no-hardlinks \
        --no-recurse-submodules \
        "$source_root" \
        "$checkout" >/dev/null || return 1
    if ! git -c safe.directory="$source_root" -C "$source_root" diff --quiet HEAD; then
        git -c safe.directory="$source_root" -C "$source_root" diff \
            --binary \
            --full-index \
            HEAD \
            | git -C "$checkout" apply --whitespace=nowarn \
            || return 1
    fi
    git -c safe.directory="$source_root" -C "$source_root" \
        ls-files --others --exclude-standard -z > "$untracked_list" \
        || return 1
    if [[ -s "$untracked_list" ]]; then
        (
            cd "$source_root"
            tar --null --files-from="$untracked_list" -cf -
        ) | tar -xf - -C "$checkout" || return 1
    fi
    printf '%s\n' "$checkout"
}

run_repository_job() {
    local checkout
    checkout="$(prepare_checkout repository)"
    cd "$checkout"

    echo "==> GitHub Actions job: repository"
    composer install --no-interaction --no-progress --prefer-dist
    composer validate --no-check-publish --no-interaction
    ./scripts/check-forbidden-files.sh
    ./scripts/check-secrets.sh --history
    MICROSYSADMIN_GIT_SKIP_TESTS=1 ./scripts/check-repository.sh
    find . \
        -path ./vendor -prune \
        -o -path ./storage -prune \
        -o -name '*.php' -print0 \
        | xargs -0 -n1 php -l >/dev/null
    find . \
        -path ./vendor -prune \
        -o -name '*.sh' -print0 \
        | xargs -0 -n1 bash -n
    find . \
        -path ./vendor -prune \
        -o -path ./storage -prune \
        -o -type f \( -name '*.sh' -o -name 'microsysadmin-git' \) -print0 \
        | xargs -0 shellcheck -x
    ./tests/installer-failure-modes.sh
    ./tests/github-test-vm-sync.sh
    echo "[PASS] repository"
}

run_install_job() {
    local checkout installer_log health_log
    checkout="$(prepare_checkout commercial-tests)"
    installer_log="$run_root/installer.log"
    health_log="$run_root/health.json"
    cd "$checkout"

    echo "==> Installation check and GitHub Actions job: commercial-tests"
    composer install --no-interaction --no-progress --prefer-dist
    MICROSYSADMIN_STORAGE_ROOT="$run_root/preflight/storage" \
        MICROSYSADMIN_PRIVATE_ROOT="$run_root/preflight/private" \
        MICROSYSADMIN_BACKUP_ROOT="$run_root/preflight/storage/backups" \
        MICROSYSADMIN_TRASH_ROOT="$run_root/preflight/storage/trash" \
        MICROSYSADMIN_SCRIPTS_ROOT="$run_root/preflight/storage/scripts" \
        MICROSYSADMIN_WORKSPACE_ROOT="$run_root/preflight/storage/workspace" \
        ./install.sh --check >/dev/null

    MYSQL_PWD="$mysql_password" mysql \
        --host="$mysql_host" \
        --port="$mysql_port" \
        --user="$mysql_user" \
        --execute="CREATE DATABASE \`$database_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    database_created=1

    export MICROSYSADMIN_ADMIN_PASSWORD="MicroSysAdmin-CI-Owner-74!"
    export MICROSYSADMIN_DB_PASSWORD="$mysql_password"
    ./install.sh \
        --admin-username=ci-owner \
        --admin-email=ci@example.test \
        --security-profile=safe \
        --db-host="$mysql_host" \
        --db-port="$mysql_port" \
        --db-socket= \
        --db-name="$database_name" \
        --db-user="$mysql_user" \
        --php-binary=/usr/bin/php \
        --composer-binary=/usr/bin/composer \
        --git-binary=/usr/bin/git \
        --bash-binary=/usr/bin/bash \
        --mysql-binary=/usr/bin/mysql \
        --mysqldump-binary=/usr/bin/mysqldump \
        --document-root="$run_root/projects" \
        --allowed-root="$run_root/projects" \
        --storage-root="$run_root/storage" \
        --private-root="$run_root/private" \
        --backup-root="$run_root/storage/backups" \
        --trash-root="$run_root/storage/trash" \
        --scripts-root="$run_root/storage/scripts" \
        --workspace-root="$run_root/storage/workspace" \
        --service-driver=inspection \
        --terminal-available=0 \
        --service-user=tester \
        --service-group=tester \
        --system-integration=skip > "$installer_log"
    unset MICROSYSADMIN_ADMIN_PASSWORD MICROSYSADMIN_DB_PASSWORD

    ./diagnose.sh >/dev/null
    if ! ./health-check.sh > "$health_log"; then
        # Apache and MySQL run outside this container. Require the same
        # release-critical health checks that gate installation and updates.
        # The single-quoted program is intentionally interpreted by PHP.
        # shellcheck disable=SC2016
        php -r '
            define("APP_ROOT", getcwd());
            require APP_ROOT . "/classes/bootstrap.php";
            $service = new SysAdmin\HealthCheckService();
            exit($service->releaseGate($service->all())["ok"] ? 0 : 1);
        '
    fi

    php tests/run.php
    ./tests/git-manager-failure-modes.sh
    echo "[PASS] commercial-tests"
}

if [[ "$mode" == "all" || "$mode" == "repository" ]]; then
    run_repository_job
fi
if [[ "$mode" == "all" || "$mode" == "install" ]]; then
    run_install_job
fi

echo "Local GitHub workflow test passed (mode=$mode)."

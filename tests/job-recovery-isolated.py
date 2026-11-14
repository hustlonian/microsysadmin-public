#!/usr/bin/env python3
"""Verify job recovery using generated config and a private, network-disabled MariaDB.

No installed application config, database, credentials or services are used.
Runtime and evidence are retained, and only this runner's child server is stopped.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--evidence-dir', required=True, type=Path)
    parser.add_argument('--application-suite', action='store_true')
    parser.add_argument('--install-suite', action='store_true')
    parser.add_argument('--development-projection', type=Path)
    parser.add_argument('--development-domain', type=Path)
    parser.add_argument('--development-only', action='store_true')
    parser.add_argument('--owner-setup-only', action='store_true')
    parser.add_argument('--vendor-dir', type=Path, help='Existing dependency directory to copy into the disposable runtime')
    args = parser.parse_args()
    if args.owner_setup_only and (args.development_projection or args.application_suite or args.install_suite):
        parser.error('--owner-setup-only cannot run unrelated suites')
    if args.development_only and not args.development_projection:
        parser.error('--development-only requires a sanitized projection')
    source = Path(__file__).resolve().parents[1]
    evidence = args.evidence_dir.resolve()
    evidence.mkdir(parents=True, exist_ok=True, mode=0o700)
    runtime = Path(tempfile.mkdtemp(prefix='msa-job-test-'))
    app = runtime / 'app'
    results = []
    php = '/opt/lampp/bin/php'

    def run(name, command, timeout=120):
        print(name, flush=True)
        with (evidence / (name + '.log')).open('w') as output:
            result = subprocess.run(command, cwd=app, stdout=output,
                                    stderr=subprocess.STDOUT, timeout=timeout)
        results.append({'check': name, 'exit_code': result.returncode})
        if result.returncode:
            raise RuntimeError(f'{name} failed; see {evidence / (name + ".log")}')

    # Copy only versioned source and this suite, never local config or runtime state.
    names = subprocess.check_output(['git', '-C', str(source), 'ls-files', '-z']).split(b'\0')
    names += [b'tests/job-recovery.php', b'tests/job-recovery-isolated.py', b'tests/owner-setup.php']
    for name in set(filter(None, names)):
        relative = Path(os.fsdecode(name))
        if relative.parts[0] in {'.git', '.agents', '.codex', 'storage', 'vendor', 'dist'}:
            continue
        if relative.name in {'.env', 'config.php'}:
            continue
        origin = source / relative
        if origin.is_symlink() or not origin.is_file():
            raise RuntimeError(f'Expected regular source file: {relative}')
        target = app / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(origin, target)
    shutil.copytree(args.vendor_dir.resolve() if args.vendor_dir else source / 'vendor', app / 'vendor')
    builder = runtime / 'configuration.php'
    builder.write_text(r'''<?php
    $c = require $argv[1].'/config/config.sample.php';
    $root = $argv[1].'/storage';
    $c['database']['socket'] = $argv[2].'/mysql.sock';
    $c['database']['name'] = 'microsysadmin_job_test_isolated';
    $c['environment']['mysql_socket'] = $c['database']['socket'];
    foreach (['private_root','backup_root','script_root','temporary_workspace_root','trash_root'] as $key) {
        $c['environment'][$key] = $root.'/'.$key;
    }
    $c['environment']['storage_root'] = $root;
    $c['environment']['service_control_driver'] = 'inspection';
    $c['environment']['allowed_filesystem_roots'] = [$root];
    $c['environment']['document_roots'] = [$root];
    $c['paths']['allowed_roots'] = [$root];
    $c['paths']['backup_root'] = $root.'/backup_root';
    $c['updates']['release_root'] = $root.'/releases';
    $c['app']['base_url'] = '';
    $c['app']['debug'] = true;
    $c['security']['app_key'] = bin2hex(random_bytes(32));
    file_put_contents($argv[1].'/config/config.php', '<?php return '.var_export($c,true).';');
    chmod($argv[1].'/config/config.php', 0600);
    ''')
    server = None
    failure = None
    try:
        run('configuration', [php, str(builder), str(app), str(runtime)])
        run('initialize-mariadb', ['/opt/lampp/bin/mysql_install_db', '--no-defaults',
                                  '--basedir=/opt/lampp', '--datadir=' + str(runtime / 'data')])
        command = ['/opt/lampp/sbin/mysqld', '--no-defaults', '--basedir=/opt/lampp',
                   '--datadir=' + str(runtime / 'data'), '--socket=' + str(runtime / 'mysql.sock'),
                   '--pid-file=' + str(runtime / 'mysql.pid'), '--log-error=' + str(runtime / 'mysql.log'),
                   '--skip-networking', '--innodb-buffer-pool-size=32M', '--max-connections=15']
        if os.getuid() == 0:
            command.append('--user=root')
        server = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError('Disposable MariaDB exited; inspect ' + str(runtime / 'mysql.log'))
            if (runtime / 'mysql.sock').exists():
                break
            time.sleep(0.1)
        else:
            raise RuntimeError('Disposable MariaDB readiness timed out')
        if args.development_projection:
            projection = json.loads(args.development_projection.read_text())
            if projection.get('kind') != 'DEVELOPMENT_COMMAND_CENTER':
                raise RuntimeError('Expected sanitized development projection')
            (app / 'storage').mkdir(exist_ok=True)
            shutil.copyfile(args.development_projection, app / 'storage/development-fixture.json')
            if args.development_domain:
                domain = json.loads(args.development_domain.read_text())
                if domain.get('validation_context') != 'DISPOSABLE_DATABASE_SYNTHETIC_ACCOUNTS_REAL_ROADMAP_NO_ACCEPTANCE' or domain.get('domain', {}).get('kind') != 'LOCKGATE_DOMAIN':
                    raise RuntimeError('Expected labeled disposable domain import replay')
                (app / 'storage/development-domain-replay.json').write_text(json.dumps(domain['domain']))
            run('development-command-center', [php, 'tests/development-command-center.php'])
        if args.owner_setup_only:
            run('owner-setup', [php, 'tests/owner-setup.php'])
        if not args.development_only and not args.owner_setup_only:
            run('job-recovery', [php, 'tests/job-recovery.php'])
        if args.application_suite:
            run('application-suite', [php, 'tests/run.php'])
        if args.install_suite:
            run('isolated-install', ['env', 'MICROSYSADMIN_TEST_MYSQL_SOCKET=' + str(runtime / 'mysql.sock'),
                                    'MICROSYSADMIN_TEST_DB_USER=root', 'MICROSYSADMIN_TEST_DB_PASSWORD=',
                                    'MICROSYSADMIN_KEEP_TEST_ARTIFACTS=1', str(source / 'tests/isolated-install.sh')],
                timeout=240)
    except Exception as exc:
        failure = str(exc)
        print(failure, flush=True)
    finally:
        if server is not None and server.poll() is None:
            server.terminate()
            try:
                server.wait(timeout=15)
            except subprocess.TimeoutExpired:
                failure = (failure or '') + '; exact child MariaDB did not stop; reconcile before retry'
        receipt = {'status': 'FAIL' if failure else 'PASS', 'runtime': str(runtime),
                   'checks': results, 'error': failure, 'server_exit': server.poll() if server else None,
                   'production_connections': 0, 'customer_data_imports': 0, 'config_copied': False,
                   'sanitized_controller_replay': bool(args.development_projection)}
        (evidence / 'isolation.json').write_text(json.dumps(receipt, indent=2) + '\n')
        print(json.dumps(receipt, indent=2), flush=True)
    return 1 if failure else 0


if __name__ == '__main__':
    raise SystemExit(main())

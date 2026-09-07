# GitHub Linux test machine

This directory provides a disposable Ubuntu 24.04 test machine for MicroSysAdmin.
It uses Docker for OS-level isolation and a separate MySQL 8 container. It does
not require a GitHub account or change the host installation.

The VM checkout is built from Git-tracked and non-ignored local files. Ignored
configuration, credentials, logs, databases, and other machine state are not
copied into it.

## Quick start

Requirements:

- Docker Engine with permission to access the daemon;
- `curl` and Internet access for image and dependency downloads;
- at least 10 GB free when running the complete workflow through `act`.

Start the machine and run the fast local equivalent of both CI jobs:

```bash
./tools/github-test-vm/vm up
./tools/github-test-vm/vm test
```

Run the exact customer clone/install canary in a reset, privileged Ubuntu 24.04 systemd container:

```bash
./tools/github-test-vm/vm install-clean
```

`canary` is an alias. The command builds a disposable clean OS image, constructs an ephemeral bare Git origin containing the current tracked customer source (plus intentional uncommitted changes while developing), clones it as an unprivileged customer, and runs the exact `sudo ./install.sh` command with protected automation values in its environment. It does not mount host MicroSysAdmin configuration, databases, credentials, ignored files, development storage, or an existing installation.

The canary verifies the web response, first-Administrator authentication, diagnostics, release health, ownership/modes, active services, a second idempotent install, `install.sh --upgrade` with verified rollback backups, a deliberately failed installer upgrade and restoration, database backup/restore with controlled data, an ephemeral RSA-signed update, manual rollback, forced-update-failure automatic rollback, safe uninstall preservation, repair, and secret redaction. It produces `canary-report.json` and `canary-report.md`, then copies the complete redacted report set to a timestamped `/tmp/microsysadmin-canary-report-*` directory on the host.

Run just one portion:

```bash
./tools/github-test-vm/vm test repository
./tools/github-test-vm/vm test install
```

Open an interactive shell:

```bash
./tools/github-test-vm/vm shell
cd /workspace/microsysadmin
```

The shell runs as the unprivileged `tester` user, with passwordless `sudo`
available for installation experiments. Use `sync` to discard that checkout
and replace it with the current host worktree's tracked and non-ignored files:

```bash
./tools/github-test-vm/vm sync
```

## Test the actual GitHub Actions workflow

The `actions` command downloads the checksum-verified `act` v0.2.89 binary to a
temporary cache and runs `.github/workflows/ci.yml` with an Ubuntu 24.04 runner
image:

```bash
./tools/github-test-vm/vm actions repository
./tools/github-test-vm/vm actions commercial-tests
./tools/github-test-vm/vm actions clean-install
./tools/github-test-vm/vm actions
```

The first run downloads a large runner image. `act` is a close local emulator,
not GitHub's hosted service; a successful GitHub-hosted run remains the final
check. Only run trusted workflows because local Actions receive access to the
Docker daemon. The workflow reserves host port `13306` for its temporary MySQL
service so it can run alongside a normal MySQL server on port `3306`.

The current workflow does not require repository secrets. If GitHub download
rate limits require a token, export `GITHUB_TOKEN` in the calling shell. The
control script passes it to `act` without writing it into this repository.

## Lifecycle

```bash
./tools/github-test-vm/vm status
./tools/github-test-vm/vm down
./tools/github-test-vm/vm rebuild
./tools/github-test-vm/vm destroy
```

`down` removes the two containers but retains the named workspace volume.
`destroy` also deletes that test-only volume. Neither command changes the host
checkout, MicroSysAdmin database, XAMPP installation, or GitHub repository.

The small `test` runner reproduces the commands from both CI jobs and additionally
checks the documented installer preflight and diagnostics. MySQL is reachable
only on the private Docker network; no host port is opened.

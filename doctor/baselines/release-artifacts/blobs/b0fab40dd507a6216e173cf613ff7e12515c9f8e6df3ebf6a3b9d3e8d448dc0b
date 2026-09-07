# MicroSysAdmin Terminal Git Workflow

`microsysadmin-git` is the supported terminal entry point for repository recovery, policy checks, controlled staging, commits, safe synchronization, and release preparation.

```bash
cd /opt/lampp/htdocs/microsysadmin
./microsysadmin-git --help
./microsysadmin-git doctor
```

The utility resolves the project from its own location, so it may also be invoked by absolute path from another directory.

## Safety model

- No command writes a token into a remote URL or configuration file.
- Recovery evidence does not automatically become `origin`.
- Push, tag, release publication, repository creation, history rewriting, and force push are never automatic.
- `pull` is fast-forward-only.
- `push` fetches and checks ancestry immediately before presenting its command.
- Controlled staging uses an explicit NUL-delimited path manifest; it never runs `git add .`.
- Runtime files, active configuration, backups, dumps, logs, machine credentials, and generated archives fail policy checks.
- Operations are serialized with `flock`.
- Redacted structured logs are stored outside the web root, normally under `/var/lib/microsysadmin/git-manager`.

## Commands

```text
status                         Local branch, remote, and worktree summary
recover                        Display verified local recovery evidence
auth [status|login]            Check or initiate secure GitHub CLI login
audit                          Full repository, syntax, test, secret, and package audit
clean                          Preview ignored/generated files; deletion needs confirmation
prepare [--stage] [--yes]      Build the manifest and optionally stage exactly those paths
diff                           Show unstaged/staged diff summaries
commit [--message TEXT]        Commit only after a fresh successful preflight
fetch                          Fetch/prune only a confirmed remote
pull                           Fast-forward-only pull with a clean worktree
push                           Run every gate and require final confirmation
branch [list|create NAME|switch NAME]
tag [list|create VERSION]      Create an annotated semantic-version tag
release VERSION                Build and verify; never publishes automatically
verify                         Push/release readiness without mutation
doctor                         Tooling, auth, policy, test, remote, and disk report
logs                           Show redacted Git-manager event logs
menu                           Interactive terminal menu
```

## Public repository

```bash
git clone https://github.com/hustlonian/microsysadmin-public.git microsysadmin
cd microsysadmin
```

This repository starts with a clean public source snapshot. Keep installation configuration, credentials, runtime data, and internal deployment records outside Git. The optional `create-private` command creates separate private repositories; it does not change the visibility of this repository.

## Commit flow

```bash
./microsysadmin-git audit
./microsysadmin-git prepare
./microsysadmin-git prepare --stage
./microsysadmin-git diff
./microsysadmin-git commit
```

The commit command refuses stale preflight state, unstaged proposed changes, unresolved conflicts, unsafe files, suspicious large files, or absent author identity.

## Fetch, pull, and push

```bash
./microsysadmin-git fetch
./microsysadmin-git pull
./microsysadmin-git verify
./microsysadmin-git push
```

If histories do not share a base, the utility stops. It does not merge, rebase, force, or overwrite unrelated code. Protected-branch details are queried through GitHub CLI where available; a blocked or indeterminate rule fails closed.

## Releases

```bash
./microsysadmin-git release 1.2.0-beta.1
./microsysadmin-git tag create 1.2.0-beta.1
```

The release command requires a clean tree and passing audit, verifies that release inputs are tracked (with Composer dependencies resolved from the tracked lock file), invokes the reproducible package builder twice, verifies SHA-256 and manifest metadata, prepares notes, and creates a local annotated tag. If the requested version has no changelog section, it moves the reviewed Unreleased block under that version and stops so the changelog can be committed before packaging. Publishing requires a separate, explicit GitHub release action after review. Stable versions require configured package and Git signing; unsigned development output is labelled development only.

## Authentication

Use GitHub CLI's secure login flow:

```bash
./microsysadmin-git auth login
```

Never pass a personal access token as a command argument, paste it into `.microsysadmin-git.conf`, or embed it in an HTTPS URL. SSH is supported only after `ssh -T git@github.com` identifies the expected account.

## Optional scanners

The built-in scanner runs without optional packages and redacts all values. Installing Gitleaks provides a second engine; obtain it from the project's official release packages and verify its checksum. A missing optional scanner is reported as degraded coverage, not silently ignored.

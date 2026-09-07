# Second-machine canary

`1.1.0-beta.1` is not validated until this procedure passes on a second clean supported Linux/XAMPP machine. A second directory, container sharing this host, or another database on the development machine is not sufficient.

## Supported canary profile

- current supported 64-bit Linux distribution;
- clean XAMPP/LAMPP installation;
- PHP 8.2 or newer with PDO MySQL, OpenSSL, JSON, mbstring, ZIP, and cURL;
- Apache and MySQL/MariaDB healthy;
- at least 15 GiB free;
- private-network or VPN access, with HTTPS before any remote user access;
- Safe profile, terminal disabled, code runner disabled, privileged helper disabled;
- ports 80/443 for Apache and the locally selected MySQL socket/port, without public database exposure.

## Artifact transfer and verification

Transfer the complete protected canary kit without placing it under a web root. It contains the archive, checksum, manifest, detached signature, public key, standalone verifier, this guide, result template, and canary script.

```bash
cd /secure/microsysadmin-canary-1.1.0-beta.1
chmod +x run-second-machine-canary.sh
./run-second-machine-canary.sh \
  --kit "$PWD" \
  --extract /opt/lampp/htdocs/microsysadmin-beta
```

Confirm the displayed public-key fingerprint against:

```text
292b108913973d69642a58c4c84953ee203d0203a43b2b6e56dd87082fe97468
```

The preflight verifies checksum, RSA-SHA256 signature, safe archive paths, capacity, XAMPP services, extraction, and `install.sh --check`. It does not install or validate the product by itself.

## Fresh install

Use a unique beta database and avoid command-line passwords:

```bash
cd /opt/lampp/htdocs/microsysadmin-beta
read -rsp "Administrator password: " MICROSYSADMIN_ADMIN_PASSWORD
export MICROSYSADMIN_ADMIN_PASSWORD
./install.sh \
  --admin-username=owner \
  --admin-email=owner@example.com \
  --security-profile=safe \
  --base-url=/microsysadmin-beta \
  --system-integration=preview
unset MICROSYSADMIN_ADMIN_PASSWORD
./diagnose.sh
./health-check.sh --json
```

Record PASS/FAIL/BLOCKED for checksum, signature, installation, first-Administrator creation, login, health, and diagnostics. In the UI verify Safe is visible, Development licensing is unavailable, hub enrollment is unavailable, terminal/code runner/privileged helper are disabled, and debug details are not exposed.

## Product workflows

Using disposable canary data:

1. discover a sample site;
2. provision one sample Laravel site;
3. inspect MySQL and perform a bounded test-database operation;
4. exercise allowed file operations inside configured roots;
5. inspect a sample Git repository without publishing it;
6. create a backup and verify its checksum/inventory;
7. change disposable data, restore the backup through safeguards, and verify the pre-restore backup;
8. run the background worker and confirm a durable job completes;
9. run one scheduled task and confirm audit correlation;
10. verify audit entries for all consequential actions.

## Signed update

Use an earlier compatible signed canary build supplied specifically for this drill. Never weaken signature policy to manufacture the earlier state.

```bash
./update.sh check
./update.sh apply \
  /secure/microsysadmin-canary-1.1.0-beta.1/microsysadmin-1.1.0-beta.1.manifest.json \
  /secure/microsysadmin-canary-1.1.0-beta.1/microsysadmin-1.1.0-beta.1.tar.gz
./health-check.sh --json
```

Verify pre-update application/database backups, migration success, preserved configuration, users and sites, update history, and post-update health.

## Forced failure and rollback

Create a separate, deliberately broken signed canary update with a nonproduction version and signer controlled by the release owner. Apply it only to the disposable canary. Confirm post-update health fails, automatic rollback restores the prior tree, maintenance mode clears, and configuration/data remain intact. Then run a manual rollback against the recorded rollback archive:

```bash
./rollback.sh /var/lib/microsysadmin/releases/rollback-VERSION-TIMESTAMP.tar.gz
./diagnose.sh
./health-check.sh --json
```

Do not simulate this result by editing the result template.

## Negative security tests

On copies of the artifacts:

1. omit the detached signature: verification must fail;
2. alter one archive byte: checksum and verification must fail;
3. substitute a wrong detached signature: verification must fail;
4. substitute another public key: verification must fail;
5. confirm terminal is not reachable or enabled publicly;
6. scan logs, responses, archive, backups, and hub data for secrets;
7. confirm no hub connection is attempted.

## Acceptance

Every row in [Second-machine canary results](SECOND-MACHINE-CANARY-RESULTS.md) must be PASS with evidence. Any FAIL or BLOCKED item prevents payment, customer onboarding, release publication, and the `SIGNED BETA VALIDATED` label.

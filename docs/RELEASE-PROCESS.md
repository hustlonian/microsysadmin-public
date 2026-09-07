# Release process

1. Freeze the intended source state and record its source-control commit.
2. Run PHP lint, `bash -n` on shell entry points, and `/opt/lampp/bin/php tests/run.php`.
3. Run clean installs for XAMPP and supported standard Linux layouts.
4. Restore a representative pre-commercial database and verify all preserved record counts and workflows.
5. Exercise provisioning, backup/restore, users/MFA, API scopes, webhooks, worker restart, Safe mode, and demo isolation.
6. Build a `microsysadmin/` release archive that excludes customer config, `.env`, storage, test secrets, and local backups.
7. Calculate SHA-256.
8. Create a semantic-version manifest with immutable release timestamp and channel.
9. Sign the four-field canonical manifest offline using RSA-SHA256 or Ed25519.
10. Publish package and manifest atomically.
11. Upgrade a canary, verify health/history, and perform a full rollback drill.
12. Promote to a limited pilot ring, then general release only after the observation window.

Never publish from a dirty customer installation. Build through `./microsysadmin-git release VERSION` from a clean, pushed source commit. Stable and beta builds require the protected offline signing key, an embedded matching public key, two byte-identical builds, independent checksum and detached-signature verification, and a clean-machine canary before publication.

The current automated isolated test proves deterministic archive bytes, clean installation, installer idempotency, customer-state exclusions, critical workflows, forced update failure, and application rollback on the validated XAMPP host. It does not replace the clean multi-environment release matrix or the second-machine signed canary described in [Second-machine canary](SECOND-MACHINE-CANARY.md).

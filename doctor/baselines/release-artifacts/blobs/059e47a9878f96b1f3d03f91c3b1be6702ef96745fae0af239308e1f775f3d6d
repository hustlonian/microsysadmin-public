# Building MicroSysAdmin releases

MicroSysAdmin release archives are constructed from an explicit allow-list. The live installation tree is never archived directly.

## Development build

```bash
cd /opt/lampp/htdocs/sysadmin
SOURCE_DATE_EPOCH=0 ./build-release.sh \
  --channel development \
  --released-at 2026-07-27T12:00:00Z \
  --unsigned-development
```

Outputs:

```text
dist/microsysadmin-VERSION.tar.gz
dist/microsysadmin-VERSION.tar.gz.sha256
dist/microsysadmin-VERSION.manifest.json
```

Given identical source bytes, allow/exclude lists, `SOURCE_DATE_EPOCH`, channel, release timestamp, URL, and signing key, the archive bytes and checksum are reproducible.

Normal builds are local-only: the builder reconstructs the complete production `vendor/` tree from immutable, content-addressed baseline blobs, applies the locked modes, and independently verifies the exact file and directory set before packaging. It does not run `composer install`, consult a Composer cache, or access a package network. Capturing a deliberately updated vendor baseline is a separate operator workflow (`--capture-locked-vendor`) and is never part of a normal build.

## Signed beta or stable build

Generate and retain the private key outside the application, release archive, shell history, and web root. RSA-SHA256 works through the required OpenSSL extension:

```bash
openssl genpkey -algorithm RSA \
  -pkeyopt rsa_keygen_bits:3072 \
  -out /secure/offline/microsysadmin-release-private.pem
openssl pkey \
  -in /secure/offline/microsysadmin-release-private.pem \
  -pubout \
  -out /secure/offline/microsysadmin-release-public.pem
```

```bash
SOURCE_DATE_EPOCH=1785672000 ./build-release.sh \
  --channel stable \
  --released-at 2026-08-02T12:00:00Z \
  --package-url https://updates.example.com/microsysadmin-1.1.0.tar.gz \
  --signing-key-file /secure/offline/microsysadmin-release-private.pem
```

Put the PEM public key in the customer release configuration. A base64 Sodium Ed25519 private/public key pair remains supported where Sodium is available. Never copy either private key onto a MicroSysAdmin installation.

## Package policy

`packaging/release-allowlist.txt` is authoritative. `packaging/release-excludes.txt` removes machine/customer state and local one-off utilities. The builder creates empty protected runtime directories.

The package excludes:

- `config/config.php`, `.env`, and machine identity;
- live storage, logs, backups, cache, jobs, uploads, and workspaces;
- customer files, database dumps, demo fixture state, and release output;
- `.git`, `.agents`, and local hosted-hub mock state;
- project-specific conversion and repair scripts.

It includes:

- version, changelog, product documentation, and configuration sample;
- runtime PHP source/assets;
- database schema and migrations;
- installer, diagnose, repair, health, update, rollback, worker, and demo commands;
- Composer lock file and production dependencies;
- tokenized packaging references.

The installer copies this verified locked vendor set and validates it locally before application setup. Unexpected, missing, drifted, symbolic, or incorrectly permissioned dependency artifacts fail closed; materialization is bounded to manifest-owned paths and never removes an unmanifested file.

Tests are deliberately excluded from the downloadable customer package. Before publication, build twice in separate temporary directories, compare SHA-256, inspect `tar -tzf`, run `tests/run.php`, run `tests/isolated-install.sh`, complete the supported-environment matrix, and perform a signed stable canary update/rollback drill.

# Updates

MicroSysAdmin supports `stable`, `beta`, and `development` channels through `UpdateProviderInterface`. A provider may read a filesystem manifest now and can later use a private server, GitHub Releases, or hosted update service without changing the update workflow.

## Manifest

```json
{
  "version": "1.2.0",
  "channel": "stable",
  "released_at": "2026-08-01T12:00:00Z",
  "package_url": "https://updates.example/microsysadmin-1.2.0.tar.gz",
  "sha256": "64-lowercase-hex-characters",
  "signature_algorithm": "RSA-SHA256",
  "signature": "base64-signature"
}
```

The signature covers these newline-separated values:

```text
version
channel
lowercase-sha256
released_at
```

Configure either an RSA public key in PEM form for `RSA-SHA256` or a base64 Ed25519 public key for `Ed25519` in `updates.public_key`. Ed25519 requires Sodium; RSA-SHA256 uses the required OpenSSL extension and works with the current XAMPP PHP build. Unsigned development packages are accepted only when the development channel and explicit development override are both enabled. Never enable that override for customer releases.

## Check and apply

```bash
./update.sh check
./update.sh apply /absolute/manifest.json /absolute/microsysadmin.tar.gz
```

Application steps:

1. validate semantic version and channel;
2. validate checksum and signature;
3. compare release date with `updates.until`;
4. create an application rollback archive;
5. create a database backup;
6. enter maintenance mode;
7. reject absolute or traversal archive entries;
8. stage then synchronize files while preserving `config/config.php`, `.env`, and `storage`;
9. run idempotent migrations;
10. update machine metadata;
11. run installation-critical post-update health checks;
12. record history and leave maintenance mode.

The release gate requires application, database, storage capacity, environment, scheduler, and agent checks. Intentionally stopped Apache/MySQL services remain visible as operational degradation but do not by themselves make a file rollback appear unsuccessful.

On failure, MicroSysAdmin attempts automatic application rollback and rechecks the critical gate. Update entitlement expiry never stops the installed version; it only denies releases newer than the entitled date.

The isolated test has completed an intentionally failed development update and automatic rollback on the validated XAMPP host. Production releases are not broadly certified until signed stable packages pass clean-install and rollback drills on every supported environment.

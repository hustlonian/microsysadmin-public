# Security

MicroSysAdmin should be deployed on a trusted Linux machine and reached through localhost, a private network, or a VPN. Do not publish the browser terminal, Code Runner, agent, or administrator interface directly to the Internet.

Implemented controls include role permissions; organization scope; CSRF checks; password strength and forced reset; lockout and rate limiting; TOTP MFA and hashed one-time recovery codes; session versioning/revocation; security headers; strict path roots; executable allow-lists and bounded process calls; encrypted stored SMTP, agent, and hub secrets; hashed API tokens; signed/replay-protected agent requests; signed timestamped webhooks; redacted structured audits; Safe mode; and demo-driver isolation.

The agent is not unauthenticated even on localhost. Product API tokens expose only health/service/project/backup/version/audit summaries and approved backup/health triggers. There is no external raw terminal, command, SQL, or unrestricted file mutation API.

License failure never creates a remote lockout. It preserves essential local access and denies unavailable premium/network features. There is no remote kill switch.

Private state should live in `/var/lib/microsysadmin` with owner/service-group access only. `config/config.php` must not be web served. The included configuration currently resides under the application document tree for compatibility, so Apache rules and file permissions remain defense-in-depth requirements; moving all configuration outside the document tree is a pre-broad-launch recommendation.

Updates require SHA-256 plus RSA-SHA256 or Ed25519 signature verification outside explicitly enabled development-channel testing. Keep the signing private key offline; MicroSysAdmin stores only the public key.

The release archive includes a base-path-independent Apache `.htaccess` that denies direct access to configuration, classes, scripts, migrations, packaging, tests, vendor internals, and storage. This is defense in depth; the production virtual host should still point at the intended entry point and repeat equivalent denial rules.

Demo mode routes pages and legacy APIs to fixtures, disables external product/agent APIs, password changes, workspace preview, real scheduling, terminal access, and real service/file/database actions. A public demo still belongs on a separate Safe-mode installation containing no customer data or credentials.

See `SECURITY-AUDIT.md`, `SECURITY-PROFILES.md`, and `KNOWN-LIMITATIONS.md`.

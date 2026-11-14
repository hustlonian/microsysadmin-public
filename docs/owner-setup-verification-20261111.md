# Owner Setup configuration verification — 2026-11-11

The hosting form now returns its saved deployment scope and database connection
fields, so an ordinary read/save no longer clears them. Credential presence is
reported as `HOSTING_CONFIGURED_NOT_VERIFIED`; it never claims a successful
connection or authorized deployment.

The Stripe form accepts restricted API keys as well as existing secret keys,
requires the selected test/live prefix, and rejects malformed values. Each mode's
webhook-presence indicator is separate; signing secrets are never returned.
Blank signing-secret input retains the saved value. The form explains that
webhook verification is required before payment integration acceptance.
Restricted-key guidance follows [Stripe's documentation](https://docs.stripe.com/keys/restricted-api-keys).
This is credential storage only: no Stripe calls, charges or live activation.

The existing Administrator permission and CSRF boundary is unchanged. Hosting,
mail and Stripe credentials use the existing authenticated encryption. Email
configuration remains NOT_TESTED, and this work sends no email.

Verification: `tests/owner-setup.php` passed 33 assertions in a newly generated
private MariaDB runtime: persistence, actual view rendering, encryption round-trip,
redaction, blank credential retention, restricted keys, invalid-key no-mutation,
mode isolation and disabled live payments. PHP syntax and Git whitespace checks
passed. The isolated runner's new `--owner-setup-only` option avoids maintenance
job dispatch and uses no installed configuration or production database.

```sh
python3 tests/job-recovery-isolated.py --evidence-dir /tmp/owner-setup-evidence --owner-setup-only
# If this checkout has no vendor directory, add --vendor-dir /path/to/existing/vendor
```

The previous full application result remains 23 passed / 1 failed. Its synthetic
maintenance health-check outcome is preserved as unknown; it was not retried.
This focused acceptance does not relabel that full-suite failure or accept the
whole application. Hosting connection checks, controlled email delivery checks,
Stripe API/webhook integration and production acceptance remain separate work.
The preexisting remote branch's main-base compatibility also remains unresolved;
no protected merge or production deployment is performed.

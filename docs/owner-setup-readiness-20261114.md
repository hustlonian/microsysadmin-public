# Owner Setup readiness increment — 2026-11-14

This development increment adds durable, fail-closed verification contracts to
the existing Administrator-only Owner Setup service. It does not connect to a
real host, send a real message, call Stripe, enable payments, deploy source, or
change Customer Zero.

## Implemented contracts

- Hosting compatibility records a request identity and configuration digest
  before invoking a caller-supplied read-only probe. While the probe is pending,
  configuration changes are rejected. A completed request is replay-safe.
- Transactional email records a delivery intent before invoking a caller-supplied
  adapter. An interrupted or rejected adapter result becomes
  `DELIVERY_OUTCOME_UNKNOWN_NO_RETRY`; a new test or configuration change remains
  blocked until an authorized caller reconciles the provider's durable record.
- Stripe test-mode webhook verification requires a test-mode payload whose event
  identity matches the request, a fresh valid signature, and configured test
  credentials. Replays with the same payload return the durable receipt; changed
  payloads, live-mode events, stale signatures, and invalid signatures fail closed.
- Hosting, email, and Stripe receipts expose only request identities, timestamps,
  statuses, and digests. Stored provider credentials remain encrypted and are not
  returned by the overview contract.
- Replacing a test API key or webhook signing secret invalidates prior Stripe
  webhook receipts. Live credential presence never enables charges.

## Verification

The current dirty source was copied into fresh disposable runtimes with a private,
network-disabled MariaDB server and generated configuration:

- Focused Owner Setup suite: 52 assertions passed; no external calls.
- Application suite: 24 tests passed.
- Job-recovery regression suite: 12 tests / 46 assertions passed.
- Composer validation, forbidden-file policy, worktree and history secret scans,
  repository-wide PHP syntax, shell syntax, required-documentation, release
  contamination, and Git whitespace checks passed.
- The disposable runner reported zero production connections and zero customer
  data imports.

Evidence is retained under
`.deploy/microsysadmin-owner-setup-readiness-20261114/`. The first application
integration attempt failed before configuration or database startup because the
isolated worktree had no dependency directory; the documented parent dependency
directory was supplied to a fresh second attempt, which passed. The earlier
focused negative control also remains retained.

## Remaining boundaries

No concrete hosting, email, or Stripe HTTP adapter is wired by this increment.
Real credential enrollment, provider reconciliation, real host compatibility,
external delivery, Stripe API/payment behavior, checkout, protected merge,
release, deployment, recovery, and commercial acceptance remain separate gates.
The historical unknown maintenance worker is preserved and was not retried; the
passing job-recovery regression used new disposable identities only.

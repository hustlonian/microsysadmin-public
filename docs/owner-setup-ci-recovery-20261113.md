# Owner Setup CI recovery

Draft PR 9 uses the existing Command Center parent `35535d09` as its development
base. This keeps the Owner Setup review separate from the inherited changes to
main. The base remains unmerged; production acceptance is separate.

Initial CI run 43517486226 passed clean installation but found two inherited
failures. Both original failures remain recorded.

The worker rejected a successfully produced degraded health report because its
`ok` field was false. Health inspection completion now preserves that report,
including its false health result, without treating it as an unknown mutation.
Effectful handlers still enter reconciliation after unsuccessful results. No
historical unknown job is changed or retried.

A fresh disposable regression failed before the repair and passes afterward.
All 12 job recovery tests / 46 assertions and all 24 application cases pass in a
new private MariaDB runtime. The job tests include failed effects, process death,
concurrent execution, deadlines, stale attempts, and no automatic retries.

The history scan found one synthetic negative-test URL in historical blob
`e4829bda800c115abdc6b8ea9af310282fb958e5`, introduced in `59326359` and removed by
`5a6b4148`. The exception binds the exact blob, file, line number, category, and
line SHA-256. It does not exclude the path or any current-source match. Regression
checks prove that the known historic fixture passes while current source and a
changed historical blob still fail. Full history scanning remains enabled.

These checks establish isolated development behavior. Real hosting checks,
email delivery, payment API/webhook integration, and installation remain gated.

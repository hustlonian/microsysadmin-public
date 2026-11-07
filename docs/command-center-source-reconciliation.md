# Command Center source reconciliation — 2026-11-07

The November 7 checkpoint's installed Command Center source differs from the
independently tested candidate `5339a4f3f9aefb80a23fb22fb9c658997661d00d`.
Replaying its eleven exported source files in a disposable copy fails the
candidate's access-metadata assertion. Inspection also finds absent workflow
domain projections, owner-review navigation and evidence inspection support.
The installed authorization call remains present; the assertion failure is not
evidence of a demonstrated privilege escalation.

This development candidate starts from the tested source and carries forward
the installed additions: trusted `development.local.php` bindings, automatic
selection of a single authorized project, the narrative summary and descriptive
roadmap labels. Existing candidate access metadata, exact workflow evidence,
atomic bundle support, owner-review navigation, stale-claim expiration and
tenant authorization stay intact. No installed configuration is copied.

Explicit configured project arrays take precedence over local fallback, including
an empty array. Local bindings pass through the same organization membership and
explicit project-role checks. Automatic selection considers only authorized
projects. Narrative text is presentation, with no effect on approvals, workflow
decisions or readiness; stale reports cannot claim active work.

The backend regression covers local bindings, foreign-tenant exclusion, explicit
empty configuration, default project selection, narrative/access rendering,
evidence navigation and stale narrative status, alongside the existing workflow
domain, owner-review, atomic-bundle, integrity and freshness contracts. Browser
checks cover default selection, narrative visibility, retained evidence access,
all views, tenant/platform boundaries, exports, stale workflow claims and mobile
rendering. All databases, users, configuration and servers are disposable.

The first browser attempt used an expired workflow fixture without the domain
fixture option. The expected fail-closed BLOCKED gates failed its DRAFT-only
assertion. The retry refreshes the generated fixture and explicitly supplies it
to the browser's stale-domain tests. No acceptance condition or application
freshness check was relaxed.

The installed working tree, local configuration and databases remain untouched.
This candidate grants no installation, production, CZ-A1, live billing or owner
gate authority. Review and separately authorized installation remain necessary.

# Managed release-directory restoration

Each release directory has two mutually exclusive condition owners: one repair
for absence and one for permission-mode drift on an existing directory. The
same immutable release manifest independently authorizes both permanent repair
IDs, their shared path, exact mode, and distinct condition. Missing ancestor
segments may be created only by the absence owner and only when every segment
is present in that topology manifest.

The primitive records the device/inode identity and exact mode for every
created segment. Rollback visits them in reverse order and removes a segment
only while its identity and mode are unchanged and it is empty. For a
pre-existing directory with mode drift, the mode owner records its identity
and prior mode, applies the manifest mode, and restores the prior mode only
while that identity and repaired mode remain unchanged. A missing state is
inapplicable to the mode owner. Symbolic-link and non-directory obstructions
are never replaced automatically by either owner.

Managed-artifact file restoration uses the same topology catalog to create
missing authorized parent directories before an atomic file replacement, so
repair correctness does not depend on execution ordering.
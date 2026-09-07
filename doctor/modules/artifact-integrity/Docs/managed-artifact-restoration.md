# Managed artifact restoration

Each ARTIFACT repair owns exactly one application release file. The release
manifest fixes the target path, baseline blob, SHA-256 digest, and permission
mode. A repair reads no network resource and invokes no external provider or
package manager. Before replacement it captures the existing regular file;
replacement uses an atomic rename; verification repeats the independent hash
and mode probe. Rollback restores the validated snapshot, or removes an
artifact created from a previously missing state only while its installed hash
still matches the recorded repair output.

Artifact enforcement is not applicable under the developer security profile.
That boundary prevents Doctor from overwriting intentional working-tree edits.
The generator derives its complete candidate set from
`packaging/release-allowlist.txt` and `packaging/release-excludes.txt`, so a
developer-only path cannot become an executable repair merely because it
exists in a working tree. Release builds regenerate the local baseline
explicitly and preserve permanent path-to-repair ID assignments. An ID whose
path leaves the release policy is retained as a non-executable tombstone and is
never reassigned to a different path.
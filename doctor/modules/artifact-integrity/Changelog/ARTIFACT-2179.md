# ARTIFACT-2179

Added exact missing release-directory restoration for `doctor/modules/apache/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2179`
- Rollback: automatic identity journal

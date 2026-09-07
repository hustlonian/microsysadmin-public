# ARTIFACT-2364

Added exact missing release-directory restoration for `packaging/systemd`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2364`
- Rollback: automatic identity journal

# ARTIFACT-2388

Added exact missing release-directory restoration for `vendor/psr/log/src`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2388`
- Rollback: automatic identity journal

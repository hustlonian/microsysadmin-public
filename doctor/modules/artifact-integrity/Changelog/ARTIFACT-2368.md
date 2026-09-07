# ARTIFACT-2368

Added exact missing release-directory restoration for `vendor/composer`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2368`
- Rollback: automatic identity journal

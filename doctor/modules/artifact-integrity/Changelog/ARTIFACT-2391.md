# ARTIFACT-2391

Added exact missing release-directory restoration for `vendor/symfony/process/Exception`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2391`
- Rollback: automatic identity journal

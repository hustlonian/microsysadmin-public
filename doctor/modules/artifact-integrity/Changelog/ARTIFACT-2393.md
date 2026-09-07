# ARTIFACT-2393

Added exact missing release-directory restoration for `vendor/symfony/process/Pipes`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2393`
- Rollback: automatic identity journal

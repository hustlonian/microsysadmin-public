# ARTIFACT-2377

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Handler/FingersCrossed`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2377`
- Rollback: automatic identity journal

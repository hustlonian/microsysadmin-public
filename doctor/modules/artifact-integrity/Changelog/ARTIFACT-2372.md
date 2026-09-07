# ARTIFACT-2372

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2372`
- Rollback: automatic identity journal

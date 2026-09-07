# ARTIFACT-2375

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Handler`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2375`
- Rollback: automatic identity journal

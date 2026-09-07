# ARTIFACT-2379

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Handler/SyslogUdp`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2379`
- Rollback: automatic identity journal

# ARTIFACT-2634

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Handler/SyslogUdp`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2634`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2463

Added exact release-directory mode correction for `doctor/modules/cron/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2463`
- Rollback: automatic identity/prior-mode journal

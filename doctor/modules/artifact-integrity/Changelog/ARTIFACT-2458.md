# ARTIFACT-2458

Added exact release-directory mode correction for `doctor/modules/cron/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2458`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2460

Added exact release-directory mode correction for `doctor/modules/cron/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2460`
- Rollback: automatic identity/prior-mode journal

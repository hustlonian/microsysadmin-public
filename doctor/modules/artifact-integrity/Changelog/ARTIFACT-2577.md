# ARTIFACT-2577

Added exact release-directory mode correction for `doctor/modules/redis/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2577`
- Rollback: automatic identity/prior-mode journal

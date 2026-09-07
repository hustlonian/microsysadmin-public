# ARTIFACT-2532

Added exact release-directory mode correction for `doctor/modules/mysql/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2532`
- Rollback: automatic identity/prior-mode journal

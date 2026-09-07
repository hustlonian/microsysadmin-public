# ARTIFACT-2589

Added exact release-directory mode correction for `doctor/modules/security/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2589`
- Rollback: automatic identity/prior-mode journal

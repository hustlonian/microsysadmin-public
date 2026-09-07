# ARTIFACT-2526

Added exact release-directory mode correction for `doctor/modules/mail/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2526`
- Rollback: automatic identity/prior-mode journal

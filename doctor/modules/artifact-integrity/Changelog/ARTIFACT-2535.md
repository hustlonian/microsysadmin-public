# ARTIFACT-2535

Added exact release-directory mode correction for `doctor/modules/mysql/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2535`
- Rollback: automatic identity/prior-mode journal

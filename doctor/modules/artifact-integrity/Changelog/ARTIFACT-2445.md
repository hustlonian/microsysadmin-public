# ARTIFACT-2445

Added exact release-directory mode correction for `doctor/modules/backups/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2445`
- Rollback: automatic identity/prior-mode journal

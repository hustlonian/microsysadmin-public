# ARTIFACT-2438

Added exact release-directory mode correction for `doctor/modules/backups`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2438`
- Rollback: automatic identity/prior-mode journal

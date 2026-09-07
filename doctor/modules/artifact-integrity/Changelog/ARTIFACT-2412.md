# ARTIFACT-2412

Added exact release-directory mode correction for `doctor/engine/Backup`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2412`
- Rollback: automatic identity/prior-mode journal

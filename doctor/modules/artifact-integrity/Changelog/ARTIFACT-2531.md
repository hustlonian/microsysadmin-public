# ARTIFACT-2531

Added exact release-directory mode correction for `doctor/modules/mysql/Commercial`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2531`
- Rollback: automatic identity/prior-mode journal

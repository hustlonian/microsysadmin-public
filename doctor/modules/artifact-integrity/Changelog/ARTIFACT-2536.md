# ARTIFACT-2536

Added exact release-directory mode correction for `doctor/modules/mysql/Tests`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2536`
- Rollback: automatic identity/prior-mode journal

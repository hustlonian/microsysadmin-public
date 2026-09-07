# ARTIFACT-2529

Added exact release-directory mode correction for `doctor/modules/mysql/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2529`
- Rollback: automatic identity/prior-mode journal

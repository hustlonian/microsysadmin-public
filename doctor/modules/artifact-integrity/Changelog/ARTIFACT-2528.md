# ARTIFACT-2528

Added exact release-directory mode correction for `doctor/modules/mysql`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2528`
- Rollback: automatic identity/prior-mode journal

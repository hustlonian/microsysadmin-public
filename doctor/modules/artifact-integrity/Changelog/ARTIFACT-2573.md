# ARTIFACT-2573

Added exact release-directory mode correction for `doctor/modules/redis`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2573`
- Rollback: automatic identity/prior-mode journal

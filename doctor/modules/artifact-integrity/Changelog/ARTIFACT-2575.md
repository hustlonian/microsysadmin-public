# ARTIFACT-2575

Added exact release-directory mode correction for `doctor/modules/redis/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2575`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2580

Added exact release-directory mode correction for `doctor/modules/redis/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2580`
- Rollback: automatic identity/prior-mode journal

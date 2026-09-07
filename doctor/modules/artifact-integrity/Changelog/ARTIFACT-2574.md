# ARTIFACT-2574

Added exact release-directory mode correction for `doctor/modules/redis/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2574`
- Rollback: automatic identity/prior-mode journal

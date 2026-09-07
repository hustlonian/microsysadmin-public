# ARTIFACT-2579

Added exact release-directory mode correction for `doctor/modules/redis/Fixtures`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2579`
- Rollback: automatic identity/prior-mode journal

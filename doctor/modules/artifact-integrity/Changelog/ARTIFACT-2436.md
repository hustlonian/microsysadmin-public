# ARTIFACT-2436

Added exact release-directory mode correction for `doctor/modules/apache/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2436`
- Rollback: automatic identity/prior-mode journal

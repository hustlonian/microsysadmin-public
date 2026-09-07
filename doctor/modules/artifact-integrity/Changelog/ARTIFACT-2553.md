# ARTIFACT-2553

Added exact release-directory mode correction for `doctor/modules/performance/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2553`
- Rollback: automatic identity/prior-mode journal

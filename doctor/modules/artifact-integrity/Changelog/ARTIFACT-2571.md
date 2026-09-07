# ARTIFACT-2571

Added exact release-directory mode correction for `doctor/modules/queue/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2571`
- Rollback: automatic identity/prior-mode journal

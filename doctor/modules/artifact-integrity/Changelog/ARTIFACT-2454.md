# ARTIFACT-2454

Added exact release-directory mode correction for `doctor/modules/composer/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2454`
- Rollback: automatic identity/prior-mode journal

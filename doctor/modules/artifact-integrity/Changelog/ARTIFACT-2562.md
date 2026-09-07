# ARTIFACT-2562

Added exact release-directory mode correction for `doctor/modules/php/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2562`
- Rollback: automatic identity/prior-mode journal

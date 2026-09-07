# ARTIFACT-2447

Added exact release-directory mode correction for `doctor/modules/composer`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2447`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2448

Added exact release-directory mode correction for `doctor/modules/composer/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2448`
- Rollback: automatic identity/prior-mode journal

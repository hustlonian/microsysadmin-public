# ARTIFACT-2484

Added exact release-directory mode correction for `doctor/modules/filesystem/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2484`
- Rollback: automatic identity/prior-mode journal

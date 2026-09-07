# ARTIFACT-2430

Added exact release-directory mode correction for `doctor/modules/apache/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2430`
- Rollback: automatic identity/prior-mode journal

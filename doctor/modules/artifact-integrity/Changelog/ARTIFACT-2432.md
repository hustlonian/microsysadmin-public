# ARTIFACT-2432

Added exact release-directory mode correction for `doctor/modules/apache/Commercial`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2432`
- Rollback: automatic identity/prior-mode journal

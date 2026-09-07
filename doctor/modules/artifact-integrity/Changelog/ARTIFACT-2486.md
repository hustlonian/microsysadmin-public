# ARTIFACT-2486

Added exact release-directory mode correction for `doctor/modules/filesystem/Commercial`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2486`
- Rollback: automatic identity/prior-mode journal

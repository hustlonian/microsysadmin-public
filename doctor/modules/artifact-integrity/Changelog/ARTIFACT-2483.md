# ARTIFACT-2483

Added exact release-directory mode correction for `doctor/modules/filesystem`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2483`
- Rollback: automatic identity/prior-mode journal

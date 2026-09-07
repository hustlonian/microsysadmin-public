# ARTIFACT-2435

Added exact release-directory mode correction for `doctor/modules/apache/Fixtures`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2435`
- Rollback: automatic identity/prior-mode journal

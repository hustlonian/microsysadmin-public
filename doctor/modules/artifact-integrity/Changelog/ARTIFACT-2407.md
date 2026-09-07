# ARTIFACT-2407

Added exact release-directory mode correction for `database/migrations`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2407`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2406

Added exact release-directory mode correction for `database`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2406`
- Rollback: automatic identity/prior-mode journal

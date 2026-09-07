# ARTIFACT-2533

Added exact release-directory mode correction for `doctor/modules/mysql/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2533`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2538

Added exact release-directory mode correction for `doctor/modules/nginx/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2538`
- Rollback: automatic identity/prior-mode journal

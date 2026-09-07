# ARTIFACT-2543

Added exact release-directory mode correction for `doctor/modules/nginx/Fixtures`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2543`
- Rollback: automatic identity/prior-mode journal

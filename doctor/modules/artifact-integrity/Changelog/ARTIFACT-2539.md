# ARTIFACT-2539

Added exact release-directory mode correction for `doctor/modules/nginx/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2539`
- Rollback: automatic identity/prior-mode journal

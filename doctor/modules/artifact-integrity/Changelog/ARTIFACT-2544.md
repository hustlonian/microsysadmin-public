# ARTIFACT-2544

Added exact release-directory mode correction for `doctor/modules/nginx/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2544`
- Rollback: automatic identity/prior-mode journal

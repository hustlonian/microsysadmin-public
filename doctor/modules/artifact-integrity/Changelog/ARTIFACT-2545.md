# ARTIFACT-2545

Added exact release-directory mode correction for `doctor/modules/nginx/Tests`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2545`
- Rollback: automatic identity/prior-mode journal

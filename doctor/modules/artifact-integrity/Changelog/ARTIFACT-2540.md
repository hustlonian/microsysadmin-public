# ARTIFACT-2540

Added exact release-directory mode correction for `doctor/modules/nginx/Commercial`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2540`
- Rollback: automatic identity/prior-mode journal

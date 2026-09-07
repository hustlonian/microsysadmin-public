# ARTIFACT-2481

Added exact release-directory mode correction for `doctor/modules/docker/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2481`
- Rollback: automatic identity/prior-mode journal

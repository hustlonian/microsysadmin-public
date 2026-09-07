# ARTIFACT-2476

Added exact release-directory mode correction for `doctor/modules/docker/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2476`
- Rollback: automatic identity/prior-mode journal

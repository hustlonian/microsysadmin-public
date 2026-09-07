# ARTIFACT-2474

Added exact release-directory mode correction for `doctor/modules/docker`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2474`
- Rollback: automatic identity/prior-mode journal

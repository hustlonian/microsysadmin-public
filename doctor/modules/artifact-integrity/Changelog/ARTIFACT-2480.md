# ARTIFACT-2480

Added exact release-directory mode correction for `doctor/modules/docker/Fixtures`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2480`
- Rollback: automatic identity/prior-mode journal

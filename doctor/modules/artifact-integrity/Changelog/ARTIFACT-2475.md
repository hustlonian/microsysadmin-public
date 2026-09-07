# ARTIFACT-2475

Added exact release-directory mode correction for `doctor/modules/docker/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2475`
- Rollback: automatic identity/prior-mode journal

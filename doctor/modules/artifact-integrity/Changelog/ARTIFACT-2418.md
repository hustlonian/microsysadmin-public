# ARTIFACT-2418

Added exact release-directory mode correction for `doctor/engine/Filesystem`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2418`
- Rollback: automatic identity/prior-mode journal

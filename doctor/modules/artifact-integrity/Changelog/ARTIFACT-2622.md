# ARTIFACT-2622

Added exact release-directory mode correction for `vendor`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2622`
- Rollback: automatic identity/prior-mode journal

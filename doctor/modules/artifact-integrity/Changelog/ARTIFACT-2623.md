# ARTIFACT-2623

Added exact release-directory mode correction for `vendor/composer`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2623`
- Rollback: automatic identity/prior-mode journal

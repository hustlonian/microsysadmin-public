# ARTIFACT-2624

Added exact release-directory mode correction for `vendor/monolog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2624`
- Rollback: automatic identity/prior-mode journal

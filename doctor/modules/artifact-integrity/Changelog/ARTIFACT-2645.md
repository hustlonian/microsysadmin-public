# ARTIFACT-2645

Added exact release-directory mode correction for `vendor/symfony/process`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2645`
- Rollback: automatic identity/prior-mode journal

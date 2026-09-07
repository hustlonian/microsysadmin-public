# ARTIFACT-2647

Added exact release-directory mode correction for `vendor/symfony/process/Messenger`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2647`
- Rollback: automatic identity/prior-mode journal

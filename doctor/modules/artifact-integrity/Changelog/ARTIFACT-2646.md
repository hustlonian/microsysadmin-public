# ARTIFACT-2646

Added exact release-directory mode correction for `vendor/symfony/process/Exception`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2646`
- Rollback: automatic identity/prior-mode journal

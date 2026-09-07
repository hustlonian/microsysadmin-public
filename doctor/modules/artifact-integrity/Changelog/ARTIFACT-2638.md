# ARTIFACT-2638

Added exact release-directory mode correction for `vendor/phpmailer/phpmailer`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2638`
- Rollback: automatic identity/prior-mode journal

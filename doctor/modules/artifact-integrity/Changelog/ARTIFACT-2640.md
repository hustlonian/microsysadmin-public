# ARTIFACT-2640

Added exact release-directory mode correction for `vendor/phpmailer/phpmailer/src`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2640`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2637

Added exact release-directory mode correction for `vendor/phpmailer`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2637`
- Rollback: automatic identity/prior-mode journal

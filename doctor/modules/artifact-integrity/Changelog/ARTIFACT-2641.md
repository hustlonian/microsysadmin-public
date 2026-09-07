# ARTIFACT-2641

Added exact release-directory mode correction for `vendor/psr`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2641`
- Rollback: automatic identity/prior-mode journal

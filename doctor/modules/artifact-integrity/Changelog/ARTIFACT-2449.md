# ARTIFACT-2449

Added exact release-directory mode correction for `doctor/modules/composer/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2449`
- Rollback: automatic identity/prior-mode journal

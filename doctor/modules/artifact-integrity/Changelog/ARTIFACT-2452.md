# ARTIFACT-2452

Added exact release-directory mode correction for `doctor/modules/composer/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2452`
- Rollback: automatic identity/prior-mode journal

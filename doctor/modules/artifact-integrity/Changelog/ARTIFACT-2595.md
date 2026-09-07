# ARTIFACT-2595

Added exact release-directory mode correction for `doctor/modules/ssl/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2595`
- Rollback: automatic identity/prior-mode journal

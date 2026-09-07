# ARTIFACT-2514

Added exact release-directory mode correction for `doctor/modules/linux/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2514`
- Rollback: automatic identity/prior-mode journal

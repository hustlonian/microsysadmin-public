# ARTIFACT-2451

Added exact release-directory mode correction for `doctor/modules/composer/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2451`
- Rollback: automatic identity/prior-mode journal

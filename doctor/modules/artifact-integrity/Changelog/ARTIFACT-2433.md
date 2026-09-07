# ARTIFACT-2433

Added exact release-directory mode correction for `doctor/modules/apache/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2433`
- Rollback: automatic identity/prior-mode journal

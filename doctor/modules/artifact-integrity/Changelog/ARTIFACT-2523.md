# ARTIFACT-2523

Added exact release-directory mode correction for `doctor/modules/mail/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2523`
- Rollback: automatic identity/prior-mode journal

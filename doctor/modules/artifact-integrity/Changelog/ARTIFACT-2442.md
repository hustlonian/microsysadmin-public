# ARTIFACT-2442

Added exact release-directory mode correction for `doctor/modules/backups/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2442`
- Rollback: automatic identity/prior-mode journal

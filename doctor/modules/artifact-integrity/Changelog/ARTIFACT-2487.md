# ARTIFACT-2487

Added exact release-directory mode correction for `doctor/modules/filesystem/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2487`
- Rollback: automatic identity/prior-mode journal

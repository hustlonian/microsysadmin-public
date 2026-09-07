# ARTIFACT-2478

Added exact release-directory mode correction for `doctor/modules/docker/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2478`
- Rollback: automatic identity/prior-mode journal

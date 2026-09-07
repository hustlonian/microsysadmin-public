# ARTIFACT-2541

Added exact release-directory mode correction for `doctor/modules/nginx/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2541`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2505

Added exact release-directory mode correction for `doctor/modules/laravel/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2505`
- Rollback: automatic identity/prior-mode journal

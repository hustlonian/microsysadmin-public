# ARTIFACT-2508

Added exact release-directory mode correction for `doctor/modules/laravel/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2508`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2253

Added exact missing release-directory restoration for `doctor/modules/laravel/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2253`
- Rollback: automatic identity journal

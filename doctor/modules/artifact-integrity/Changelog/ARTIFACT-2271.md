# ARTIFACT-2271

Added exact missing release-directory restoration for `doctor/modules/mail/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2271`
- Rollback: automatic identity journal

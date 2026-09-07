# ARTIFACT-2282

Added exact missing release-directory restoration for `doctor/modules/nginx`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2282`
- Rollback: automatic identity journal

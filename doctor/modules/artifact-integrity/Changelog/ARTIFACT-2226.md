# ARTIFACT-2226

Added exact missing release-directory restoration for `doctor/modules/docker/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2226`
- Rollback: automatic identity journal

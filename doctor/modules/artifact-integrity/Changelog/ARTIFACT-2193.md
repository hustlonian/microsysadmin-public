# ARTIFACT-2193

Added exact missing release-directory restoration for `doctor/modules/composer/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2193`
- Rollback: automatic identity journal

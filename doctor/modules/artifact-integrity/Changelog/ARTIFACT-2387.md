# ARTIFACT-2387

Added exact missing release-directory restoration for `vendor/psr/log`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2387`
- Rollback: automatic identity journal

# ARTIFACT-2512

Added exact release-directory mode correction for `doctor/modules/linux/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2512`
- Rollback: automatic identity/prior-mode journal

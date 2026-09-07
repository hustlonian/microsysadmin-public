# ARTIFACT-2431

Added exact release-directory mode correction for `doctor/modules/apache/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2431`
- Rollback: automatic identity/prior-mode journal

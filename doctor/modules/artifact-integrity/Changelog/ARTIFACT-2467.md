# ARTIFACT-2467

Added exact release-directory mode correction for `doctor/modules/dns/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2467`
- Rollback: automatic identity/prior-mode journal

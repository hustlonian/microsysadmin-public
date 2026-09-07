# ARTIFACT-2469

Added exact release-directory mode correction for `doctor/modules/dns/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2469`
- Rollback: automatic identity/prior-mode journal

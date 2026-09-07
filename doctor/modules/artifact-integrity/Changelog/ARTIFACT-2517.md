# ARTIFACT-2517

Added exact release-directory mode correction for `doctor/modules/linux/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2517`
- Rollback: automatic identity/prior-mode journal

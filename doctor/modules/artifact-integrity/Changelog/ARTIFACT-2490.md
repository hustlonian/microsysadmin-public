# ARTIFACT-2490

Added exact release-directory mode correction for `doctor/modules/filesystem/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2490`
- Rollback: automatic identity/prior-mode journal

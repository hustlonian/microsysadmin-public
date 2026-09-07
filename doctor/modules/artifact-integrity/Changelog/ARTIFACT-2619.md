# ARTIFACT-2619

Added exact release-directory mode correction for `packaging/systemd`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2619`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2618

Added exact release-directory mode correction for `packaging/logrotate`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2618`
- Rollback: automatic identity/prior-mode journal

# ARTIFACT-2542

Added exact release-directory mode correction for `doctor/modules/nginx/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2542`
- Rollback: automatic identity/prior-mode journal

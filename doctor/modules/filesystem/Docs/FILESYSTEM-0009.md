# FILESYSTEM-0009: Repair a broken project public storage link

Applies only when `public/storage` is already a symbolic link and points away from the exact managed `storage/app/public` target. Missing links remain owned by `LARAVEL-0009`; regular files and directories are never replaced.

The primitive rejects links escaping the project, captures the exact prior link target, verifies the corrected target independently, and rolls back only while the repaired link still has its recorded target.
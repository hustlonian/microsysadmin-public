# SECURITY-0007: Disable Laravel debug output in production

Applies only to the exact project `.env` when it contains one unambiguous `APP_ENV=production` assignment and one supported enabled `APP_DEBUG` boolean. Non-production, missing, duplicate, unsupported, symbolic, externally owned, or unwritable states are never changed automatically.

The semantic dotenv primitive captures a secret-safe exact snapshot, changes only `APP_DEBUG`, emits no other environment values or diff content, verifies the file hash and parsed value independently, and restores the original file only while the post-repair hash is unchanged.
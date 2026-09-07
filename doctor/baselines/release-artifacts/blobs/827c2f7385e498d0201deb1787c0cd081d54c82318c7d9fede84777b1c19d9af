# SECURITY-0003: Restore missing XAMPP private-key storage

Applies only when XAMPP is the primary web server, its fixed installation and `etc` roots already exist, and `/opt/lampp/etc/ssl.key` is absent. The structured action carries the allowlisted `xampp-ssl-key` token and its exact audited path; arbitrary privileged paths and recursive parent creation are impossible.

The helper creates one directory and returns its device, inode, mode, UID, and GID. Rollback requires that complete identity, refuses symbolic or non-directory targets, refuses a directory after any key or other content appears, removes only the exact empty directory created by this repair, and independently verifies absence.
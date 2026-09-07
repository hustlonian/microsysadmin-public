# XAMPP-0010: Restore the missing XAMPP log directory

Applies only when XAMPP is the primary web server, the fixed installation root exists, and `/opt/lampp/logs` is absent. The structured action carries an allowlisted `xampp-logs` token and its exact audited path; arbitrary privileged paths are impossible.

The helper creates one directory without recursive parent creation and returns its device, inode, mode, UID, and GID. Rollback requires that complete identity, refuses symbolic or non-directory targets, refuses any non-empty directory, removes only that exact path, and independently verifies absence.
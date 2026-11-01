# Doctor rollback and recovery

Doctor executes an exact stable plan under its existing exclusive execution lock.
The lifecycle is preflight, current policy and approval, durable execution claim,
backup, recorded action attempts, execution, independent verification and commit
or reverse compensation. REPORT_ONLY, prohibited and destructive definitions
never enter execution, even if a caller supplies an allowed policy projection.
Required backup and explicit approval rules remain in force. A definition that
advertises rollback cannot run a primitive without a reversible contract.

Claims live beside the execution lock in `<lock>.journal/`, using hashed operation
identities and private, fsynced JSON records. Directory entries are synced after claim creation and outcome replacement. Repeating the same execution/project/
repair/plan does not execute or consume another approval. An unfinished claim or
incomplete rollback also blocks a different execution ID for that project/repair.
Do not remove the journal to retry an unknown effect. Reconcile the original
execution, backups, audit and actual state under operator authority first.
Policy denials and duplicates are audited without invoking actions.

Each filesystem action has an immutable prior-state capture, including absence and missing parent directories.
Rollback reverses action order, attempts independent earlier actions after a
failure, refuses to overwrite recorded post-state drift, and compares restored
content, type, permissions and ownership with the preimage. A successful rollback
call alone cannot produce ROLLED_BACK. Corrupt backups and unsupported transitions
fail closed. Service and crontab exceptions carry captured compensation state; crontab
restoration checks backup integrity before writing and verifies actual scheduler content and prior absence.
Raw configuration contents and diffs are replaced with hashes in durable audit.

These are compensating operations, not an atomic transaction over arbitrary
systems. A failed primitive without captured reversible state, interrupted worker,
non-reversible command, failed restoration or unavailable independent verifier
requires reconciliation. Completed actions are not silently relabeled successful.
Power loss/SIGKILL does not run PHP exception handlers: the durable intent blocks
replay, preserving evidence for supervised recovery. No destructive cleanup or
generic shell compensation is added.

BACKUP-C0008 retains `skip_if_missing`: a foreign project without Doctor config is
not applicable. A project containing `doctor/DoctorApplication.php` owns the
configuration requirement; a missing config still fails there. An existing but
invalid/unreadable/symbolic config never becomes an absent optional file. This
does not alter other disk, Laravel, Git or security findings.

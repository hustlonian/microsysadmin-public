# Isolated Code Runner

Code Runner submits literal source files to Bash, PHP, Python or Node through an
argument vector. Browser textarea CRLF source is normalized to Unix newlines;
the script is never interpolated into a command string. Existing authentication,
RBAC, CSRF, profile, entitlement and confirmation checks remain. Unknown request
fields, interpreter selection and host working-directory injection are rejected.

The backend requires Linux Bubblewrap and `prlimit`. It fails closed if namespace
setup or a reviewed system interpreter is unavailable. There is no host-shell
fallback. Each run has a nonzero UID, no capabilities, new PID/network/IPC/user
namespaces, further user namespaces disabled, a clean environment, minimal
read-only system binaries/libraries/public timezone data and only its submitted
source file. Application configuration, home directories, host sockets, unrelated
projects and the host temporary directory are not mounted. `/work` and `/tmp` are
private bounded tmpfs mounts; files disappear after the run. Directory changes
inside a script work normally and do not persist into another run.

Execution has a 1–30 second wall timeout, CPU/address-space/process/file-descriptor/
file-size limits and a 128 KiB combined streaming-output budget. Namespace exit
cleans child processes. A synchronous run supports timeout and output-limit
termination; it does not claim asynchronous user cancellation. PHP uses its
standalone interpreter without application extensions/config; Node has a bounded
heap and JIT disabled. Host project dependencies and network access are
intentionally unavailable. Deployment still requires validating the host kernel,
Bubblewrap and selected runtime versions; this is not a VM security guarantee.

The Bash tab offers a multiline script form, input and timeout, and shows stdout,
stderr, exit status, elapsed time and failures. Python and Node use the same form;
PHP retains its output preview. Legacy Code Runner host-PTY start/input are denied.
Stopping an already recorded legacy session remains available; this change does
not terminate or modify installed running sessions.

HTML/CSS/JavaScript preview remains available in an opaque-origin sandbox. Only
the authenticated preview response permits same-origin framing; global app frame
protection stays unchanged. Its two fixed saved CSS/JS resources are embedded,
so the frame needs neither application cookies nor authenticated subresource
fetches. CSP denies network connections, external scripts, form actions and base
URL changes. The frame cannot read its parent application document. Preview files
remain in the existing application-managed storage; scripts cannot mount it.

Run `php doctor/tests/rollback.php`, the existing Doctor/SDK/temporal suites,
`php tests/code-runner.php`, and the existing application/install checks.
`tests/code-runner-service.php` requires a disposable `microsysadmin_test_doctor_*`
or CI database. `node tests/code-runner-browser.cjs /new/evidence/path` additionally
needs a disposable installed fixture with its synthetic owner and Playwright.
No test authorizes production installation, CZ-A1, real customer work or spending.

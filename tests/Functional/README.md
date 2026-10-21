# Isolated HTTP smoke

Run `php scripts/run-http-smoke.php`, `php scripts/run-http-auth.php`, or
`php scripts/run-http-user-lifecycle.php` only with all five `TEST_DB_*` variables described in
`tests/Integration/README.md`. The target must be a new database named
`devwebcamp_port6_test_` followed by eight lowercase hexadecimal digits. Use a dedicated
test-only MySQL account; never supply development credentials or an existing database.

The runner reuses Integration's configuration validation and connection identity checks.
It refuses an existing database, creates only `schema.sql`, one synthetic event, and three
synthetic users, then
drops only the database it created. The PHP server receives explicit `DB_*` values in its
child process and `APP_ENV_MODE=isolated-test`, which skips loading either local `.env`.
The parent process environment is unchanged. PHP's `variables_order=EGPCS` makes the
child's explicit values available in `$_ENV`.

Each run binds a PHP server to `127.0.0.1` on a temporary port and uses a private session
directory and server logs under a unique temporary directory. The harness waits for a
public route to answer before testing. Cleanup stops the server, verifies it no longer
answers, drops the disposable database, removes session files and logs, and removes the
temporary directory. Cleanup is attempted even when an assertion fails.

The original smoke tests read-only public pages, a missing route, an anonymous admin redirect,
and the synthetic read-only events API. The authenticated smoke uses separate cookie jars,
form-encoded POST requests, and temporary session-file identity checks to exercise confirmed
admin and attendee login, invalid and unconfirmed login, role denial, and logout. It checks
session ID regeneration and rejects the previous ID after logout. Redirects are never followed.
The attendee registration redirect is checked but its destination is not requested. Neither
of those runners executes registration, account changes, CRUD, payments, email, or uploads.

The user-lifecycle runner exercises registration, confirmation, recovery, reset, and password
login against one newly registered synthetic user. It checks that the original users and event
remain unchanged and that only the expected synthetic user is added. It reads confirmation and
recovery tokens only from the disposable database and never prints tokens or passwords.
Before any lifecycle request, the isolated server preloads `MailGuard.php`, which defines a
test-only `App\Services\Email` with no SMTP implementation. Every response must carry its
guard header; otherwise the harness aborts. Missing or inactive interception prevents the
runner from reaching the registration and recovery flows. No production mail code is changed.
The lifecycle server discards its own stderr access log because token-bearing URLs would
otherwise be recorded there; the runner reports token-free failure labels instead.
The runner does not follow login into ticketing, payment, or event-registration pages.

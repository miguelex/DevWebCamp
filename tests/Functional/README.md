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

## Administrative CRUD (PORT-9 block 4)

Run `php scripts/run-http-crud.php` with the same disposable `TEST_DB_*` configuration.
This opt-in runner adds only the columns/tables in `crud-schema.sql` to its private database:
`eventos`, `ponentes`, `categorias`, `dias`, and `horas`. It seeds one speaker, one event,
and one row in each catalog. IDs are captured from the database; the existing smoke event
retains ID 1 solely for the prior runner's API contract. No foreign keys or cascades are
defined by this functional schema, and it does **not** describe production DDL. The test
deletes its created event before its created speaker and checks that all rows return to the
initial snapshot.

The speaker controller's `../public/img/speakers/` path is relative to its process CWD.
For this runner only, a generated router changes CWD to `<temporary>/runtime` before
loading the real front controller. The relative destination then resolves to
`<temporary>/public/img/speakers`; PHP's upload temp path, sessions, router, and logs also
remain under that private temporary tree. The test-only router loads `ImageGuard.php` only for this
server. It rejects any input other than the exact synthetic 1×1 PNG, checks the controller's
800×800 PNG/WebP calls, and refuses to save anywhere except the isolated destination.
It writes deterministic valid PNG/WebP fixtures because the host PHP runtime lacks GD and
Imagick. This tests HTTP upload wiring, DB writes, path isolation, and legacy file retention;
it does **not** test Intervention Image's real resize/encoding or browser image rendering.
The runner verifies both files after create/edit/delete. Legacy delete removes only the DB
row; harness cleanup removes the two temporary files in `finally`.
The legacy `ActiveRecord::crear()` prepends a space to the first bound value; this runner
asserts that observed INSERT behavior and verifies UPDATE removes it, without changing
production persistence code.

The event POST supplies complete backend fields directly. It does not simulate the hidden
day/hour/speaker inputs populated by `src/js/horas.js` and `src/js/ponentes.js`, nor does it
validate frontend interactions, responsive presentation, production uploads, ticketing,
conference registration, checkout, PayPal, or any external call.

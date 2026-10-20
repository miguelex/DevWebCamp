# Isolated HTTP smoke

Run `php scripts/run-http-smoke.php` only with all five `TEST_DB_*` variables described in
`tests/Integration/README.md`. The target must be a new database named
`devwebcamp_port6_test_` followed by eight lowercase hexadecimal digits. Use a dedicated
test-only MySQL account; never supply development credentials or an existing database.

The runner reuses Integration's configuration validation and connection identity checks.
It refuses an existing database, creates only `schema.sql` and one synthetic event, then
drops only the database it created. The PHP server receives explicit `DB_*` values in its
child process and `APP_ENV_MODE=isolated-test`, which skips loading either local `.env`.
The parent process environment is unchanged. PHP's `variables_order=EGPCS` makes the
child's explicit values available in `$_ENV`.

Each run binds a PHP server to `127.0.0.1` on a temporary port and uses a private session
directory and server logs under a unique temporary directory. The harness waits for a
public route to answer before testing. Cleanup stops the server, verifies it no longer
answers, drops the disposable database, removes session files and logs, and removes the
temporary directory. Cleanup is attempted even when an assertion fails.

This smoke tests only read-only public pages, a missing route, an anonymous admin redirect,
and the synthetic read-only events API. It does not run legacy HTTP scripts or exercise
login, account changes, CRUD, registration, payments, email, or uploads.

# Disposable database integration suite

`composer test` runs Unit only. Integration is opt-in and never loads the development `.env`.

## Run

Set all five variables explicitly, then run `composer test:integration`:

| Variable | Required value |
| --- | --- |
| `TEST_DB_HOST` | `127.0.0.1` or `localhost` (local socket) |
| `TEST_DB_PORT` | MySQL port, such as `3306` |
| `TEST_DB_NAME` | `devwebcamp_port6_test_` plus eight lowercase hex digits |
| `TEST_DB_USER` | Account allowed to create/drop only disposable test databases |
| `TEST_DB_PASS` | Password; set explicitly even when empty |

On the current local machine, `localhost` connects through the MySQL socket; `127.0.0.1` TCP did not connect. This is an environment observation, not a requirement for other machines.

The runner validates these values before connecting. It connects to `information_schema`, checks `SELECT DATABASE()`, and refuses an existing target database rather than deleting it. It then creates the named database, verifies its identity, loads only `schema.sql`, runs PHPUnit's Integration suite, checks for residual rows, and drops the database it created. Cleanup also runs after test failures. A stale database from an interrupted run is **not** overwritten; remove it manually only after verifying its identity and contents.

Use a dedicated MySQL account with narrowly scoped privileges. Do not point these variables at development credentials or `devwebcamp`. No application boot, SMTP, uploads, or HTTP server is needed.

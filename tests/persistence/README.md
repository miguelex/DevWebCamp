# PORT-3 block 1: legacy persistence characterization

These scripts record the behavior **before** switching the application from `mysqli` to PDO. They use the project's existing Composer autoload and PHP CLI; no test framework or new dependency is required.

## Quick path

From the repository root, with the original application running at `http://localhost:3000` and the local development database available:

```bash
php tests/persistence/legacy_contract.php
php tests/persistence/legacy_baseline.php --verify http://localhost:3000
```

`legacy_contract.php` uses a fake connection. It records SQL construction, hydration, return shapes, alerts, attribute synchronization and CRUD calls **without executing database writes**.

`legacy_baseline.php` runs only `SELECT` queries and local HTTP `GET` requests. It compares the result with `legacy_baseline.json`. To deliberately refresh the baseline after reviewing a change:

```bash
php tests/persistence/legacy_baseline.php --record http://localhost:3000
```

The script accepts only `localhost` or `127.0.0.1` as its HTTP target. It selects the root `.env` if present and otherwise `includes/.env`, matching the current bootstrap. It stores counts, SHA-256 fingerprints of non-sensitive fixture columns, model-result fingerprints and response fingerprints. It does **not** store credentials, password hashes, emails, tokens, full database rows, or response bodies.

## Intentional legacy observations

- `get()` builds invalid SQL (`LIMIT` before `ORDER BY`); the fake checks the SQL and the read-only baseline records the current `mysqli_sql_exception` outcome.
- `crear()` prefixes the first inserted value with a space, does not set the object's ID and returns an array even on failure; therefore a failed insert's array is still truthy.
- The public API endpoints return JSON bodies with a `text/html` Content-Type. The baseline checks JSON validity, field types and body hashes rather than inferring format from the header.
- The homepage uses random `data-aos` animation values. Only those attributes are normalized before its HTML hash is computed.
- `/admin/dashboard` is anonymously accessible, `/404` responds with HTTP 200, and unknown paths redirect to `/404`. These are existing behaviors, not assertions of desired future policy.
- Some admin routes issue redirects but still render an admin layout in the same response; the baseline records both the redirect and body.
- PHP 8.2 deprecations from `${var}` interpolation and dynamic model properties are pre-existing. The characterization scripts suppress `E_DEPRECATED` output but do not modify production behavior.

## Coverage boundary

Positive authenticated HTTP flows are not repeated by these scripts because the development fixture credentials are not stored in the repository. No payment, email, upload or write-based CRUD endpoint is invoked. Real database insert/update/delete behavior needs a disposable database in a later PORT-3 block; a rollback on the current MySQL database would not necessarily restore `AUTO_INCREMENT` counters.

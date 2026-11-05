# Separate application and public roots

Local development needs no new configuration. The front controller defaults to
the repository root for the application and `<repository>/public` for public files.

## Split-directory deployments

Provide both variables to the PHP process **before** `public/index.php` runs:

```text
APP_ROOT=/srv/example/private-app
APP_PUBLIC_ROOT=/srv/example/site-public
```

These are illustrative filesystem paths, not URLs. Configure them through the
hosting PHP environment or server configuration, never through request parameters.
The hosting account must allow PHP to read the private directory (including any
`open_basedir` restrictions).

| Setting | Default when absent | Relative override anchor |
| --- | --- | --- |
| `APP_ROOT` | Parent of the directory containing `index.php` | Directory containing `index.php` |
| `APP_PUBLIC_ROOT` | `<resolved APP_ROOT>/public` | Resolved application root |

Both roots must exist and be directories. Explicit empty, missing or non-directory
values fail before application boot with HTTP 500 and a variable-specific message
that does not disclose the configured path. The application root must also contain
readable `vendor/autoload.php`, `config/routes.php` and `src/Core/App.php` files.
Invalid explicit configuration never silently falls back to the local layout.
Absolute paths use the native platform: `/path` on Unix, or drive-qualified
`C:\path`, `C:/path` and UNC `\\server\share\path` on Windows. Unix rejects
Windows-style roots instead of interpreting them relative to the CWD. Windows
rejects drive-relative (`C:path`) and rooted-without-drive (`\path`) values.
Ordinary relative overrides use the anchors above on either platform.

## Private files and uploads

- Keep `.env`, `vendor`, `src`, `config` and `views` under the private application
  root, outside the web server's public root.
- Existing `App::boot()` loads `<APP_ROOT>/.env`; its legacy `includes/.env`
  fallback is unchanged. Root overrides cannot come from that `.env`, because
  locating the application and Composer autoload happens before Dotenv can run.
- `BASE_PATH` remains the private application root. `PUBLIC_PATH` is the resolved
  public root. Neither resolution depends on the process working directory.
- Speaker uploads use `PUBLIC_PATH/img/speakers`; that directory must be writable
  by PHP. Image names, PNG/WebP processing and public `/img/speakers/...` URLs do
  not change. Public assets remain under `build` and `img` in the public root.
- Routing, `PATH_INFO`, `.htaccess`, views and helpers retain their existing
  contracts. No global `chdir()` or Hostinger-specific paths are required.

## Test isolation

The functional harness removes inherited root overrides. CRUD requests receive an
exclusive temporary `APP_PUBLIC_ROOT`, alongside the existing isolated database,
sessions, upload temporary directory and fail-closed image double. Its generated
router no longer changes the CWD to resolve uploads. Files and directories are
removed by the existing cleanup path. The public HTTP harness uses an explicit
environment allowlist, so it does not inherit deployment root overrides either.

Bootstrap tests cover the local defaults, split roots with the real private Dotenv
loading, deterministic relative overrides, invalid roots and unchanged CWD/PATH_INFO
without opening database or network connections. Speaker unit tests use temporary
public roots and verify both image-save destinations from an unrelated CWD.

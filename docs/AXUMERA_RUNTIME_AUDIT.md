# Axumera runtime audit

Audited 2026-08-03 from the complete application source tree, supplied runtime trees, SQL schema/migrations, and XAMPP reference configurations.

## Runtime findings

| Component | Actual supplied version | Required production capability |
|---|---:|---|
| PHP | 8.2.12, ZTS x64 | Apache module, PDO MySQL, mbstring, OpenSSL, fileinfo, sessions, JSON, filesystem uploads |
| Apache | 2.4.58 Win64 | built-in `mpm_winnt`; authz, mime, dir, alias, rewrite, headers, log_config, PHP module; `.htaccess` enabled |
| MariaDB | 10.4.32 Win64 | InnoDB, TCP loopback, utf8mb4 / utf8mb4_general_ci |

PHP additionally relies on built-in JSON, random bytes, session, PDO and standard file APIs. `mysqli` is not used by the current application. `RedisCache.php` references the PHP Redis extension but has no callers; Redis is an optional, inactive implementation and is not bundled. OpenSSL is necessary for signed license verification. `shell_exec` is used only to obtain the Windows machine UUID for that licensing flow.

The supplied PHP and MariaDB `ini` files and Apache `httpd.conf` contain `C:\xampp` paths. They are reference material only and are not used by the private runtime.

## Application findings

Environment variables: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, `APP_KEY`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, session/login controls, HTTPS flag, autosave/grace values, and integrity thresholds. The runtime initializer generates a production `.env`; the source development `.env` is never copied.

Writable paths: `storage/logs`, `storage/cache`, `storage/sessions`, and `storage/license.lic` after activation. No background worker is called by the application. External CDN use is limited to `cdn.jsdelivr.net` in the existing CSP; MathJax is also locally present. The product needs a local browser-to-server connection; LAN publication is not enabled by the initial loopback configuration.

The installer creates the database, imports `database/schema.sql`, creates an administrator, and creates `storage/installed.lock`. The runtime initializer preserves that schema without a second schema and creates a dedicated local `axumera_app` account rather than shipping XAMPP's root account. The historical migration files are retained but are not automatically applied to a clean database: they reference `admins` and `users`, whereas the current schema uses `admin_users` and contains some migration columns already. Applying them to a clean install produces duplicate-column and foreign-key errors. This is an application migration-lineage defect requiring a separate, data-preserving migration reconciliation before an upgrade path can be certified.

## Security handling

Never ship source `.env`, `storage/license.lic`, installed locks, runtime logs, cache/session contents, development database backup data, or any private signing key. The public license key is required and retained. `schema.sql` and migrations are packaged because initialization needs them. Do not record passwords in logs or documentation.

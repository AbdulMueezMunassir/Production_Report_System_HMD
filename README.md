"# Production Report System HMD

## Requirements

- PHP 8.5
- PHP PDO with the MySQL driver (`pdo_mysql`)

## Hosting deployment

1. Select PHP 8.5 for the site in the hosting control panel and make sure the `pdo_mysql` extension is enabled.
2. Create a MySQL database and database user in the hosting control panel, then grant that user access to the database.
3. Open the database in phpMyAdmin and import `install.sql`. The SQL creates the application tables but does not create or select a database, so it works with hosting providers that prefix database names.
4. Configure database access using hosting environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`, or create `config/database.local.php` based on `config/database.local.example.php`. The private file is intentionally excluded from Git and must be created on the server with the host's actual credentials.
5. Upload the PHP files and the complete `assets` directory. Keep `.htaccess` if the host supports Apache per-directory configuration.
6. Change the default administrator password immediately after the first login.

`sql/database.sql` and `sql/seed_data.sql` belong to an older, incompatible schema; do not import them. `sql/migrations/20261015_add_direct_staffing_fields.sql` is only for an existing database that does not already have the `direct_on_roll` and `direct_present` columns; verify the columns first and do not run it after a fresh install.

## Sending a deployment ZIP

Include `install.sql`, the application PHP files, `config/database.php`, `config/database.local.example.php`, `includes`, and `assets`. Omit `sql/database.sql` and `sql/seed_data.sql`; the migration file is only needed when upgrading an existing database. Do not include `config/database.local.php` if one exists, any database export containing real production data, or the `.git` folder. The `.gitignore` entry only protects the private config from Git; remove it manually from any ZIP if it exists.

The live host must be configured with its own database credentials and database schema. A successful local XAMPP test cannot confirm that an inaccessible hosting account is working."

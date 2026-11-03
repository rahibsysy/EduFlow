# Database migrations

This directory is the only authoritative history of database structure and
shared reference data. Do not place executable migrations in `active/` or in
another directory: the runner reads only `backend/storage/migrations/*.sql`.

## Apply migrations

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1
```

For a brand-new, empty database, bootstrap the canonical baseline once, then
run migrations:

```bash
cd backend
php bin/migrate.php --bootstrap
php bin/migrate.php --migrate
```

If bootstrap fails, do not rerun it against the partially-created database.
The runner intentionally refuses non-empty unmarked databases. Keep the error
output, drop only the dedicated empty target database, recreate it, and rerun
`--bootstrap`. Never use this recovery procedure against a database containing
school data; restore that database from a verified backup instead.

For a pre-existing database created before the baseline ledger, first take and
verify a backup, inspect `--status`, then run `php bin/migrate.php
--adopt-baseline`. Adoption does not execute historical DDL; it records the
declared baseline after checking critical schema signatures. Run `--migrate`
afterwards to apply only migrations newer than the baseline.

Check status without modifying the database:

```powershell
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1 -Status
```

## Creating a migration

Use an ordered filename:

```text
YYYY_MM_DD_NNN_short_description.sql
```

Example:

```text
2026_09_03_001_create_academic_years.sql
```

Rules:

1. Never edit or rename a migration after it has been pushed.
2. Create a new migration for every later correction.
3. Include schema changes and deterministic reference data only.
4. Never commit real students, parents, payments, credentials, or database dumps.
5. Test migrations on a backup before merging.
6. Commit the migration with the backend code that depends on it.

## Required verification

Every migration must be tested on an empty MySQL database and on a database
already marked with the current baseline. Financial migrations must also test
concurrency and cross-school foreign-key rejection. The reproducible test
entry point is `tests/integration/financial_integrity.sh`.

## Collaboration workflow

```powershell
git pull
powershell -ExecutionPolicy Bypass -File .\migrate-database.ps1
```

All collaborators then have the same schema version. Their private development
records remain local.

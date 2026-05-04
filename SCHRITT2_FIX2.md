# Schritt 2 Fix #2 — Audit-UUID

## Problem

```
SQLSTATE[23502]: Not null violation: null value in column "id" of relation "audits"
```

Das Default-`OwenIt\Auditing\Models\Audit` erwartet bigIncrements als
PK. Pflegedex-`audits`-Tabelle hat aber UUID. Beim Insert sendet das
Default-Model kein `id` mit -> Postgres NOT-NULL-Constraint blockt.

## Fix

1. **Neu:** `app/Models/Audit.php` extendet `BaseAudit` und nutzt unser
   `HasUuidV7`-Trait. Damit bekommt jeder Audit-Insert eine UUIDv7.
2. **`config/audit.php`:** `'implementation'` zeigt jetzt auf
   `App\Models\Audit::class`.

## Anwendung

ZIP entpacken (3 Files, 1 NEU):
- `app/Models/Audit.php` (NEU)
- `config/audit.php` (geaendert: implementation)
- `database/seeders/DatabaseSeeder.php` (Backup falls letzter Fix noch nicht drin)
- `tests/Feature/CareReportVersioningTest.php` (Backup falls letzter Fix noch nicht drin)

```
docker compose exec app php artisan test
```

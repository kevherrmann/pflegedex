# Schritt 1: UUID-Migration + Pseudonym (v3)

## Wichtig vorher (Cleanup von v1/v2)

In v1 hatte ich Factories gegen ein angenommenes Schema geschrieben,
nicht gegen das echte. v2 enthielt nur die geaenderten Files - **nicht**
LocationFactory/UserFactory/CareReportFactory. Dadurch sind die kaputten
v1-Versionen lokal noch aktiv.

**v3 enthaelt jetzt alle 19 betroffenen Files**, also einfach komplett
ueber das Repo legen und gut ist.

## Anwendung

1. Diese ZIP komplett ueber das Repo entpacken (alle Pfade ueberschreiben).
2. Lokale DB wegwerfen + neu starten:
   ```
   docker compose down -v
   docker compose up --build
   ```
   (`migrate:fresh --seed` laeuft automatisch via PFLEGEDEX_AUTO_MIGRATE=true.)
3. Tests:
   ```
   docker compose exec app php artisan test
   ```
4. Login mit Original-Demo-Daten:
   - admin@pflegedex.local / password
   - pdl@pflegedex.local / password
   - carl@pflegedex.local / password

## Was war kaputt nach v2

- **`column "slug" does not exist`** in 30+ Tests: LocationFactory v1
  hatte `'slug' => ...` drin. Echtes Schema hat keinen `slug`, sondern
  `name` (unique) + `short_name`. v3 enthaelt die Original-LocationFactory.
- **419-Fehler** in Auth/Profile-Tests: User-Model v1 hatte
  `use Laravel\Sanctum\HasApiTokens;` gehackt - Sanctum ist im Repo aber
  nicht installiert. Class-Not-Found-Exception bricht Session-Aufbau ->
  CSRF-Validierung schlaegt fehl -> 419. v2/v3 ohne Sanctum.

## Inhalt v3 (19 Files)

### Migrationen (UUID-PKs/FKs, alle Original-Felder behalten)

- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000001_create_cache_table.php` (Original)
- `0001_01_01_000002_create_jobs_table.php` (Original)
- `2026_04_30_212601_create_permission_tables.php` (model_morph_key auf UUID)
- `2026_04_30_212602_create_locations_table.php` (mit short_name/active)
- `2026_04_30_212625_add_location_id_to_users_table.php`
- `2026_05_01_112400_create_residents_table.php` (mit pseudonym, birth_date, care_level, active)
- `2026_05_01_123000_create_location_user_table.php`
- `2026_05_01_180000_create_care_reports_table.php` (mit occurred_at, body, category)

### Models

- `app/Support/Concerns/HasUuidV7.php` (NEU)
- `app/Models/User.php` (HasUuidV7, location_id-Cast raus, canAccessLocation auf string)
- `app/Models/Location.php`
- `app/Models/Resident.php` (+ pseudonym + generatePseudonym() + full_name + scopes)
- `app/Models/CareReport.php`

### Controller

- `app/Http/Controllers/ResidentController.php` (UUID-Validation + Pseudonym-Aufruf)
- `app/Http/Controllers/CareReportController.php` (UUID-Validation)
- `app/Http/Controllers/StaffController.php` (intval -> strval)

### Seeder & Factories (alle 4!)

- `database/seeders/DatabaseSeeder.php` (Original + pseudonym-Felder)
- `database/factories/UserFactory.php` (Original-Stand)
- `database/factories/LocationFactory.php` (Original-Stand, **kein slug**)
- `database/factories/CareReportFactory.php` (Original-Stand)
- `database/factories/ResidentFactory.php` (+ pseudonym-Feld)

### Frontend

- `resources/js/types/index.d.ts` (User.id: string)
- `resources/js/Pages/**/*.tsx` (id: number -> string, locationIds: number[] -> string[])

### Tests

- `tests/Feature/ResidentPseudonymTest.php` (NEU)

## Was Schritt 1 NICHT macht (Folgeschritte)

- Mailpit, owen-it/laravel-auditing, Category-Enum (Schritt 2)
- report_versions Append-Only (Schritt 3)
- Signieren (Schritt 4)
- SIS (Schritt 5)
- Audit-Log-View (Schritt 6)

## Pseudonym im UI

Feld + Generator vorhanden, aber UI zeigt das Pseudonym noch nicht
(macht ein eigener kleiner Folge-Schritt: Bewohner-Index, Edit-Header,
CareReports-Liste).

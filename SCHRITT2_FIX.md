# Schritt 2 — Hotfix nach Test-Run

## Probleme

1. **3 Audit-Tests rot:** Mit `'console' => false` werden in Tests **gar keine** Audits
   geschrieben (PHPUnit-Lauf wird auch als Console gewertet -
   Issue owen-it/laravel-auditing#520).

2. **1 Versioning-Test rot:** `CareReportVersioningTest > creates initial report version`
   sendet `'category' => 'pflege'` - das ist nach Enum-Haertung kein gueltiger Wert mehr,
   Validation laeuft auf 422, Bericht wird nicht angelegt, `firstOrFail()` wirft.

## Fix

### 1. config/audit.php
- `'console' => true` (war false)
- Begruendung als Kommentar im File

### 2. database/seeders/DatabaseSeeder.php
- `run()` wrappt alles in `Location::withoutAuditing(fn () => Resident::withoutAuditing(fn () => User::withoutAuditing(fn () => CareReport::withoutAuditing(fn () => $this->seedAll()))))`
- Demo-Seeder produziert weiter **keine** Audit-Eintraege (Test "schreibt KEINE
  audit-Eintraege beim Seeder-Lauf" bleibt damit gruen)

### 3. tests/Feature/CareReportVersioningTest.php
- `'category' => 'pflege'` -> `'category' => 'Grundpflege'`

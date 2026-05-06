# Schritt 5: SIS-Modul (Strukturierte Informationssammlung)

## Was Schritt 5 macht

- Vollständige SIS Phase 1: Erstellen + Anzeigen + Editieren + Risikomatrix + Evaluation-Fristen
- Domain-Modell lt. Beikirch/Roes (BMG-Konzept v2.0/2017): 6 Themenfelder + 6-fache Risikomatrix + 1 Eingangsfrage
- Versionierung der SIS analog zu CareReport (sis_versions Append-Only)
- Auth: PDL kann anlegen/editieren/evaluieren, Pflegekraft sieht read-only, Admin/Putzkraft/Hausmeister 403
- Fristen lt. Immerso: started_at = Aufnahmetag, completed_at <= 14 Tage, Evaluation alle 8 Wochen

## Anwendung

1. ZIP über das Repo legen (gleiche Pfade).
2. Container neustarten (Migrations laufen automatisch via PFLEGEDEX_AUTO_MIGRATE):
   ```
   docker compose down
   docker compose up -d
   ```
3. Tests:
   ```
   docker compose exec app php artisan test
   ```
4. Demo-SIS (Erika Mustermann) ist nach Seeder-Lauf vorhanden:
   ```
   http://localhost:8080/residents/<erika-uuid>/sis
   ```

## Was sich ändert

### Migrationen (4 NEU)

```
database/migrations/2026_05_05_080000_create_sis_assessments_table.php   NEU
database/migrations/2026_05_05_080100_create_sis_topic_entries_table.php NEU
database/migrations/2026_05_05_080200_create_sis_risks_table.php         NEU
database/migrations/2026_05_05_080300_create_sis_versions_table.php      NEU
```

- `sis_assessments`: 1:1 zu Resident (resident_id unique). Header mit Eingangsfrage,
  Datums-Feldern (started_at, completed_at, evaluated_at, next_evaluation_due),
  created_by/updated_by FKs.
- `sis_topic_entries`: 6 Themenfelder pro SIS, unique(sis_id, topic_number).
- `sis_risks`: 6 Risiken pro SIS (dekubitus/sturz/inkontinenz/schmerz/ernaehrung/sonstiges),
  unique(sis_id, risk_kind), Felder is_at_risk + needs_further_assessment + notes.
- `sis_versions`: append-only JSON-Snapshots, analog zu report_versions.

### Enums (2 NEU)

- `app/Enums/SisTopic.php` - int-Enum 1..6 mit deutscher Bezeichnung pro Themenfeld
- `app/Enums/SisRiskKind.php` - string-Enum mit Werten dekubitus/sturz/inkontinenz/schmerz/ernaehrung/sonstiges

### Models (4 NEU + 1 geändert)

- `app/Models/Sis.php` (NEU, Tabelle sis_assessments, Auditable)
  - `appendVersion(reason, user)` schreibt JSON-Snapshot des aktuellen Zustands
  - `markEvaluated(user)` setzt evaluated_at=heute + next_evaluation_due=+8W + Snapshot
  - `isOverdue()` und `scopeOverdue()` für Fristen-Check
- `app/Models/SisTopicEntry.php` (NEU)
- `app/Models/SisRisk.php` (NEU)
- `app/Models/SisVersion.php` (NEU, append-only, $timestamps=false)
- `app/Models/Resident.php`: HasOne `sis()` ergänzt

### Controller (1 NEU)

- `app/Http/Controllers/SisController.php` mit show/create/store/edit/update/evaluate
- Auth-Methoden:
  - `authorizeRead()`: PDL+Pflegekraft, Location-Check
  - `authorizeWrite()`: nur PDL, Location-Check
- syncTopics() und syncRisks() initialisieren immer alle 6 Einträge (updateOrCreate)
- Bei store: Snapshot via appendVersion('created') NACH Topic/Risk-Insert
- Bei update: Snapshot via appendVersion('updated') VOR den Schreibvorgängen (alter Stand)
- Wenn completed_at zum ersten Mal gesetzt wird und next_evaluation_due noch null ist:
  automatisch +8 Wochen setzen

### Routes (6 NEU)

```
GET    /residents/{resident}/sis              residents.sis.show
GET    /residents/{resident}/sis/create       residents.sis.create
POST   /residents/{resident}/sis              residents.sis.store
GET    /residents/{resident}/sis/edit         residents.sis.edit
PATCH  /residents/{resident}/sis              residents.sis.update
POST   /residents/{resident}/sis/evaluate     residents.sis.evaluate
```

### Factories (3 NEU)

- `database/factories/SisFactory.php` mit States `withTopicsAndRisks()`, `completed()`, `overdue()`
- `database/factories/SisTopicEntryFactory.php`
- `database/factories/SisRiskFactory.php`

### React Pages (3 NEU)

- `resources/js/Pages/Sis/Show.tsx` - Read-only Anzeige mit Themenfeldern, Risikomatrix,
  Fristen-Anzeige (überfällig in rot), Bearbeiten/Evaluieren-Buttons (nur PDL)
- `resources/js/Pages/Sis/Create.tsx` - Anlege-Formular
- `resources/js/Pages/Sis/Edit.tsx` - Bearbeiten + completed_at setzen

### Seeder

- `database/seeders/DatabaseSeeder.php`:
  - withoutAuditing-Wrapper um `Sis::class` ergänzt
  - Neue Methode `seedDemoSis()`: legt für Erika Mustermann eine vollständige Demo-SIS
    mit realistischen Themenfeld-Texten und Risiko-Flags an (Sturzrisiko + Inkontinenz
    nachts gesetzt, Rest unauffällig).

### Tests (2 NEU)

- `tests/Feature/SisAccessTest.php` (6 Tests):
  - PDL kann anlegen
  - Pflegekraft kann nicht anlegen, kann aber lesen
  - Pflegekraft kann nicht editieren
  - Admin/Putzkraft/Hausmeister 403
  - PDL fremder Wohnbereich 403

- `tests/Feature/SisDomainTest.php` (7 Tests):
  - Beim Anlegen alle 6 Themenfelder + 6 Risiken
  - Versions-Snapshot beim Anlegen
  - Versions-Snapshot beim Update enthält alten Stand
  - next_evaluation_due automatisch bei completed_at
  - Evaluation setzt evaluated_at + next_evaluation_due (+8W)
  - isOverdue() erkennt überfällige SIS
  - Zweite SIS pro Bewohner wird verhindert (unique constraint + Controller-Redirect)

## Was Schritt 5 NICHT macht (Folgeschritte)

- Audit-Log-View (Inertia-Seite für PDL/Admin) - Schritt 6
- SIS-überfällig-Indikator im Bewohner-Index und Dashboard - kann zusätzlich kommen
- Detail-Anzeige der SIS-Versionen (Diff-Viewer) - Schritt 7?

## Designentscheidungen

- **DB-Schema-Naming**: Tabellenname `sis_assessments` (nicht `sis`), weil "sis" als
  Tabellenname zu kryptisch ist. Model heißt `Sis` für gute Lesbarkeit.
- **Risiko-Enum als String, nicht Boolean-Spalten**: Flexibler, falls später weitere
  Risikoarten dazukommen. Macht die Risikomatrix-Anzeige im Frontend einfacher.
- **JSON-Snapshot statt strukturiertes Versions-Schema**: analog zu report_versions
  konsistent, simpler zu schreiben/lesen, ausreichend für Audit-Trail.
- **Kein Soft-Delete**: SIS gehört zum Bewohner. Wenn der Bewohner gelöscht wird,
  cascade-deleten Header + Topics + Risks. Versionen werden mit-cascadiert (sind eh
  read-only Archiv).
- **opening_question optional**: Nicht jede SIS hat sofort einen Eingangsfrage-Text;
  kann später ergänzt werden.

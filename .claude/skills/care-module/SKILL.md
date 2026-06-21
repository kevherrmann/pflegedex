---
name: care-module
description: >
  Schablone zum Hinzufügen eines neuen Fachmoduls in Pflegedex (Laravel 11 + Inertia/React/TS).
  Verwenden, wenn ein neues Pflege-/Dienstplan-Domänenmodul angelegt wird (z.B. Vitalwerte,
  Durchführungsnachweis, Assessment, Wunddoku, Medikation). Folgt den bewährten Konventionen
  des Codebase: UUIDv7-PKs, Enums, Audit, Versionierung/Signatur, Standort-Scope, Pest-Tests, PDF.
---

# Neues Fachmodul in Pflegedex anlegen

Ziel: konsistent zum bestehenden Code (SIS, CarePlan, CareReport, Roster). NIE Fremdcode aus dem Netz
in diese Gesundheitsdaten-App ziehen. Pflegedaten verlassen den Server nicht; KI-Ausgaben sind nur Entwürfe.

## Checkliste pro Modul
1. **Migration** `database/migrations/` — `uuid('id')->primary()`, FKs auf `residents`/`locations`/`users`
   mit `restrictOnDelete` (kein Datenverlust), `timestamps()`. Additive Migrationen, alte nie editieren.
2. **Model** `app/Models/` — `use HasUuids;`, enges `$fillable`, Enum-/`encrypted`-Casts. Gesundheits-Freitext
   IMMER `'feld' => 'encrypted'`. Statusfelder (`signed`, `completed_at`) nur per `forceFill()` serverseitig.
   Auditing: `use OwenIt\Auditing\Auditable;` + `implements Auditable` wie bei `Resident`/`CareReport`.
3. **Enum** `app/Enums/` — `enum X: string` mit `label(): string` (deutsch) und ggf. `values()`/`numbers()`.
4. **Policy** `app/Policies/` ODER Controller-Guard nach Muster `SisController::authorizeWrite`:
   IMMER `abort_unless($user->canAccessLocation($resident->location_id), 403)` + Rollencheck.
   Object-Level prüfen (`abort_unless($child->parent_id === $parent->id, 404)`).
5. **Controller** `app/Http/Controllers/` — dünn halten; Geschäftslogik in `app/Services/`.
   Standort-Scope über `accessibleLocations()`/`canAccessLocation()`. Validierung → FormRequest (Zielbild).
6. **Routen** `routes/web.php` — innerhalb `auth`-Middleware-Gruppe; resource-/explizite Routen.
7. **Versionierung** falls dokumentenartig (wie `ReportVersion`/`SisVersion`): append-only Snapshot je Änderung,
   `updating`/`deleting` auf Version-Model blocken. Signierte/abgeschlossene Datensätze nicht mehr editierbar.
8. **Inertia-Seiten** `resources/js/Pages/<Modul>/` (Index/Show/Create/Edit) — TS `strict`, kein `any`.
   Komponenten klein halten (KEINE God-Component wie `Rosters/Show.tsx`); Wiederverwendbares nach `Components/`.
9. **PDF** falls nötig: dompdf-Muster aus `SisPdfController`/`CarePlanPdfController`.
10. **Tests** `tests/Feature/` (Pest) — Pflicht: Zugriffsschutz (fremder Wohnbereich → 403), CRUD,
    Validierung, Versionierung/Immutabilität. Echte Logik testen, nicht mocken (vgl. Roster-Tests).

## Verifizieren & Deployen
- Lokal/Server-Container: `pest` grün, `npm run build` (führt `tsc` + vite) ohne Fehler,
  `tsc --noUnusedLocals` sauber (kein toter Code — in Profi-Software nicht akzeptiert).
- Deploy (siehe Memory `pflegedex-deployment`): geänderte Dateien hochladen →
  bei Frontend `npm run build` im node:20-Container → `chown -R 1000:1000 /root/pflegedex` →
  `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force` +
  `config:cache route:cache view:cache` → Smoke-Test über die ngrok-URL.

## Referenz-Implementierungen im Code
- Strukturmodell-Domäne: `app/Models/Sis.php`, `app/Http/Controllers/SisController.php` (`authorizeWrite`).
- Versionierung/Signatur: `app/Models/{ReportVersion,CareReport}.php`, `CareReportController::update` (403 bei signiert).
- Dienstplan-Architektur (Strategy-Pattern): `app/Services/Rosters/Planning/`.
- Standort-Scope-Muster: `app/Http/Controllers/StaffController.php` (index), `app/Models/User.php` (`accessibleLocations`).

# Schritt 6: Audit-Log-View + SIS-Navigation

## Was Schritt 6 macht

- **Audit-Log-View** als eigene Inertia-Page unter `/audit` mit Filter (Benutzer,
  Modell-Typ, Ereignis, Zeitraum) und Pagination (25 pro Seite)
- **Hauptmenü-Eintrag** "Audit-Log" für PDL und Pflegekraft
- **SIS-Links im Bewohner-Index und im Bewohner-Edit** (war in Schritt 5 vergessen)
- **Mini-Bugfix in `SisController::create()`**: korrekter Laravel-Redirect statt
  Inertia-Render mit fehlenden Props, falls schon eine SIS existiert

## Anwendung

```bash
docker compose down
docker compose up -d
docker compose exec app php artisan test
```

## Was sich ändert

### Backend

**Neu**:
- `app/Http/Controllers/AuditController.php` — `index`-Action mit Filter+Pagination
- `tests/Feature/AuditLogTest.php` — 8 Tests (Auth-Matrix, Sortierung, Filter, Paging)

**Geändert**:
- `app/Http/Middleware/HandleInertiaRequests.php` — neue Permission `viewAuditLog`
  (true für PDL+Pflegekraft)
- `app/Http/Controllers/SisController.php` — `create()` benutzt jetzt
  `redirect()->route(...)` statt `Inertia::render('Sis/Edit', ['redirect' => ...])`,
  wenn die SIS schon existiert
- `routes/web.php` — neue Route `GET /audit` (Name: `audit.index`)

### Auth

- **PDL** und **Pflegekraft** sehen den Audit-Log (read-only, da der Log
  konzeptuell read-only ist)
- **Admin / Putzkraft / Hausmeister** bekommen 403

### Filter

Die Audit-Page unterstützt diese Query-Parameter:
- `user_id` — UUID eines Benutzers, filtert auf Aktionen dieses Users
- `model` — einer von: `resident`, `care_report`, `sis`, `location`, `user`
- `event` — einer von: `created`, `updated`, `deleted`, `restored`
- `from`, `to` — ISO-Datum (YYYY-MM-DD), inkl. start/end of day
- `page` — Pagination

Filter werden im Controller intern auf die `audits.auditable_type`-Spalte gemappt
(`resident` → `App\Models\Resident::class`).

### Frontend

**Neu**:
- `resources/js/Pages/Audit/Index.tsx` — Filter-Sidebar, Audit-Liste mit
  ausklappbaren Detail-Diffs (alt → neu), Pagination-Buttons
- `resources/js/Layouts/AuthenticatedLayout.tsx` — neuer NavLink "Audit-Log"
  (sowohl Desktop als auch Mobile-Nav)
- `resources/js/types/index.d.ts` — `viewAuditLog: boolean` ergänzt

**Geändert**:
- `resources/js/Pages/Residents/Index.tsx`:
  - Action-Spalte zeigt jetzt **immer** "SIS"-Link (für PDL+Pflegekraft)
  - Zusätzlich "Bearbeiten" für PDL
  - Tabellen-Header: "Aktionen" für PDL, "Dokumentation" für Pflegekraft
- `resources/js/Pages/Residents/Edit.tsx` — Footer hat jetzt Link "SIS öffnen"

## Designentscheidungen

- **Filter via Query-String** statt Form-State im Frontend, damit Filter-URLs
  bookmarkbar/teilbar sind
- **Modell-Filter mit Schlüssel-Mapping** (`resident`, `care_report`, …)
  statt direkt FQCN in der URL — saubere URLs, kein FQCN-Leak
- **Detail-Diff lazy expanded** — bei vielen Audits würde sonst die Page
  unhandlich groß
- **Audit-Log read-only**: kein Löschen, kein Bearbeiten in der UI. Audits
  sollen unveränderlich sein, das ist der Sinn der Sache

## Was Schritt 6 NICHT macht (Folgeschritte)

- Audit-Export als CSV/Excel
- Volltextsuche in `old_values`/`new_values`
- Detail-Page pro Audit-Eintrag mit Verlinkung zum jeweiligen Modell
- Permanente Filter-Defaults pro User

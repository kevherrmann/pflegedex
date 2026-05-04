# Schritt 2: Mailpit + laravel-auditing + CareReportCategory-Enum

## Anwendung

1. **Patch ueber das Repo legen.**
2. **Auditing-Package installieren** (composer.lock muss neu erstellt werden):
   ```
   docker compose exec app composer require owen-it/laravel-auditing:^13.6
   ```
   Falls der Container noch nicht laeuft: vorher `docker compose up -d`.
3. **Container fuer Mailpit neu bauen:**
   ```
   docker compose down
   docker compose up --build
   ```
   Mailpit-UI dann unter http://localhost:8025 erreichbar.
4. **Tests:**
   ```
   docker compose exec app php artisan test
   ```

## Was sich aendert

### Mailpit (lokales SMTP fuer Mail-Tests)

- `docker-compose.yml`: neuer `mailpit`-Service auf Ports 1025 (SMTP) und 8025 (UI)
- `.env.example`: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
- README erklaert das Setup

Lokale `.env` muesste auf Mailpit umgestellt werden, falls bisher `MAIL_MAILER=log`
(Default war `log`, also keine echten Mails). Praktisch: alle Mails landen jetzt
in der Mailpit-Web-UI statt im Log.

### owen-it/laravel-auditing

- `composer.json` ergaenzt um `owen-it/laravel-auditing:^13.6`
- Neue Migration `2026_05_04_120000_create_audits_table.php` mit UUID-PKs/FKs
  (statt Standard-bigInt - sonst kollidiert es mit unseren UUID-Models)
- Models implementieren `OwenIt\Auditing\Contracts\Auditable`:
  - `User` (mit `auditExclude` fuer `password` und `remember_token`)
  - `Resident`
  - `CareReport`
  - `Location`
- `config/audit.php` mit Pflegedex-spezifischer Config:
  - `console => false`: Seeder-Laeufe schreiben **keine** Audits (verhindert Demo-Laerm)
  - `queue.enable => false`: Audits synchron schreiben (deterministisch in Tests)

Der Audit-Log-View kommt erst in Schritt 6. Aktuell wird nur die DB-Foundation gelegt.

### CareReportCategory-Enum

- Neu: `app/Enums/CareReportCategory.php` als Source-of-Truth
  - 6 Cases: `Grundpflege`, `Beobachtung`, `Mobilitaet` (Wert: `Mobilität`),
    `Medikation`, `Uebergabe` (Wert: `Übergabe`), `Sonstiges`
  - `CareReportCategory::values()` gibt die geordnete String-Liste zurueck
- `CareReportController`: Validation in `store()` und `update()` nutzt
  `Rule::in(CareReportCategory::values())` statt der zu laschen `string max:80`-Regel
- `CareReportController::categories()` ruft jetzt das Enum auf
- `CareReportFactory`: nutzt Enum-Werte
- DB bleibt String-Spalte (kein Cast im Model). Begruendung: ein Cast wuerde
  `$report->category` zum Enum-Objekt machen, was im Inertia-Payload und in
  `Collection->where('category', $string)` Vergleichen brechen wuerde. Wir
  bekommen die Type-Safety beim Schreiben (Validation+Factory) - das reicht.

### Neue Tests

- `tests/Feature/CareReportCategoryEnumTest.php` (3 Tests)
- `tests/Feature/AuditingTest.php` (4 Tests)

## Nicht enthalten (kommt in Folge-Schritten)

- Audit-Log-View (Inertia-Seite fuer PDL/Admin) - Schritt 6
- Spaltenbezogene Audit-Filter, Export

## Hinweis: lokale .env

Falls deine lokale `.env` noch den alten `MAIL_MAILER=log` hat: aendern auf

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

sonst landen Mails im Log statt in Mailpit.

# Pflegedex — Weg zur professionellen, prüfungs- & betriebsreifen Pflegesoftware

Stand: 2026-06-19. Grundlage: dreigeteiltes Audit (Sicherheit/Datenschutz, Code-Qualität, fachliche Vollständigkeit).

Ausgangslage: handwerklich überdurchschnittliches MVP (Backend-Testratio >1:1, sauberer Dienstplangenerator,
SIS→Maßnahmenplan mit Versionierung/Signatur), aber **noch nicht prüfungs-/betriebsreif**. Es fehlen
Datenverschlüsselung, Automatisierung (CI) und die geschäftskritischen Fachmodule (Medikation/BTM,
Durchführungsnachweis, validierte Assessments, Vitalwerte).

Leitplanken (aus README übernommen): Pflegedaten verlassen den Server nicht; KI-Ausgaben sind nur Entwürfe;
signierte Berichte sind unveränderlich; spätere Versionen append-only; LLM-Eingaben pseudonymisiert.

---

## Phase 0 — Sicherheits-Sofortpaket  ✅ in Arbeit (Software ist live)
Klein, hohe Wirkung, kein Datenrisiko. Muss zuerst, weil öffentlich erreichbar mit Gesundheitsdaten.
- [x] Eigener Produktions-`APP_KEY` (nicht mehr identisch mit Dev). — K2
- [x] Demo-Seeder produktionsseitig gesperrt (`DatabaseSeeder` nur außerhalb `production`). — M1
- [x] `trustProxies` auf konkrete Proxy-CIDR/env statt `*`. — H4
- [x] IDOR-Lücken geschlossen: `AbsenceRequestController::approve/reject`, `ShiftWishController::index/store/destroy`
      mit Standort-Scope (`canAccessLocation`). — H1/H2
- [ ] **H3** `RosterBlackoutDayController`-Scope zurückgestellt — Produktentscheidung nötig: Darf eine PDL Sperrtage
      nur im eigenen Wohnbereich anlegen? Aktuell global per Location-Dropdown; striktes Scoping würde das Verhalten ändern.
- [x] Passwort-Policy verschärft (`Password::defaults` min. 12, mixedCase, numbers, uncompromised). — M4
- [x] `SESSION_ENCRYPT=true` in Produktion. — M3
- [x] Security-Header ergänzt: HSTS + Content-Security-Policy (Caddy). — M2
- [x] Pest-Tests für alle IDOR-Fixes (fremder Wohnbereich → 403).

## Phase 1 — Datenschutz-Kern (kritisch)
- [x] **K1** Verschlüsselung der Gesundheitsdaten at-rest — ERLEDIGT 2026-06-19. `encrypted`-Casts auf 9 Freitext-Feldern
      (`CareReport.body`, `ReportVersion.content_snapshot`, `Sis.opening_question`, `SisTopicEntry.content`,
      `SisRisk.notes`, `SisVersion.content_snapshot`, `CarePlan.grundbotschaft`, `CarePlanTopicEntry.content`,
      `CarePlanVersion.content_snapshot`). Daten-Migration `…encrypt_existing_care_data` (idempotent). Bewohner-Namen/
      Pseudonym BEWUSST nicht verschlüsselt (SQL-Sortierung/Suche) — bei Bedarf später per Blind-Index. Begleitfix:
      `GenerateSisJob` schrieb `content` per Query-Builder (umging Cast → Klartext) → auf Model-`save()` umgestellt.
      AuditController entschlüsselt Audit-Werte für autorisierte Anzeige. 614 Tests grün, in Prod verifiziert (DB chiffriert, Model lesbar).
- [x] **K3** Audit & fachliche Versionen revisionssicher — ERLEDIGT 2026-06-19. PostgreSQL-Trigger gegen
      UPDATE/DELETE auf `audits` (komplett) und den 3 Version-Tabellen (DELETE verboten; UPDATE nur erlaubt,
      solange `content_snapshot`/`snapshot_reason` unverändert — `created_by`-Nullung via nullOnDelete bleibt möglich).
      Migration `…make_audit_and_versions_append_only` (treiberabhängig: Trigger nur pgsql). Zusätzlich
      Eloquent-Guard-Trait `AppendOnly` (deleting/updating) als DB-unabhängige zweite Verteidigungslinie auf den
      Version-Modellen. 4 neue Tests, 618 grün; 4 Trigger in Prod-DB verifiziert.
      (Optional später: separater DB-User ohne UPDATE/DELETE-Recht, Hash-Chaining.)

## Phase 2 — Fundament & Qualität (sichert alles Weitere ab)
- [x] CI-Pipeline `.github/workflows/ci.yml` (Annahme GitHub): Backend-Job (composer install → Pint `--test` →
      PHPStan → Pest, mit Postgres-Service) + Frontend-Job (npm ci → typecheck → build). ERLEDIGT 2026-06-19.
- [x] Statische Analyse: Larastan (Level 5) mit `phpstan-baseline.neon` (292 Altbefunde eingefroren → nur NEUE
      Fehler brechen CI). Pint auf gesamte Codebase angewandt (253 Dateien sauber). composer-Scripts
      `test`/`lint`/`fix`/`analyse`, npm-Script `typecheck`. ERLEDIGT 2026-06-19.
- [x] ESLint + Prettier fürs Frontend — ERLEDIGT 2026-06-20. `eslint.config.js` (flat, typescript-eslint +
      react-hooks + prettier-compat), `.prettierrc`/`.prettierignore`, npm-Scripts `lint`/`format`/`format:check`.
      Gesamtes Frontend mit Prettier formatiert; ESLint 0 Fehler/0 Warnungen; CI-Frontend-Job um format:check + lint erweitert.
- [x] **SICHERHEIT — Dependency-Updates** — ERLEDIGT 2026-06-20. `composer audit`: **19 Advisories/10 Pakete → 0**.
      Guzzle, PSR-7, 7× Symfony gepatcht; danach **Major-Upgrade Laravel 11 → 12.62.0** (+ `owen-it/laravel-auditing`
      v13→v14, sonst keine Code-Änderungen nötig). 635 Tests grün, Pint+PHPStan grün, in Prod deployed, audit komplett clean.
- [ ] OFFEN (Phase-2-Rest): PHPStan-Baseline schrittweise abbauen / Level erhöhen (300 Altbefunde, v.a. larastan-Cast-Falschmeldungen).
- [ ] FormRequests für SIS/Roster/Staff/Absence (Validierung aus Controllern ziehen, isoliert testbar).
- [ ] `resources/js/Pages/Rosters/Show.tsx` (~2000 Z.) in Subkomponenten zerlegen; Frontend-Tests (Vitest) beginnen.
- [ ] Fehlerbehandlung um KI-/PDF-Pfade härten (`OllamaClient`, `GenerateCarePlanJob`, PDF): Timeouts/Retry/Fehlerzustände.

## Phase 3 — Geschäftskritische Fachmodule (P0 — ohne diese kein realer Betrieb)
Reihenfolge nach Hebel & Risiko. Jedes Modul nach Projekt-Schablone (siehe Skill `care-module`):
Migration + Model (UUID, Audit) + Enum + Controller + Policy + Inertia-Seiten + Pest-Tests + ggf. PDF.
1. [x] **Vitalwerte** (RR, Puls, AF, Temp, BZ, Gewicht, SpO₂) — ERLEDIGT 2026-06-19. Tabelle `vital_signs`
       (UUID, resident/location/recorded_by, Messwerte, `note` verschlüsselt, auditierbar), `VitalSignController`
       (index/store/destroy, Standort-Scope + Rollen PDL/Pflegekraft, Wertebereich-Validierung, mind. 1 Messwert),
       Inertia-Seite `Vitals/Index` (Erfassen + Verlaufstabelle), Link „Vitalwerte" in Bewohner-Übersicht.
       9 Tests, 627 gesamt grün, Pint+PHPStan grün, in Prod deployed.
2. [x] **Durchführungsnachweis / Pflegekurve (LNW)** — ERLEDIGT 2026-06-19. Tabellen `care_tasks` (geplante
       Maßnahmen je Bewohner: Titel, Kategorie-Enum, Turnus, Beschreibung verschlüsselt, active) +
       `care_task_completions` (tägliche Quittierung: Status-Enum done/refused/not_needed/omitted, performed_on,
       performed_by/at, Notiz verschlüsselt). `CareTaskController` (index mit Datumswahl, store, destroy=deaktivieren
       unter Erhalt der Nachweise, complete, destroyCompletion; Standort-Scope + Rollen + Objektbezug-404).
       Inertia-Seite `CareTasks/Index` (Maßnahmen + tägliche Quittierung pro Tag), Link „Nachweis" in Bewohner-Übersicht.
       8 Tests, 635 gesamt grün, Pint+PHPStan grün, in Prod deployed. *geplant ≠ geleistet ist damit dokumentierbar.*
       (Folge-Idee: Kopplung an Schicht/Maßnahmenplan-Themen, Tages-Soll/Ist-Übersicht.)
3. [~] **Validierte Assessments** — Framework + 2 Instrumente ERLEDIGT 2026-06-20. Erweiterbares, datengetriebenes
       Modul: `AssessmentType`-Enum trägt Item-Katalog + Scoring + Re-Eval-Intervall; Tabelle `assessments`
       (`answers` als `encrypted:array`, `note` verschlüsselt, total_score/risk_level/next_due, auditierbar);
       `AssessmentController` (dynamische Validierung gegen Katalog, Scoring, Standort-Scope+Rollen);
       Inertia-Seite `Assessments/Index` (Instrument wählen → Items → Score+Risiko+Verlauf), Link in Bewohner-Übersicht.
       Implementiert: **Braden** (Dekubitus, 6 Items, Risikostufen) + **Schmerz/NRS** (0–10). 9 Tests, 644 gesamt grün.
       ERWEITERT 2026-06-20 um **Norton, Sturzrisiko, MNA, Kontinenzprofil** (jetzt 6 Instrumente, 10 Tests, 645 grün).
4. [x] **Medikamentenmanagement inkl. BTM** — ERLEDIGT 2026-06-20. Tabellen `medications` (Medikationsplan:
       Name, Form-Enum, Stärke, 1-1-1-1-Schema, Bedarf/PRN + Hinweis verschlüsselt, **BTM-Flag**, Arzt, Start/Ende,
       active) + `medication_administrations` (MAR/Quittierung: Zeitpunkt-Enum, Status-Enum, administered_by/at,
       **witness_by**, Notiz verschlüsselt). `MedicationController` (index mit Datumswahl, store, destroy=absetzen
       unter Erhalt des Nachweises, administer, destroyAdministration; Standort-Scope+Rollen, Objektbezug-404).
       **BTM-Vier-Augen-Prinzip**: bei tatsächlicher BTM-Gabe ist eine Zweitkraft (Pflegepersonal, ≠ abgebende Person)
       verpflichtend. Inertia-Seite `Medications/Index`, Link „Medikation" in Bewohner-Übersicht. 10 Tests, 655 gesamt grün.
       OFFEN (Folge): BTM-Bestandsbuch (Zu-/Abgang/Bestand), Medikationsplan-PDF, Interaktions-/Allergieprüfung.

## Phase 4 — P1-Module (seriöser Vollbetrieb)
- [x] **Wunddokumentation** (Wunde + Verlauf mit Stadium/Größe/Schmerz/Maßnahmen) — ERLEDIGT 2026-06-20. OFFEN: Foto-Anhang.
- [ ] Lagerungs-/Bewegungsplan. Trink-/Ernährungsprotokoll mit Bilanzierung. Sturzprotokoll/-meldung.
- [ ] Stammdaten erweitern: Einzug/Auszug, Hausarzt, Betreuer/Bevollmächtigter, Diagnosen, Allergien,
      Patientenverfügung/Vorsorgevollmacht. Resident-Archivstatus + Aufbewahrungsfrist (10 Jahre).
- [ ] Autorisierung in Policies/Gates konsolidieren (statt verstreut in Controllern). Pflegebericht-PDF-Export.

## Phase 5 — Große Brocken (eigene Projekte)
- [ ] Abrechnung SGB XI mit DTA §302/§105 (Kostenträger, Pflegekassen, Entgelte/EEE, Schnittstellen).
- [x] **Qualitätsindikatoren §113b** (interne halbjährliche Erhebung je Bewohner + aggregierte Auswertung) — ERLEDIGT 2026-06-20.
      OFFEN: offizielle Risikoadjustierung + DAS-Datenübermittlung (regulatorischer Großschritt).
- [ ] Träger-/Mandantenebene über dem Standort (Mehrheim-Betreiber).

---

### Arbeitsweise
- Jede Änderung: Branch-fähig, mit Tests, über `npm run build`/`pest` verifiziert, dann deploy + Smoke-Test.
- Kein Fremdcode aus dem Netz in die Gesundheitsdaten-App. Wiederverwendbares Muster im Skill `care-module`.
- Deploy-Ablauf siehe Memory `pflegedex-deployment`: Datei → ggf. Assets bauen → hochladen → `chown 1000` → Caches.

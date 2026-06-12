# Manuelle End-to-End-Testcheckliste: Dienstplan-Demo-Daten

Diese Checkliste gehört zu Phase 3.6.2. Sie ergänzt die [Dienstplan-Demo-Daten](roster-demo-data.md) um eine praktische, durchgehende Prüfung des aktuellen Stands.

## 1. Ziel der manuellen Prüfung

Mit dieser Checkliste wird geprüft, ob die in Phase 3.5/3.6 umgesetzten Funktionen für Dienstplan- und Urlaubsplanung mit realistischen Demo-Daten praktisch bedienbar sind. Es geht nicht um eine vollständige Abnahme einzelner Regeln, sondern um den durchgehenden Ablauf aus Sicht einer PDL: Dienstplan öffnen, Stammdaten prüfen, Auto-Planung starten, Validator beurteilen, manuell nacharbeiten, zurücksetzen und veröffentlichen.

Der getestete Stand wird bewusst mit seinen bekannten Grenzen dokumentiert (siehe Abschnitt 11), damit Auffälligkeiten als „erwartet" oder „neu" eingeordnet werden können.

## 2. Vorbereitung

1. **Container starten, falls nötig**

   ```bash
   flatpak-spawn --host docker compose up -d
   ```

2. **Migrationen/Seeder-Hinweis**

   Der Demo-Befehl legt Daten nur an oder aktualisiert sie und löscht keine bestehenden Daten. Falls Tabellen fehlen, vorsichtig nur die ausstehenden Migrationen nachziehen:

   ```bash
   flatpak-spawn --host docker compose exec app php artisan migrate
   ```

   Kein `migrate:fresh` ausführen. Das würde vorhandene Daten unwiderruflich löschen und ist für diesen Test nicht erforderlich.

3. **Demo-Daten-Command ausführen**

   ```bash
   flatpak-spawn --host docker compose exec app php artisan pflegedex:seed-roster-demo --month=2027-01
   ```

   Der Befehl ist wiederholbar. Bei einem erneuten Lauf werden die Demo-Daten aktualisiert, nicht dupliziert.

4. **Optional: Tests ausführen**

   ```bash
   flatpak-spawn --host docker compose exec app php artisan test --filter=RosterDemoDataCommandTest
   ```

### Erwartete Demo-Daten

- 2 Wohnbereiche (A und B) mit je 20 Bewohnern (Pflegegrade 2–5), insgesamt 40 Bewohner
- Demo-PDL (wohnbereichsübergreifend)
- Pflegepersonal je Bereich (12 Personen) als Qualifikationsmix:
  - 1 Wohnbereichsleitung (WBL, Pflegefachkraft, ~50 % Stunden)
  - 4 weitere Pflegefachkräfte (davon 1 Nachtwache)
  - 3 Pflegeassistenten
  - 4 Pflegehilfskräfte (davon 1 Nachtwache)
- je Bereich 1 Putzkraft (2 gesamt) und 1 Hausmeister
- Schichtvorlagen je Bereich:
  - Frühdienst 06:00–14:00
  - Spätdienst 14:00–22:00
  - Nachtdienst 22:00–06:00
- Besetzungsregeln je Bereich:
  - Frühdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - Spätdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - Nachtdienst: 1 Mitarbeiter, davon 1 Fachkraft
- genehmigte Abwesenheiten im Januar 2027 (je Bereich)
- Draft-Dienstplan Januar 2027 je Bereich

## 3. Login und Rechteprüfung

- [ ] Als Demo-PDL einloggen:
  - E-Mail: `demo.pdl.dienstplan@pflegedex.local`
  - Passwort: `password`
- [ ] Prüfen, ob nur Wohnbereich A sichtbar und zugänglich ist.
- [ ] Prüfen, ob fremde Wohnbereiche nicht sichtbar oder nicht bearbeitbar sind, falls dafür Testdaten existieren.
- [ ] Falls keine fremden Wohnbereiche vorhanden sind: als Beobachtung notieren, dass die Abgrenzung nicht praktisch geprüft werden konnte.

## 4. Dienstplan öffnen

- [ ] Dienstplan für Januar 2027 öffnen.
- [ ] Status `Draft` (Entwurf) prüfen.
- [ ] Monatsübersicht prüfen (Tage, Schichten, Besetzung pro Tag).
- [ ] Filter prüfen:
  - [ ] Alle
  - [ ] Mit Diensten
  - [ ] Ohne Dienste
  - [ ] Wochenenden
  - [ ] Mit Problemen

Für jeden Filter prüfen, ob die angezeigte Auswahl plausibel zur Auswahlbedingung passt.

## 5. Stammdaten prüfen

- [ ] **Schichtvorlagen** prüfen:
  - [ ] Frühdienst 06:00–14:00
  - [ ] Spätdienst 14:00–22:00
  - [ ] Nachtdienst 22:00–06:00
- [ ] **Besetzungsregeln** prüfen:
  - [ ] Frühdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - [ ] Spätdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - [ ] Nachtdienst: 1 Mitarbeiter, davon 1 Fachkraft
- [ ] **Pflegekräfte** prüfen:
  - [ ] Qualifikationsstufen korrekt (Pflegefachkraft / Pflegeassistent / Pflegehilfskraft)
  - [ ] WBL als Pflegefachkraft gekennzeichnet
  - [ ] nur Pflegefachkräfte zählen im Dienstplan als Fachkraft
  - [ ] Wochenstunden plausibel hinterlegt
  - [ ] Nachtfähigkeit korrekt gesetzt (nur Nachtwachen-Profile)
  - [ ] aktive Profile (keine inaktiven/gesperrten Mitarbeiter im Plan)
- [ ] **Beide Wohnbereiche** prüfen: Bereich A und Bereich B haben je eigenes Personal, Schichtvorlagen, Besetzungsregeln und einen eigenen Dienstplan.

## 6. Auto-Planung testen

- [ ] Auto-Planung starten.
- [ ] Prüfen, ob Auto-Dienste erzeugt werden.
- [ ] Prüfen, ob Auto-Badges an den erzeugten Diensten angezeigt werden.
- [ ] Prüfen, ob Dienste nicht auf genehmigte Abwesenheiten fallen.
- [ ] Prüfen, ob Früh-/Spät-/Nacht-Fähigkeiten beachtet werden (z. B. keine Nachtdienste für nicht nachtfähige Mitarbeiter).
- [ ] Prüfen, ob der Fachkraftbedarf grob eingehalten wird (mindestens 1 Fachkraft je Schicht laut Besetzungsregel).
- [ ] Generator-Skips prüfen: werden übersprungene Zuordnungen verständlich dargestellt und nachvollziehbar begründet?

## 7. Validator prüfen

- [ ] Ergebnisbox prüfen (Zusammenfassung der Prüfung).
- [ ] Kalender-Markierungen prüfen (markierte Tage/Schichten).
- [ ] Rote Fehler prüfen.
- [ ] Gelbe Warnungen prüfen.
- [ ] Grüne Bestätigung prüfen.
- [ ] Falls rote Fehler auftreten: notieren, ob sie fachlich nachvollziehbar sind.
- [ ] Prüfen, ob die Veröffentlichung bei roten Fehlern blockiert wird.

## 8. Manuelle Dienste prüfen

- [ ] Dienst manuell hinzufügen.
- [ ] Dienst bearbeiten.
- [ ] Dienst löschen.
- [ ] Einen Auto-Dienst bearbeiten und prüfen, ob er danach als `Manuell` gekennzeichnet wird.
- [ ] Prüfen, ob Published/Locked Dienstpläne gegen Änderungen geschützt sind, falls die UI dafür vorhanden ist.

## 9. Auto-Planung zurücksetzen

- [ ] Auto-Planung zurücksetzen.
- [ ] Prüfen, ob Auto-Dienste gelöscht werden.
- [ ] Prüfen, ob manuell bearbeitete Dienste erhalten bleiben.
- [ ] Prüfen, ob der Validator danach sinnvoll aktualisiert wird (Ergebnisbox und Markierungen passen zum neuen Stand).

## 10. Veröffentlichung / Statuswechsel

- [ ] Draft veröffentlichen, falls keine blockierenden Fehler vorhanden sind.
- [ ] Published-Schutz prüfen (veröffentlichter Plan ist gegen unkontrollierte Änderungen geschützt).
- [ ] Locked-Schutz prüfen, falls die UI dafür vorhanden ist.
- [ ] Falls der Statuswechsel aktuell noch nicht vollständig über die UI verfügbar ist: als offene Beobachtung notieren.

## 11. Bekannte Grenzen

Die folgenden Grenzen sind dem aktuellen Stand bekannt und beim Testen zu berücksichtigen. Auffälligkeiten in diesen Bereichen sind erwartbar und kein Fehler:

- Mehrfachzuordnung zu Wohnbereichen ist noch nicht abschließend geprüft (später prüfen).
- Das historische Löschverhalten ist noch nicht abschließend geprüft (später prüfen).
- Das Wochenend-Limit (max. 2 Wochenenden/Monat) darf zugunsten der Besetzung
  weichen, wenn ein Slot sonst unbesetzt bliebe (konfigurierbar über
  `rostering.relax_weekend_limit_for_coverage`). Der Validator meldet die
  Mehrbelastung dann als Hinweis — das ist erwartet, kein Fehler.

Frühere Grenzen, die inzwischen behoben sind:

- Der Generator optimiert jetzt zweiphasig (Konstruktion nach weichen Zielen,
  anschließend lokale Suche mit Tausch-, Verschiebe- und Wochenendblock-Zügen).
- Es gibt einen Vorschau-Modus („Vorschau" neben „Automatisch planen"): gleiche
  Pipeline ohne Persistenz, inkl. projizierter Validierung und Auslastung.
- Monatsgrenzen werden berücksichtigt (Ruhezeiten, Arbeitstage am Stück,
  Wochenstunden über den Monatswechsel sowie Dienste in anderen Dienstplänen
  desselben Mitarbeiters), in Generator und Validator gleichermaßen.
- Mitarbeiterwünsche (Wunschfrei / Wunschdienst) existieren als eigene
  Verwaltungsseite und fließen als weiche Ziele in die Planung ein.
- Schwellwerte und Strafgewichte sind über `config/rostering.php` konfigurierbar.
- Mitarbeiter sehen ihre eigenen Dienste unter „Mein Dienstplan" (/my-roster);
  sichtbar sind ausschließlich veröffentlichte oder gesperrte Pläne.
- „Mein Dienstplan" zeigt die Teambesetzung des Wohnbereichs an allen Tagen
  des Monats (an Arbeitstagen „Mit wem arbeite ich zusammen?", an freien Tagen
  „Wer ist im Dienst?") — Grundlage für Diensttausch-Absprachen.

## 12. Testergebnis-Vorlage

```
Datum:
Tester:
Branch/Commit:
Browser:

Ergebnis:
- [ ] bestanden
- [ ] bestanden mit Auffälligkeiten
- [ ] nicht bestanden

Auffälligkeiten:
1.
2.
3.

Nächste empfohlene Maßnahmen:
```

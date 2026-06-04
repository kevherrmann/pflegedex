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

- Wohnbereich A
- Demo-PDL
- 12 Pflegekräfte
- Schichtvorlagen:
  - Frühdienst 06:00–14:00
  - Spätdienst 14:00–22:00
  - Nachtdienst 22:00–06:00
- Besetzungsregeln:
  - Frühdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - Spätdienst: 2 Mitarbeiter, davon 1 Fachkraft
  - Nachtdienst: 1 Mitarbeiter, davon 1 Fachkraft
- genehmigte Abwesenheiten im Januar 2027
- Draft-Dienstplan Januar 2027

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
  - [ ] Fachkräfte korrekt gekennzeichnet
  - [ ] Nicht-Fachkräfte korrekt gekennzeichnet
  - [ ] Wochenstunden plausibel hinterlegt
  - [ ] Nachtfähigkeit korrekt gesetzt
  - [ ] aktive Profile (keine inaktiven/gesperrten Mitarbeiter im Plan)

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

- Der Generator ist kein globaler Optimierer. Er erzeugt eine brauchbare, aber nicht zwingend optimale Belegung.
- Es gibt keinen Vorschau-Modus. Die Auto-Planung schreibt direkt in den Entwurf.
- Monatsgrenzen werden noch nicht vollständig berücksichtigt:
  - Ruhezeiten über den Monatswechsel
  - Sonntagsausgleich über den Monatswechsel
  - Arbeitstage am Stück über den Monatswechsel
  - Wochenendlast über den Monatswechsel
- Mitarbeiterwünsche (Wunschfrei / Wunschdienst) fehlen.
- Die Mitarbeiteransicht „Mein Dienstplan" fehlt.
- Die Ansicht „Mit wem arbeite ich zusammen?" fehlt.
- Mehrfachzuordnung zu Wohnbereichen ist noch nicht abschließend geprüft (später prüfen).
- Das historische Löschverhalten ist noch nicht abschließend geprüft (später prüfen).
- Harte Regeln und weiche Warnungen sind noch nicht konfigurierbar (später ermöglichen).

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

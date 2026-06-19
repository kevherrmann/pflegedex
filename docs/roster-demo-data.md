# Dienstplan-Demo-Daten

Mit diesem Befehl erzeugst oder aktualisierst du Demo-Daten für Dienstplan und Urlaubsplanung:

```bash
flatpak-spawn --host docker compose exec app php artisan pflegedex:seed-roster-demo --month=2027-01
```

Login:

- E-Mail: `demo.pdl.dienstplan@pflegedex.local`
- Passwort: `password`

Der Befehl baut ein realistisches Demo-Pflegeheim auf, das sich am Personalbemessungsverfahren (PeBeM nach § 113c SGB XI) orientiert. Er löscht keine bestehenden Daten und ist wiederholbar. In Produktion bricht er ohne `--force` ab.

## Was angelegt wird

- **2 Wohnbereiche**: `Wohnbereich A` und `Wohnbereich B` mit je 20 Bewohnern (gemischte Pflegegrade 2–5), insgesamt 40 Bewohner.
- **1 PDL** (`demo.pdl.dienstplan@pflegedex.local`), wohnbereichsübergreifend mit Zugriff auf beide Bereiche.
- **Pflegepersonal je Bereich (12 Personen)** als Qualifikationsmix:
  - 1 Wohnbereichsleitung (WBL, examinierte Pflegefachkraft, ~50 % Stunden, kein Nachtdienst)
  - 3 weitere Tages-Pflegefachkräfte
  - 3-köpfiges Nachtwachen-Team aus Pflegefachkräften (der Nachtdienst verlangt zwingend eine Fachkraft, eine einzelne deckt keinen vollen Monat ab)
  - 3 Pflegeassistenten
  - 2 Pflegehilfskräfte
- **Hauswirtschaft und Technik**: je Bereich 1 Putzkraft (2 gesamt) sowie 1 Hausmeister.
- **Schichtprofile**: Voll- und Teilzeit, reine Frühdienst-Profile (Familienschicht) und reine Nachtwachen.
- **Schichtvorlagen** (Frühdienst, Spätdienst, Nachtdienst) und **Besetzungsregeln** je Bereich (Frühdienst 2 / 1 Fachkraft, Spätdienst 2 / 1 Fachkraft, Nachtdienst 1 / 1 Fachkraft).
- **Genehmigte Abwesenheiten** und ein **Draft-Dienstplan je Bereich**.

Der Befehl stellt außerdem sicher, dass die Rollen `PDL`, `WBL`, `Pflegekraft`, `Putzkraft` und `Hausmeister` existieren, damit die Mitarbeiterseite nicht wegen einer fehlenden Rolle abbricht.

## Qualifikationsstufen (PeBeM)

Pflegekräfte tragen eine Qualifikationsstufe (`App\Enums\QualificationLevel`):

- **Pflegefachkraft** (`specialist`) – examinierte Fachkraft (QN 4). Nur diese Stufe zählt im Dienstplan-Generator und Validator als Fachkraft (`is_nursing_specialist`).
- **Pflegeassistent** (`assistant`) – QN 3, ein- bis zweijährige Ausbildung.
- **Pflegehilfskraft** (`aide`) – QN 1/2, angelernt oder ohne Ausbildung.

WBL und Putz-/Hausmeisterpersonal gehören nicht zum Pflege-Qualifikationsmix der Generierung: Putz- und Hausmeisterprofile sind keine Pflege und werden nicht eingeplant; die WBL ist Fachkraft und kann mitarbeiten.

> Hinweis: Eigene Berechtigungen für die WBL-Rolle (z. B. eingeschränkte Leitungsrechte) sind noch nicht modelliert – die Rolle existiert aktuell vor allem für realistische Personal- und Dienstplandaten. Das wäre ein eigener nächster Schritt.

## Manueller Test

1. Demo-Daten für `2027-01` erzeugen.
2. Als `demo.pdl.dienstplan@pflegedex.local` anmelden.
3. Dienstplan für Januar 2027 öffnen.
4. Monatsübersicht prüfen.
5. Auto-Generator starten.
6. Validator-Ergebnis nach der Auto-Planung prüfen.
7. Generator-Skips prüfen, falls welche angezeigt werden.
8. Prüfen, ob Auto-Dienste mit Badge angezeigt werden.
9. Einen Auto-Dienst bearbeiten und prüfen, ob der Dienst danach als `Manuell` angezeigt wird.
10. Auto-Planung zurücksetzen und prüfen, ob manuelle Dienste erhalten bleiben und andere Auto-Dienste gelöscht wurden.
11. Published/Locked-Schutz prüfen, falls die UI dafür verfügbar ist.

Eine ausführliche manuelle Testcheckliste steht in [roster-manual-e2e-test.md](roster-manual-e2e-test.md).

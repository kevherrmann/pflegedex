# Pflegedex Projektstandards

## Domänensprache

Wir verwenden im Code und in der UI den Begriff **Bewohner / Resident**.

Der Begriff `Patient` wird nicht für neue Tabellen, Models, Routes, React-Komponenten oder Tests verwendet.

| Fachlich | Code |
|---|---|
| Bewohner | Resident |
| Bewohnerliste | Residents/Index |
| Bewohner anlegen | Residents/Create |
| Pflegebericht | CareReport |
| Wohnbereich | Location |

Ausnahme: Wenn externe Pflege-/SIS-Dokumente fachlich den Begriff Patient verwenden, darf er in Import-/Referenztexten vorkommen. Neue Applikationslogik bleibt trotzdem bei `Resident`.

## PHP-Version

Aktueller Projektstandard: PHP `^8.2`.

Laravel 11 ist damit kompatibel. Ein Upgrade auf PHP 8.3 bleibt für Production/Pilot möglich, wird aber nicht im laufenden Phase-0-Abschluss erzwungen.

## Rollenmodell

### Admin

Initialer Systemaccount.

Darf:

- PDL-Accounts anlegen
- PDL-Accounts bearbeiten
- Systemnahe Grundverwaltung durchführen

Darf nicht:

- operative Pflegeberichte schreiben
- Bewohnerdaten als Pflegekraft bearbeiten

### PDL

Pflegedienstleitung.

Darf:

- Bewohner im eigenen Wohnbereich anlegen
- Bewohner im eigenen Wohnbereich bearbeiten
- Mitarbeiter für zugängliche Wohnbereiche anlegen
- Mitarbeiter für zugängliche Wohnbereiche bearbeiten
- Pflegeberichte einsehen

Darf nicht:

- Admins anlegen
- andere PDLs anlegen
- Daten fremder Wohnbereiche verwalten

### Pflegekraft

Darf:

- Bewohner im eigenen Wohnbereich sehen
- Pflegeberichte erfassen

Darf nicht:

- Bewohner anlegen
- Mitarbeiter anlegen
- PDLs/Admins anlegen
- fremde Wohnbereiche sehen

## Wohnbereichsregel

Jeder Zugriff auf Bewohner- und Mitarbeiterdaten muss über `location_id` oder die Pivot-Tabelle `location_user` eingeschränkt werden.

Ein User darf nur Daten sehen oder bearbeiten, wenn mindestens eine seiner zugänglichen Locations mit der Ziel-Location übereinstimmt.

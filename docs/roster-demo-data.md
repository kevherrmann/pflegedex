# Dienstplan-Demo-Daten

Mit diesem Befehl erzeugst oder aktualisierst du Demo-Daten für Dienstplan und Urlaubsplanung:

```bash
flatpak-spawn --host docker compose exec app php artisan pflegedex:seed-roster-demo --month=2027-01
```

Login:

- E-Mail: `demo.pdl.dienstplan@pflegedex.local`
- Passwort: `password`

Der Befehl legt den Wohnbereich `Wohnbereich A`, eine PDL, 12 Pflegekräfte, Schichtvorlagen, Personalbesetzungsregeln, genehmigte Abwesenheiten und einen Dienstplan im Entwurf an. Er löscht keine bestehenden Daten und ist wiederholbar.

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

In Produktion bricht der Befehl ohne `--force` ab.

---
name: responsive-design
description: >
  Responsive-Design-Konventionen für Pflegedex (Inertia + React/TS + Tailwind).
  Verwenden, wenn eine Inertia-Seite oder Komponente fürs Handy/Tablet optimiert wird oder
  eine neue Seite angelegt wird. Ziel: Mobile-First nutzbar, Desktop unverändert.
  Kernregeln: konsistenter Page-Container, gestaffeltes Padding, Tabelle→Karten ab Breakpoint,
  Touch-Targets ≥44px, keine horizontalen Overflows außer bei reinen Statistik-Tabellen.
---

# Responsive Design in Pflegedex

Grundsatz: **Mobile-First nutzbar, Desktop pixelgleich wie bisher.** Änderungen dürfen das
Desktop-Layout (ab `lg`) NICHT verändern — sie fügen nur Verhalten für kleine Screens hinzu
(`base`/`sm`/`md`-Klassen) bzw. ergänzen vorhandene Breakpoints nach unten.

## Breakpoints (Tailwind-Default)
- `sm` 640 · `md` 768 · `lg` 1024 · `xl` 1280.
- Die Sidebar (`AuthenticatedLayout`) wechselt bei **`lg`**: darunter Hamburger + Off-Canvas, darüber feste Sidebar. Layout-Hülle ist bereits responsive — NICHT anfassen.
- Tabellen/Karten-Umschaltung machen wir bei **`md`** (Tabletten quer bekommen die Tabelle, Handys die Karten).

## Page-Container (immer so)
Jede Inhaltsseite verwendet exakt diesen Container — **base `px-4` ist Pflicht**, sonst klebt der Inhalt auf dem Handy am Rand:
```tsx
<div className="py-6 sm:py-8 lg:py-12">
  <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    ...
  </div>
</div>
```
- Vertikales Padding: `py-12` → **`py-6 sm:py-8 lg:py-12`**.
- Häufiger Bug im Bestand: `sm:px-6 lg:px-8` **ohne** `px-4`. Immer `px-4` davorsetzen.
- Max-Breite je nach Seite beibehalten (`max-w-7xl` Listen, `max-w-5xl`/`max-w-3xl` Detail/Form).

## Abstände & Typo
- Karten/Sektionen: `p-8` → **`p-5 sm:p-6 lg:p-8`**; `p-6` → **`p-4 sm:p-6`**.
- Seiten-Überschriften: `text-3xl` → **`text-2xl sm:text-3xl`**; sehr große `text-4xl` → `text-3xl sm:text-4xl`.
- Header-Leisten (`header`-Prop) und Button-Reihen: mit `flex-wrap` umbrechen lassen; Aktionsbutton auf Mobile `w-full sm:w-auto`, wenn er sonst gequetscht wird.

## Tabelle → Karten (der wichtigste Umbau)
Datentabellen mit vielen Spalten/Aktions-Links sind auf dem Handy unbenutzbar. Muster: Tabelle **ab `md`**,
gestapelte Karten **darunter**. Desktop bleibt dadurch identisch (Tabelle nur in `hidden md:block` gewrappt).

```tsx
{/* Desktop: unveränderte Tabelle, nur Wrapper bekommt hidden md:block */}
<div className="hidden overflow-x-auto md:block">
  <table className="min-w-full divide-y divide-[#E5E7EB]">
    {/* ...exakt wie vorher... */}
  </table>
</div>

{/* Mobile: gestapelte Karten */}
<ul className="divide-y divide-[#E5E7EB] md:hidden">
  {rows.map((row) => (
    <li key={row.id} className="space-y-3 p-4">
      <div className="flex items-start justify-between gap-3">
        <p className="font-medium text-[#333333]">{row.title}</p>
        {/* ggf. Status-Badge */}
      </div>
      <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
        <div>
          <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">Zimmer</dt>
          <dd className="text-[#54595F]">{row.room ?? '—'}</dd>
        </div>
        {/* weitere Felder */}
      </dl>
      <div className="flex flex-wrap gap-x-4 gap-y-2 pt-1">
        {/* dieselben Links/Aktionen wie in der Tabellenzeile */}
      </div>
    </li>
  ))}
</ul>
```
Regeln:
- Karten zeigen **dieselben** Daten/Aktionen wie die Tabellenzeile — keine Funktion fällt auf Mobile weg.
- Aktions-Link-Cluster IMMER `flex flex-wrap gap-x-4 gap-y-2` (nie in einer nicht umbrechenden Zeile).
- Die wichtigste Spalte (Name) wird Karten-Titel; Rest als `dt/dd`-Paare.
- Reine **Statistik-/Zahlentabellen** (z.B. Mitarbeiter-Auslastung, Vorschau-Stats im Dienstplan) dürfen
  `overflow-x-auto` behalten — horizontales Scrollen ist da akzeptabel und ein Karten-Umbau lohnt nicht.

## Formulare
- Grids: `grid gap-4 sm:grid-cols-2` (auf Mobile 1-spaltig) — Bestand ist meist schon so.
- Inputs/Selects `block w-full`. `type="date"`-Felder ebenfalls `w-full` (sonst auf iOS zu schmal).
- Mehrspaltige Eingabereihen (z.B. Dosis-Schema): `grid-cols-2 sm:grid-cols-4`.
- Submit-Buttons in vollbreiten Formularen rechtsbündig lassen; in schmalen Kontexten `w-full sm:w-auto`.

## Touch & Interaktion
- Tap-Ziele mind. ~44px: Icon-Buttons `p-2`+, Links in Karten genügend `py`.
- Keine Hover-only-Funktion ohne sichtbares Pendant.
- Modals: `max-h-[90vh] overflow-y-auto`, Außenabstand `p-4`, Breite `w-full max-w-…`.

## Verifikation (Pflicht nach Änderungen)
Lokal im Repo `/workspace/pflegedex`:
```
npm run typecheck && npm run lint && npm run format && npm run build
```
- `format` (Prettier) zum Schluss, damit der Diff sauber ist.
- Desktop-Gegencheck: Die geänderten Seiten ab `lg` müssen aussehen wie vorher (nur Wrapper-/Breakpoint-Klassen ergänzt, keine Desktop-Klassen entfernt).
- Stichprobe der Breakpoints im Browser-DevTools: 375px (Handy), 768px (Tablet), 1280px (Desktop).

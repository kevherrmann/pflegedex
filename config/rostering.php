<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Harte Planungsregeln
    |--------------------------------------------------------------------------
    |
    | Gesetzliche bzw. fachliche Grenzen, die der Dienstplangenerator nie
    | verletzt und die der Validator als Fehler bzw. Warnung meldet.
    |
    */

    // Mindestruhezeit zwischen zwei Diensten (§ 5 ArbZG: 11 Stunden).
    'required_rest_minutes' => (int) env('ROSTERING_REQUIRED_REST_MINUTES', 660),

    // Maximale Arbeitstage am Stück ohne freien Kalendertag.
    'max_consecutive_work_days' => (int) env('ROSTERING_MAX_CONSECUTIVE_WORK_DAYS', 6),

    // Maximale Wochenenden mit Diensten pro Dienstplanmonat.
    'max_weekends_per_month' => (int) env('ROSTERING_MAX_WEEKENDS_PER_MONTH', 2),

    // Darf das Wochenend-Limit weichen, wenn ein Slot sonst unbesetzt bliebe?
    // Besetzung schlägt Empfehlung: Der Validator meldet zu viele Wochenenden
    // als Hinweis, Unterbesetzung dagegen als Fehler.
    'relax_weekend_limit_for_coverage' => (bool) env('ROSTERING_RELAX_WEEKEND_LIMIT_FOR_COVERAGE', true),

    // Harte Obergrenze pro ISO-Kalenderwoche (EU-RL 2003/88: 48 Stunden).
    'weekly_max_minutes' => (int) env('ROSTERING_WEEKLY_MAX_MINUTES', 2880),

    // Tägliche Höchstarbeitszeit. § 3 ArbZG: i.d.R. 8 h, ausdehnbar auf 10 h (600 min) -
    // das ist der gesetzeskonforme Default. 12-Stunden-Schichten (720 min) sind nur auf
    // Basis einer Tariföffnung nach § 7 ArbZG zulässig (in der Pflege via TVöD/AVR üblich)
    // und werden dann pro Einrichtung via ROSTERING_DAILY_MAX_MINUTES=720 freigeschaltet.
    'daily_max_minutes' => (int) env('ROSTERING_DAILY_MAX_MINUTES', 600),

    // Fallback-Schichtlänge, wenn keine aktive Vorlage eine Dauer liefert.
    'default_shift_minutes' => (int) env('ROSTERING_DEFAULT_SHIFT_MINUTES', 480),

    /*
    |--------------------------------------------------------------------------
    | Monatsgrenzen
    |--------------------------------------------------------------------------
    |
    | Dienste in diesem Fenster vor und nach dem Dienstplanmonat werden bei
    | Ruhezeiten, Folgetagen und Wochenstunden mit betrachtet, damit Regeln
    | nicht an der Monatsgrenze abreißen.
    |
    */

    'boundary_window_days' => (int) env('ROSTERING_BOUNDARY_WINDOW_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Soll-Arbeitszeit
    |--------------------------------------------------------------------------
    */

    // Toleranz, bevor eine Überplanung als Warnung gemeldet wird.
    'planned_hours_tolerance_minutes' => (int) env('ROSTERING_PLANNED_HOURS_TOLERANCE_MINUTES', 60),

    // Unter diesem Anteil der Soll-Kapazität gilt ein Mitarbeiter als deutlich unterplant.
    'under_planned_factor' => (float) env('ROSTERING_UNDER_PLANNED_FACTOR', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Weiche Planungsziele (Strafgewichte)
    |--------------------------------------------------------------------------
    |
    | Der Generator wählt unter allen regelkonformen Kandidaten die Zuweisung
    | mit der geringsten Gesamtstrafe und verbessert den Plan anschließend per
    | lokaler Suche. Höheres Gewicht = wichtigeres Ziel. Referenz: Eine Schicht
    | entspricht ungefähr 480 Strafpunkten beim Stundenabweichungs-Gewicht 1.
    |
    */

    'weights' => [
        // Abweichung von der monatlichen Soll-Arbeitszeit, pro Minute.
        'hours_deviation' => (int) env('ROSTERING_WEIGHT_HOURS_DEVIATION', 1),

        // Gleichverteilung der Nachtdienste (quadratisch je Mitarbeiter).
        'night_fairness' => (int) env('ROSTERING_WEIGHT_NIGHT_FAIRNESS', 60),

        // Gleichverteilung der Wochenenden (quadratisch je Mitarbeiter).
        'weekend_fairness' => (int) env('ROSTERING_WEIGHT_WEEKEND_FAIRNESS', 200),

        // Rückwärtsrotation (z. B. Spätdienst auf Frühdienst am Folgetag).
        'rotation' => (int) env('ROSTERING_WEIGHT_ROTATION', 120),

        // Einzelner freier Tag, eingeklemmt zwischen Arbeitstagen.
        'split_free_day' => (int) env('ROSTERING_WEIGHT_SPLIT_FREE_DAY', 200),

        // Verplanter Wunschfrei-Tag (schwer, aber Besetzung geht immer vor).
        'wish_free' => (int) env('ROSTERING_WEIGHT_WISH_FREE', 600),

        // Erfüllter Wunschdienst (wirkt als Belohnung).
        'wish_shift' => (int) env('ROSTERING_WEIGHT_WISH_SHIFT', 300),

        // Sonntagsarbeit ohne freien Ersatzruhetag im Ausgleichszeitraum.
        'sunday_compensation' => (int) env('ROSTERING_WEIGHT_SUNDAY_COMPENSATION', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lokale Suche (Verbesserungsphase)
    |--------------------------------------------------------------------------
    */

    'improvement' => [
        'enabled' => (bool) env('ROSTERING_IMPROVEMENT_ENABLED', true),

        // Maximale Anzahl untersuchter Züge (Tausch/Übertragung).
        'max_iterations' => (int) env('ROSTERING_IMPROVEMENT_MAX_ITERATIONS', 20000),

        // Abbruch, wenn so viele Züge in Folge keine Verbesserung brachten.
        'stall_iterations' => (int) env('ROSTERING_IMPROVEMENT_STALL_ITERATIONS', 2500),

        // Zeitbudget als Sicherheitsnetz.
        'max_milliseconds' => (int) env('ROSTERING_IMPROVEMENT_MAX_MILLISECONDS', 3000),

        // Fester Seed für reproduzierbare Pläne; null = aus der Dienstplan-ID abgeleitet.
        'seed' => env('ROSTERING_IMPROVEMENT_SEED'),
    ],

];

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

    // Harte Obergrenze pro ISO-Kalenderwoche (EU-RL 2003/88: 48 Stunden).
    'weekly_max_minutes' => (int) env('ROSTERING_WEEKLY_MAX_MINUTES', 2880),

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

];

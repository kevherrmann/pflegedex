import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type EmployeeOption = {
    id: string;
    name: string;
    email: string;
    locationId: string | null;
    isNursingSpecialist: boolean;
    canWorkEarly: boolean;
    canWorkLate: boolean;
    canWorkNight: boolean;
};

type ShiftTemplateOption = {
    id: string;
    locationId: string;
    name: string;
    code: string;
    startsAt: string;
    endsAt: string;
};

type ShiftItem = {
    id: string;
    userId: string;
    shiftTemplateId: string;
    date: string;
    startsAt: string;
    endsAt: string;
    employeeName: string | null;
    shiftTemplateName: string | null;
    shiftTemplateCode: string | null;
    note: string | null;
};

type ValidationEntry = {
    code: string;
    message: string;
    context: Record<string, unknown>;
    title: string | null;
    details: string | null;
};

type RosterValidationResult = {
    rosterId: string;
    status: 'green' | 'yellow' | 'red';
    errors: ValidationEntry[];
    warnings: ValidationEntry[];
};

type RosterGenerationResult = {
    createdShifts: number;
    deletedAutoShifts: number;
    skipped: Array<{
        code: string;
        message: string;
        context: Record<string, unknown>;
    }>;
};

type CalendarDay = {
    date: string;
    dayLabel: string;
    weekdayLabel: string;
    shifts: ShiftItem[];
};

type MonthOverviewFilter =
    | 'all'
    | 'with-shifts'
    | 'without-shifts'
    | 'weekends'
    | 'with-validation';

type ValidationIndexEntry = {
    hasErrors: boolean;
    hasWarnings: boolean;
    entries: ValidationEntry[];
};

type EmployeeWorkloadItem = {
    userId: string;
    employeeName: string;
    shiftsCount: number;
    plannedMinutes: number;
    workedDays: string[];
    workedWeekendStartsOn: string[];
    earlyCount: number;
    lateCount: number;
    nightCount: number;
    otherCount: number;
};

type RosterItem = {
    id: string;
    locationId: string;
    locationName: string | null;
    year: number;
    month: number;
    status: string;
    statusLabel: string;
    isEditable: boolean;
    isPublished: boolean;
    generatedAt: string | null;
    publishedAt: string | null;
    createdByName: string | null;
    shiftsCount: number;
    createdAt: string | null;
    shifts: ShiftItem[];
};

type Props = {
    roster: RosterItem;
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
    calendarDays: CalendarDay[];
    rosterValidationResult: RosterValidationResult | null;
    rosterGenerationResult: RosterGenerationResult | null;
};

function formatDateTime(value: string | null): string {
    if (value === null) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatTime(value: string | null): string {
    if (value === null) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusClass(status: string): string {
    if (status === 'published') {
        return 'bg-green-100 text-green-800';
    }

    if (status === 'locked') {
        return 'bg-gray-200 text-gray-800';
    }

    if (status === 'reviewed') {
        return 'bg-blue-100 text-blue-800';
    }

    if (status === 'generated') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-gray-100 text-gray-700';
}

function isWeekend(day: CalendarDay): boolean {
    return day.weekdayLabel === 'Samstag' || day.weekdayLabel === 'Sonntag';
}

function shiftBadgeClass(code: string | null): string {
    if (code === 'early') {
        return 'border-amber-200 bg-amber-50 text-amber-900';
    }

    if (code === 'late') {
        return 'border-blue-200 bg-blue-50 text-blue-900';
    }

    if (code === 'night') {
        return 'border-indigo-200 bg-indigo-50 text-indigo-900';
    }

    return 'border-gray-200 bg-gray-50 text-gray-800';
}

function minutesBetween(start: string, end: string): number {
    const startsAt = new Date(start);
    const endsAt = new Date(end);
    const diff = endsAt.getTime() - startsAt.getTime();

    if (Number.isNaN(diff) || diff <= 0) {
        return 0;
    }

    return Math.round(diff / 60000);
}

function formatMinutesAsHours(minutes: number): string {
    return `${(minutes / 60).toFixed(1).replace('.', ',')} h`;
}

function formatDateOnly(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function weekendStartsOnForDate(date: string): string | null {
    const shiftDate = new Date(`${date}T00:00:00`);

    if (Number.isNaN(shiftDate.getTime())) {
        return null;
    }

    if (shiftDate.getDay() === 6) {
        return date;
    }

    if (shiftDate.getDay() === 0) {
        const saturday = new Date(shiftDate);
        saturday.setDate(shiftDate.getDate() - 1);

        return formatDateOnly(saturday);
    }

    return null;
}

function buildEmployeeWorkload(shifts: ShiftItem[]): EmployeeWorkloadItem[] {
    const workloadByUser = new Map<
        string,
        Omit<EmployeeWorkloadItem, 'workedDays' | 'workedWeekendStartsOn'> & {
            workedDays: Set<string>;
            workedWeekendStartsOn: Set<string>;
        }
    >();

    shifts.forEach((shift) => {
        const current = workloadByUser.get(shift.userId) ?? {
            userId: shift.userId,
            employeeName: shift.employeeName ?? 'Unbekannt',
            shiftsCount: 0,
            plannedMinutes: 0,
            workedDays: new Set<string>(),
            workedWeekendStartsOn: new Set<string>(),
            earlyCount: 0,
            lateCount: 0,
            nightCount: 0,
            otherCount: 0,
        };

        current.shiftsCount += 1;
        current.plannedMinutes += minutesBetween(shift.startsAt, shift.endsAt);
        current.workedDays.add(shift.date);

        const weekendStartsOn = weekendStartsOnForDate(shift.date);

        if (weekendStartsOn !== null) {
            current.workedWeekendStartsOn.add(weekendStartsOn);
        }

        if (shift.shiftTemplateCode === 'early') {
            current.earlyCount += 1;
        } else if (shift.shiftTemplateCode === 'late') {
            current.lateCount += 1;
        } else if (shift.shiftTemplateCode === 'night') {
            current.nightCount += 1;
        } else {
            current.otherCount += 1;
        }

        workloadByUser.set(shift.userId, current);
    });

    return Array.from(workloadByUser.values())
        .map((item) => ({
            ...item,
            workedDays: Array.from(item.workedDays).sort(),
            workedWeekendStartsOn: Array.from(item.workedWeekendStartsOn).sort(),
        }))
        .sort((a, b) => {
            if (b.plannedMinutes !== a.plannedMinutes) {
                return b.plannedMinutes - a.plannedMinutes;
            }

            return a.employeeName.localeCompare(b.employeeName, 'de');
        });
}

function shiftCountsLabel(item: EmployeeWorkloadItem): string {
    const parts = [`F: ${item.earlyCount}`, `S: ${item.lateCount}`, `N: ${item.nightCount}`];

    if (item.otherCount > 0) {
        parts.push(`Sonstige: ${item.otherCount}`);
    }

    return parts.join(' · ');
}

const validationDateContextKeys = [
    'date',
    'previousShiftDate',
    'nextShiftDate',
    'startsOn',
    'endsOn',
    'sunday',
    'absenceStartsOn',
    'absenceEndsOn',
    'compensationWindowStartsOn',
    'compensationWindowEndsOn',
];

function validationDatesFromContext(context: Record<string, unknown>): string[] {
    const dates = new Set<string>();

    validationDateContextKeys.forEach((key) => {
        const value = context[key];

        if (typeof value === 'string') {
            dates.add(value);
        }
    });

    return Array.from(dates);
}

function buildValidationDayIndex(
    validationResult: RosterValidationResult | null,
    rosterId: string,
): Record<string, ValidationIndexEntry> {
    if (validationResult === null || validationResult.rosterId !== rosterId) {
        return {};
    }

    const dayIndex: Record<string, ValidationIndexEntry> = {};

    const addEntry = (entry: ValidationEntry, hasError: boolean) => {
        validationDatesFromContext(entry.context).forEach((date) => {
            dayIndex[date] ??= {
                hasErrors: false,
                hasWarnings: false,
                entries: [],
            };

            if (hasError) {
                dayIndex[date].hasErrors = true;
            } else {
                dayIndex[date].hasWarnings = true;
            }

            dayIndex[date].entries.push(entry);
        });
    };

    validationResult.errors.forEach((entry) => addEntry(entry, true));
    validationResult.warnings.forEach((entry) => addEntry(entry, false));

    return dayIndex;
}

function buildValidationUserIndex(
    validationResult: RosterValidationResult | null,
    rosterId: string,
): Record<string, ValidationIndexEntry> {
    if (validationResult === null || validationResult.rosterId !== rosterId) {
        return {};
    }

    const userIndex: Record<string, ValidationIndexEntry> = {};

    const addEntry = (entry: ValidationEntry, hasError: boolean) => {
        const userId = entry.context.userId;

        if (typeof userId !== 'string') {
            return;
        }

        userIndex[userId] ??= {
            hasErrors: false,
            hasWarnings: false,
            entries: [],
        };

        if (hasError) {
            userIndex[userId].hasErrors = true;
        } else {
            userIndex[userId].hasWarnings = true;
        }

        userIndex[userId].entries.push(entry);
    };

    validationResult.errors.forEach((entry) => addEntry(entry, true));
    validationResult.warnings.forEach((entry) => addEntry(entry, false));

    return userIndex;
}

function MonthOverview({
    calendarDays,
    validationResult,
    rosterId,
}: {
    calendarDays: CalendarDay[];
    validationResult: RosterValidationResult | null;
    rosterId: string;
}) {
    const [filter, setFilter] = useState<MonthOverviewFilter>('all');
    const validationDayIndex = buildValidationDayIndex(validationResult, rosterId);

    const filterOptions: Array<{
        value: MonthOverviewFilter;
        label: string;
        count: number;
    }> = [
        {
            value: 'all',
            label: 'Alle Tage',
            count: calendarDays.length,
        },
        {
            value: 'with-shifts',
            label: 'Mit Diensten',
            count: calendarDays.filter((day) => day.shifts.length > 0).length,
        },
        {
            value: 'without-shifts',
            label: 'Ohne Dienste',
            count: calendarDays.filter((day) => day.shifts.length === 0).length,
        },
        {
            value: 'weekends',
            label: 'Wochenenden',
            count: calendarDays.filter((day) => isWeekend(day)).length,
        },
        {
            value: 'with-validation',
            label: 'Mit Problemen',
            count: calendarDays.filter((day) => Boolean(validationDayIndex[day.date]))
                .length,
        },
    ];

    const filteredDays = calendarDays.filter((day) => {
        if (filter === 'with-shifts') {
            return day.shifts.length > 0;
        }

        if (filter === 'without-shifts') {
            return day.shifts.length === 0;
        }

        if (filter === 'weekends') {
            return isWeekend(day);
        }

        if (filter === 'with-validation') {
            return Boolean(validationDayIndex[day.date]);
        }

        return true;
    });

    return (
        <div>
            <div className="mb-4 flex flex-wrap gap-2">
                {filterOptions.map((option) => {
                    const active = filter === option.value;

                    return (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => setFilter(option.value)}
                            className={`rounded-md border px-3 py-1.5 text-sm font-semibold transition ${
                                active
                                    ? 'border-[#9B1C3B] bg-[#9B1C3B] text-white shadow-sm'
                                    : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                            }`}
                        >
                            {option.label} ({option.count})
                        </button>
                    );
                })}
            </div>

            {filteredDays.length === 0 ? (
                <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-600">
                    Für diesen Filter gibt es keine Tage.
                </div>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {filteredDays.map((day) => {
                        const hasShifts = day.shifts.length > 0;
                        const weekend = isWeekend(day);
                        const dayValidation = validationDayIndex[day.date];
                        const hasValidationError = dayValidation?.hasErrors === true;
                        const hasValidationWarning =
                            !hasValidationError && dayValidation?.hasWarnings === true;
                        const validationEntries = dayValidation?.entries ?? [];
                        const visibleValidationEntries = validationEntries.slice(0, 3);
                        const hiddenValidationEntriesCount = Math.max(
                            validationEntries.length - visibleValidationEntries.length,
                            0,
                        );
                        const cardClassName = hasValidationError
                            ? 'border-red-300 bg-red-50/50 text-gray-900 ring-1 ring-red-200 shadow-sm'
                            : hasValidationWarning
                              ? 'border-amber-300 bg-amber-50/50 text-gray-900 ring-1 ring-amber-200 shadow-sm'
                              : hasShifts
                                ? 'border-gray-300 bg-white shadow-sm'
                                : 'border-gray-100 bg-gray-50/70 text-gray-500';

                        return (
                            <div
                                key={day.date}
                                className={`rounded-md border p-3 transition ${cardClassName} ${
                                    weekend && !hasValidationError && !hasValidationWarning
                                        ? 'ring-1 ring-[#9B1C3B]/15'
                                        : ''
                                }`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h4
                                            className={`text-sm font-semibold ${
                                                hasShifts || dayValidation
                                                    ? 'text-gray-900'
                                                    : 'text-gray-500'
                                            }`}
                                        >
                                            {day.dayLabel}
                                        </h4>
                                        <span
                                            className={`mt-0.5 inline-flex text-xs ${
                                                weekend
                                                    ? 'font-semibold text-[#9B1C3B]'
                                                    : 'text-gray-500'
                                            }`}
                                        >
                                            {day.weekdayLabel}
                                        </span>
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
                                        {hasValidationError && (
                                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">
                                                Fehler
                                            </span>
                                        )}
                                        {hasValidationWarning && (
                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                                Hinweis
                                            </span>
                                        )}
                                        {hasShifts && (
                                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                                {day.shifts.length}
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {dayValidation && (
                                    <ul className="mt-3 space-y-1 rounded-md bg-white/70 p-2 text-xs">
                                        {visibleValidationEntries.map((entry, index) => (
                                            <li
                                                key={`${entry.code}-${index}`}
                                                className={
                                                    hasValidationError
                                                        ? 'text-red-900'
                                                        : 'text-amber-900'
                                                }
                                            >
                                                {entry.title ?? entry.message}
                                            </li>
                                        ))}
                                        {hiddenValidationEntriesCount > 0 && (
                                            <li className="font-medium text-gray-600">
                                                + {hiddenValidationEntriesCount} weitere
                                            </li>
                                        )}
                                    </ul>
                                )}

                                {hasShifts ? (
                                    <ul className="mt-3 space-y-2">
                                        {day.shifts.map((shift) => (
                                            <li
                                                key={shift.id}
                                                className={`rounded-md border px-2.5 py-2 text-xs ${shiftBadgeClass(
                                                    shift.shiftTemplateCode,
                                                )}`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <span className="font-semibold">
                                                        {shift.shiftTemplateName ??
                                                            shift.shiftTemplateCode ??
                                                            'Unbekannte Schicht'}
                                                    </span>
                                                    {shift.shiftTemplateCode && (
                                                        <span className="shrink-0 rounded bg-white/60 px-1.5 py-0.5 font-mono text-[0.65rem] uppercase">
                                                            {shift.shiftTemplateCode}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-1 font-medium">
                                                    {formatTime(shift.startsAt)} bis{' '}
                                                    {formatTime(shift.endsAt)}
                                                </div>
                                                <div className="mt-1">
                                                    {shift.employeeName ?? 'Unbekannt'}
                                                </div>
                                                {shift.note && (
                                                    <div className="mt-1 border-t border-current/10 pt-1 opacity-80">
                                                        {shift.note}
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-3 text-xs">Keine Dienste</p>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function RosterActions({ roster }: { roster: RosterItem }) {
    const patch = (routeName: string) => {
        router.patch(route(routeName, roster.id), {}, { preserveScroll: true });
    };

    const validate = () => {
        router.post(route('rosters.validate', roster.id), {}, { preserveScroll: true });
    };

    const generate = () => {
        if (
            window.confirm(
                'Automatisch erzeugte Dienste werden ersetzt. Manuelle Dienste bleiben erhalten. Fortfahren?',
            )
        ) {
            router.post(route('rosters.generate', roster.id), {}, { preserveScroll: true });
        }
    };

    return (
        <div className="flex flex-wrap gap-2">
            <button
                type="button"
                onClick={validate}
                className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
            >
                Dienstplan prüfen
            </button>

            {roster.isEditable && (
                <>
                    <button
                        type="button"
                        onClick={generate}
                        className="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 transition hover:bg-blue-100"
                    >
                        Automatisch planen
                    </button>
                    <button
                        type="button"
                        onClick={() => patch('rosters.publish')}
                        className="rounded-md border border-transparent bg-[#9B1C3B] px-3 py-2 text-sm font-semibold text-white transition hover:bg-[#7f1730]"
                    >
                        Veröffentlichen
                    </button>
                </>
            )}

            {roster.status === 'published' && (
                <>
                    <button
                        type="button"
                        onClick={() => patch('rosters.lock')}
                        className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Sperren
                    </button>
                    <button
                        type="button"
                        onClick={() => patch('rosters.reopen')}
                        className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Wieder öffnen
                    </button>
                </>
            )}

            {roster.status === 'locked' && (
                <span className="py-2 text-sm text-gray-500">Gesperrt</span>
            )}
        </div>
    );
}

function GenerationResult({ result }: { result: RosterGenerationResult | null }) {
    if (result === null) {
        return null;
    }

    const visibleSkipped = result.skipped.slice(0, 5);
    const hiddenSkippedCount = Math.max(result.skipped.length - visibleSkipped.length, 0);

    return (
        <div className="rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
            <p className="font-semibold">Automatische Planung abgeschlossen</p>
            <div className="mt-2 space-y-1">
                <p>Erstellt: {result.createdShifts} Dienste</p>
                <p>Ersetzte Auto-Dienste: {result.deletedAutoShifts}</p>
            </div>

            {result.skipped.length > 0 && (
                <div className="mt-3 border-t border-blue-200 pt-3">
                    <p className="font-medium">
                        Einige Dienste konnten nicht vollständig besetzt werden.
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        {visibleSkipped.map((entry, index) => (
                            <li key={`${entry.code}-${index}`}>{entry.message}</li>
                        ))}
                        {hiddenSkippedCount > 0 && <li>+ {hiddenSkippedCount} weitere</li>}
                    </ul>
                </div>
            )}
        </div>
    );
}

function ValidationResult({
    roster,
    validationResult,
}: {
    roster: RosterItem;
    validationResult: RosterValidationResult | null;
}) {
    if (validationResult?.rosterId !== roster.id) {
        return null;
    }

    return (
        <div
            className={`rounded-md border p-4 text-sm ${
                validationResult.status === 'red'
                    ? 'border-red-200 bg-red-50 text-red-900'
                    : validationResult.status === 'yellow'
                      ? 'border-amber-200 bg-amber-50 text-amber-900'
                      : 'border-green-200 bg-green-50 text-green-900'
            }`}
        >
            <p className="font-semibold">
                {validationResult.status === 'red' && 'Der Dienstplan hat Fehler.'}
                {validationResult.status === 'yellow' && 'Der Dienstplan hat Hinweise.'}
                {validationResult.status === 'green' &&
                    'Der Dienstplan erfüllt alle aktuell geprüften Regeln.'}
            </p>

            {(validationResult.errors.length > 0 ||
                validationResult.warnings.length > 0) && (
                <div className="mt-3 space-y-3">
                    {validationResult.errors.length > 0 && (
                        <EntryList title="Fehler" entries={validationResult.errors} />
                    )}
                    {validationResult.warnings.length > 0 && (
                        <EntryList title="Hinweise" entries={validationResult.warnings} />
                    )}
                </div>
            )}
        </div>
    );
}


const validationContextLabels: Array<[string, string]> = [
    ['employeeName', 'Mitarbeiter'],
    ['shiftTemplateName', 'Schicht'],
    ['previousShiftTemplateName', 'Vorherige Schicht'],
    ['nextShiftTemplateName', 'Nächste Schicht'],
    ['date', 'Datum'],
    ['previousShiftDate', 'Vorheriges Datum'],
    ['nextShiftDate', 'Nächstes Datum'],
    ['previousShiftEndsAt', 'Vorheriger Dienst endet'],
    ['nextShiftStartsAt', 'Nächster Dienst beginnt'],
    ['absenceStartsOn', 'Abwesenheit von'],
    ['absenceEndsOn', 'Abwesenheit bis'],
    ['plannedMinutes', 'Geplante Minuten'],
    ['targetMinutes', 'Soll-Minuten'],
    ['overtimeMinutes', 'Über Soll'],
    ['restMinutes', 'Ruhezeit Minuten'],
    ['requiredRestMinutes', 'Erforderliche Ruhezeit Minuten'],
    ['workedWeekends', 'Geplante Wochenenden'],
    ['maxAllowedWeekends', 'Empfohlene maximale Wochenenden'],
    ['consecutiveDays', 'Tage am Stück'],
    ['maxAllowedConsecutiveDays', 'Empfohlene maximale Tage am Stück'],
    ['workedDays', 'Gearbeitete Tage'],
    ['daysInMonth', 'Tage im Monat'],
    ['startsOn', 'Start'],
    ['endsOn', 'Ende'],
    ['sunday', 'Sonntag'],
    ['compensationWindowStartsOn', 'Ausgleichszeitraum von'],
    ['compensationWindowEndsOn', 'Ausgleichszeitraum bis'],
];

function formatValidationContextValue(value: unknown): string {
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'boolean') {
        return value ? 'Ja' : 'Nein';
    }

    if (Array.isArray(value)) {
        if (
            value.every(
                (item) => typeof item === 'string' || typeof item === 'number',
            )
        ) {
            return value.join(', ');
        }

        return JSON.stringify(value);
    }

    return JSON.stringify(value);
}

function ValidationContextSummary({ context }: { context: Record<string, unknown> }) {
    const entries = validationContextLabels
        .filter(([key]) => context[key] !== undefined && context[key] !== null)
        .map(([key, label]) => ({
            key,
            label,
            value: formatValidationContextValue(context[key]),
        }));

    const weekendStartsOn = context.weekendStartsOn;

    if (Array.isArray(weekendStartsOn)) {
        const weekends = weekendStartsOn.filter(
            (item): item is string | number =>
                typeof item === 'string' || typeof item === 'number',
        );

        if (weekends.length > 0) {
            entries.push({
                key: 'weekendStartsOn',
                label: 'Wochenenden',
                value: weekends.join(', '),
            });
        }
    }

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="mt-3 grid gap-2 rounded bg-white/50 p-3 text-xs sm:grid-cols-2">
            {entries.map((entry) => (
                <div key={entry.key}>
                    <dt className="font-semibold opacity-75">{entry.label}</dt>
                    <dd className="mt-0.5 break-words">{entry.value}</dd>
                </div>
            ))}
        </dl>
    );
}

function EntryList({ title, entries }: { title: string; entries: ValidationEntry[] }) {
    return (
        <div>
            <p className="font-medium">{title}</p>
            <ul className="mt-1 space-y-2">
                {entries.map((entry, index) => (
                    <li key={`${entry.code}-${index}`} className="rounded bg-white/60 p-3">
                        <p className="font-semibold">{entry.title ?? entry.message}</p>
                        <p className="mt-1">{entry.details ?? entry.message}</p>
                        <p className="mt-2 text-xs opacity-75">Code: {entry.code}</p>
                        <ValidationContextSummary context={entry.context} />
                        <details className="mt-2">
                            <summary className="cursor-pointer text-xs font-medium opacity-75">
                                Technische Details anzeigen
                            </summary>
                            <pre className="mt-2 overflow-x-auto rounded bg-white/80 p-2 text-xs">
                                {JSON.stringify(entry.context, null, 2)}
                            </pre>
                        </details>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function EmployeeWorkloadOverview({
    roster,
    validationResult,
    rosterId,
}: {
    roster: RosterItem;
    validationResult: RosterValidationResult | null;
    rosterId: string;
}) {
    const workload = buildEmployeeWorkload(roster.shifts);
    const validationUserIndex = buildValidationUserIndex(validationResult, rosterId);

    return (
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div className="border-b border-gray-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900">
                    Mitarbeiter-Auslastung
                </h3>
            </div>
            <div className="p-6">
                {workload.length === 0 ? (
                    <p className="text-sm text-gray-600">
                        Noch keine Auslastung vorhanden.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">Mitarbeiter</th>
                                    <th className="px-4 py-3">Dienste</th>
                                    <th className="px-4 py-3">Stunden</th>
                                    <th className="px-4 py-3">Arbeitstage</th>
                                    <th className="px-4 py-3">Wochenenden</th>
                                    <th className="px-4 py-3">Schichten</th>
                                    <th className="px-4 py-3">Prüfung</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 bg-white text-gray-700">
                                {workload.map((item) => {
                                    const userValidation = validationUserIndex[item.userId];
                                    const hasValidationError =
                                        userValidation?.hasErrors === true;
                                    const hasValidationWarning =
                                        !hasValidationError &&
                                        userValidation?.hasWarnings === true;
                                    const validationEntries = userValidation?.entries ?? [];
                                    const visibleValidationEntries = validationEntries.slice(
                                        0,
                                        2,
                                    );
                                    const hiddenValidationEntriesCount = Math.max(
                                        validationEntries.length -
                                            visibleValidationEntries.length,
                                        0,
                                    );

                                    return (
                                        <tr
                                            key={item.userId}
                                            className={
                                                hasValidationError
                                                    ? 'bg-red-50/40'
                                                    : hasValidationWarning
                                                      ? 'bg-amber-50/40'
                                                      : undefined
                                            }
                                        >
                                            <td className="px-4 py-3 font-medium text-gray-900">
                                                {item.employeeName}
                                            </td>
                                            <td className="px-4 py-3">
                                                {item.shiftsCount}
                                            </td>
                                            <td className="px-4 py-3">
                                                {formatMinutesAsHours(item.plannedMinutes)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {item.workedDays.length}
                                            </td>
                                            <td className="px-4 py-3">
                                                {item.workedWeekendStartsOn.length}
                                            </td>
                                            <td className="px-4 py-3">
                                                {shiftCountsLabel(item)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {userValidation ? (
                                                    <div className="space-y-1">
                                                        <span
                                                            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                                hasValidationError
                                                                    ? 'bg-red-100 text-red-800'
                                                                    : 'bg-amber-100 text-amber-800'
                                                            }`}
                                                        >
                                                            {hasValidationError
                                                                ? 'Fehler'
                                                                : 'Hinweise'}
                                                        </span>
                                                        <ul className="space-y-0.5 text-xs text-gray-600">
                                                            {visibleValidationEntries.map(
                                                                (entry, index) => (
                                                                    <li
                                                                        key={`${entry.code}-${index}`}
                                                                    >
                                                                        {entry.title ??
                                                                            entry.message}
                                                                    </li>
                                                                ),
                                                            )}
                                                            {hiddenValidationEntriesCount >
                                                                0 && (
                                                                <li className="font-medium">
                                                                    +{' '}
                                                                    {hiddenValidationEntriesCount}{' '}
                                                                    weitere
                                                                </li>
                                                            )}
                                                        </ul>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-gray-400">
                                                        OK
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

function ShiftForm({
    roster,
    employees,
    shiftTemplates,
}: {
    roster: RosterItem;
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
}) {
    const rosterEmployees = employees.filter(
        (employee) => employee.locationId === null || employee.locationId === roster.locationId,
    );

    const form = useForm({
        user_id: rosterEmployees[0]?.id ?? '',
        shift_template_id: shiftTemplates[0]?.id ?? '',
        date: '',
        note: '',
    });

    if (!roster.isEditable) {
        return (
            <div className="p-6 text-sm text-gray-600">
                Dieser Dienstplan ist nicht mehr bearbeitbar.
            </div>
        );
    }

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('rosters.shifts.store', roster.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('date', 'note'),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 p-6 lg:grid-cols-5 lg:items-end">
            <div>
                <InputLabel htmlFor="user_id" value="Mitarbeiter" />
                <select
                    id="user_id"
                    value={form.data.user_id}
                    onChange={(event) => form.setData('user_id', event.target.value)}
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                >
                    <option value="">Bitte wählen</option>
                    {rosterEmployees.map((employee) => (
                        <option key={employee.id} value={employee.id}>
                            {employee.name}
                            {employee.isNursingSpecialist ? ' · Fachkraft' : ''}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.user_id} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="shift_template_id" value="Schicht" />
                <select
                    id="shift_template_id"
                    value={form.data.shift_template_id}
                    onChange={(event) =>
                        form.setData('shift_template_id', event.target.value)
                    }
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                >
                    <option value="">Bitte wählen</option>
                    {shiftTemplates.map((shiftTemplate) => (
                        <option key={shiftTemplate.id} value={shiftTemplate.id}>
                            {shiftTemplate.name} · {shiftTemplate.startsAt}-
                            {shiftTemplate.endsAt}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.shift_template_id} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="date" value="Datum" />
                <TextInput
                    id="date"
                    type="date"
                    value={form.data.date}
                    onChange={(event) => form.setData('date', event.target.value)}
                    className="mt-1 block w-full"
                />
                <InputError message={form.errors.date} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="note" value="Notiz" />
                <TextInput
                    id="note"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    className="mt-1 block w-full"
                />
                <InputError message={form.errors.note} className="mt-2" />
            </div>

            <div className="flex justify-end">
                <PrimaryButton disabled={form.processing}>Dienst hinzufügen</PrimaryButton>
            </div>
        </form>
    );
}

function deleteShift(roster: RosterItem, shift: ShiftItem): void {
    if (!window.confirm('Diesen Dienst wirklich löschen?')) {
        return;
    }

    router.delete(route('rosters.shifts.destroy', [roster.id, shift.id]), {
        preserveScroll: true,
    });
}

function ShiftEditForm({
    roster,
    shift,
    employees,
    shiftTemplates,
    onCancel,
}: {
    roster: RosterItem;
    shift: ShiftItem;
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
    onCancel: () => void;
}) {
    const rosterEmployees = employees.filter(
        (employee) => employee.locationId === null || employee.locationId === roster.locationId,
    );
    const form = useForm({
        user_id: shift.userId,
        shift_template_id: shift.shiftTemplateId,
        date: shift.date,
        note: shift.note ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.patch(route('rosters.shifts.update', [roster.id, shift.id]), {
            preserveScroll: true,
            onSuccess: onCancel,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-3 grid gap-4 rounded-md border border-gray-200 bg-gray-50 p-4 md:grid-cols-2 lg:grid-cols-5 lg:items-end"
        >
            <div>
                <InputLabel htmlFor={`edit-user-${shift.id}`} value="Mitarbeiter" />
                <select
                    id={`edit-user-${shift.id}`}
                    value={form.data.user_id}
                    onChange={(event) => form.setData('user_id', event.target.value)}
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                >
                    <option value="">Bitte wählen</option>
                    {rosterEmployees.map((employee) => (
                        <option key={employee.id} value={employee.id}>
                            {employee.name}
                            {employee.isNursingSpecialist ? ' · Fachkraft' : ''}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.user_id} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`edit-shift-template-${shift.id}`} value="Schicht" />
                <select
                    id={`edit-shift-template-${shift.id}`}
                    value={form.data.shift_template_id}
                    onChange={(event) =>
                        form.setData('shift_template_id', event.target.value)
                    }
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                >
                    <option value="">Bitte wählen</option>
                    {shiftTemplates.map((shiftTemplate) => (
                        <option key={shiftTemplate.id} value={shiftTemplate.id}>
                            {shiftTemplate.name} · {shiftTemplate.startsAt}-
                            {shiftTemplate.endsAt}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.shift_template_id} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`edit-date-${shift.id}`} value="Datum" />
                <TextInput
                    id={`edit-date-${shift.id}`}
                    type="date"
                    value={form.data.date}
                    onChange={(event) => form.setData('date', event.target.value)}
                    className="mt-1 block w-full"
                />
                <InputError message={form.errors.date} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`edit-note-${shift.id}`} value="Notiz" />
                <TextInput
                    id={`edit-note-${shift.id}`}
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    className="mt-1 block w-full"
                />
                <InputError message={form.errors.note} className="mt-2" />
            </div>

            <div className="flex gap-2 lg:justify-end">
                <PrimaryButton disabled={form.processing}>Speichern</PrimaryButton>
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    Abbrechen
                </button>
            </div>
        </form>
    );
}

function ShiftList({
    roster,
    employees,
    shiftTemplates,
}: {
    roster: RosterItem;
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
}) {
    const [editingShiftId, setEditingShiftId] = useState<string | null>(null);

    if (roster.shifts.length === 0) {
        return (
            <p className="mt-3 text-sm text-gray-600">
                Für diesen Monatsdienstplan sind noch keine Dienste eingetragen.
            </p>
        );
    }

    return (
        <ul className="mt-3 divide-y divide-gray-200">
            {roster.shifts.map((shift) => (
                <li
                    key={shift.id}
                    className="py-3 text-sm text-gray-700"
                >
                    <div className="grid gap-2 md:grid-cols-[1fr_1.4fr_1.2fr_2fr_auto] md:items-center">
                        <span className="font-medium text-gray-900">{shift.date}</span>
                        <span>
                            {shift.shiftTemplateName ?? 'Unbekannte Schicht'}{' '}
                            {shift.shiftTemplateCode ? `(${shift.shiftTemplateCode})` : ''}
                        </span>
                        <span>{shift.employeeName ?? 'Unbekannt'}</span>
                        <span className="text-gray-500">
                            {formatDateTime(shift.startsAt)} bis{' '}
                            {formatDateTime(shift.endsAt)}
                            {shift.note ? ` · ${shift.note}` : ''}
                        </span>
                        {roster.isEditable && (
                            <span className="flex gap-2 md:justify-end">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setEditingShiftId(
                                            editingShiftId === shift.id ? null : shift.id,
                                        )
                                    }
                                    className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                                >
                                    Bearbeiten
                                </button>
                                <button
                                    type="button"
                                    onClick={() => deleteShift(roster, shift)}
                                    className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                >
                                    Löschen
                                </button>
                            </span>
                        )}
                    </div>

                    {editingShiftId === shift.id && (
                        <ShiftEditForm
                            roster={roster}
                            shift={shift}
                            employees={employees}
                            shiftTemplates={shiftTemplates}
                            onCancel={() => setEditingShiftId(null)}
                        />
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function RosterShow({
    roster,
    employees,
    shiftTemplates,
    calendarDays,
    rosterValidationResult,
    rosterGenerationResult,
}: Props) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dienstplan
                </h2>
            }
        >
            <Head title="Dienstplan" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <Link
                        href={route('rosters.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Übersicht
                    </Link>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">
                                        {roster.locationName ?? 'Unbekannter Wohnbereich'}
                                    </p>
                                    <h3 className="mt-1 text-lg font-semibold text-gray-900">
                                        {roster.month}/{roster.year}
                                    </h3>
                                    <p className="mt-2 text-sm text-gray-600">
                                        Erstellt von {roster.createdByName ?? '-'} ·{' '}
                                        {roster.shiftsCount} Dienste
                                    </p>
                                </div>
                                <div className="space-y-3">
                                    <span
                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(roster.status)}`}
                                    >
                                        {roster.statusLabel}
                                    </span>
                                    <RosterActions roster={roster} />
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-4 p-6 text-sm text-gray-700 md:grid-cols-3">
                            <div>
                                <span className="block font-medium text-gray-900">
                                    Angelegt am
                                </span>
                                {formatDateTime(roster.createdAt)}
                            </div>
                            <div>
                                <span className="block font-medium text-gray-900">
                                    Veröffentlicht am
                                </span>
                                {formatDateTime(roster.publishedAt)}
                            </div>
                            <div>
                                <span className="block font-medium text-gray-900">
                                    Bearbeitbar
                                </span>
                                {roster.isEditable ? 'Ja' : 'Nein'}
                            </div>
                        </div>
                    </div>

                    <GenerationResult result={rosterGenerationResult} />

                    <ValidationResult
                        roster={roster}
                        validationResult={rosterValidationResult}
                    />

                    <EmployeeWorkloadOverview
                        roster={roster}
                        validationResult={rosterValidationResult}
                        rosterId={roster.id}
                    />

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Dienst hinzufügen
                            </h3>
                        </div>
                        <ShiftForm
                            roster={roster}
                            employees={employees}
                            shiftTemplates={shiftTemplates}
                        />
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Monatsübersicht
                            </h3>
                        </div>
                        <div className="p-6">
                            <MonthOverview
                                calendarDays={calendarDays}
                                validationResult={rosterValidationResult}
                                rosterId={roster.id}
                            />
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Eingetragene Dienste
                            </h3>
                        </div>
                        <div className="p-6">
                            <ShiftList
                                roster={roster}
                                employees={employees}
                                shiftTemplates={shiftTemplates}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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
};

type RosterValidationResult = {
    rosterId: string;
    status: 'green' | 'yellow' | 'red';
    errors: ValidationEntry[];
    warnings: ValidationEntry[];
};

type CalendarDay = {
    date: string;
    dayLabel: string;
    weekdayLabel: string;
    shifts: ShiftItem[];
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

function MonthOverview({ calendarDays }: { calendarDays: CalendarDay[] }) {
    return (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {calendarDays.map((day) => (
                <div key={day.date} className="rounded-md border border-gray-200 p-4">
                    <div className="flex items-baseline justify-between gap-3">
                        <h4 className="font-semibold text-gray-900">{day.dayLabel}</h4>
                        <span className="text-sm text-gray-500">{day.weekdayLabel}</span>
                    </div>

                    {day.shifts.length === 0 ? (
                        <p className="mt-3 text-sm text-gray-500">Keine Dienste</p>
                    ) : (
                        <ul className="mt-3 space-y-3">
                            {day.shifts.map((shift) => (
                                <li key={shift.id} className="text-sm text-gray-700">
                                    <div className="font-medium text-gray-900">
                                        {shift.shiftTemplateName ?? 'Unbekannte Schicht'}{' '}
                                        {shift.shiftTemplateCode
                                            ? `(${shift.shiftTemplateCode})`
                                            : ''}
                                    </div>
                                    <div>{shift.employeeName ?? 'Unbekannt'}</div>
                                    <div className="text-gray-500">
                                        {formatTime(shift.startsAt)} bis{' '}
                                        {formatTime(shift.endsAt)}
                                    </div>
                                    {shift.note && (
                                        <div className="mt-1 text-gray-500">{shift.note}</div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ))}
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
                <button
                    type="button"
                    onClick={() => patch('rosters.publish')}
                    className="rounded-md border border-transparent bg-[#9B1C3B] px-3 py-2 text-sm font-semibold text-white transition hover:bg-[#7f1730]"
                >
                    Veröffentlichen
                </button>
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

function EntryList({ title, entries }: { title: string; entries: ValidationEntry[] }) {
    return (
        <div>
            <p className="font-medium">{title}</p>
            <ul className="mt-1 space-y-2">
                {entries.map((entry, index) => (
                    <li key={`${entry.code}-${index}`}>
                        <span className="font-mono text-xs">{entry.code}</span>{' '}
                        {entry.message}
                        <pre className="mt-1 overflow-x-auto rounded bg-white/70 p-2 text-xs">
                            {JSON.stringify(entry.context, null, 2)}
                        </pre>
                    </li>
                ))}
            </ul>
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

function ShiftList({ roster }: { roster: RosterItem }) {
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
                    className="grid gap-2 py-3 text-sm text-gray-700 md:grid-cols-[1fr_1.4fr_1.2fr_2fr_auto] md:items-center"
                >
                    <span className="font-medium text-gray-900">{shift.date}</span>
                    <span>
                        {shift.shiftTemplateName ?? 'Unbekannte Schicht'}{' '}
                        {shift.shiftTemplateCode ? `(${shift.shiftTemplateCode})` : ''}
                    </span>
                    <span>{shift.employeeName ?? 'Unbekannt'}</span>
                    <span className="text-gray-500">
                        {formatDateTime(shift.startsAt)} bis {formatDateTime(shift.endsAt)}
                        {shift.note ? ` · ${shift.note}` : ''}
                    </span>
                    {roster.isEditable && (
                        <span className="flex justify-start md:justify-end">
                            <button
                                type="button"
                                onClick={() => deleteShift(roster, shift)}
                                className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                            >
                                Löschen
                            </button>
                        </span>
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

                    <ValidationResult
                        roster={roster}
                        validationResult={rosterValidationResult}
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
                            <MonthOverview calendarDays={calendarDays} />
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Eingetragene Dienste
                            </h3>
                        </div>
                        <div className="p-6">
                            <ShiftList roster={roster} />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

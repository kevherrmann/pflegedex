import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type LocationOption = {
    id: string;
    name: string;
};

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
    locations: LocationOption[];
    rosters: RosterItem[];
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
    rosterValidationResult: RosterValidationResult | null;
};

const months = [
    { value: 1, label: 'Januar' },
    { value: 2, label: 'Februar' },
    { value: 3, label: 'März' },
    { value: 4, label: 'April' },
    { value: 5, label: 'Mai' },
    { value: 6, label: 'Juni' },
    { value: 7, label: 'Juli' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'Oktober' },
    { value: 11, label: 'November' },
    { value: 12, label: 'Dezember' },
];

function formatDateTime(value: string | null): string {
    if (value === null) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
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

function deleteShift(roster: RosterItem, shift: ShiftItem): void {
    if (!window.confirm('Diesen Dienst wirklich löschen?')) {
        return;
    }

    router.delete(route('rosters.shifts.destroy', [roster.id, shift.id]), {
        preserveScroll: true,
    });
}

function RosterActions({ roster }: { roster: RosterItem }) {
    const patch = (routeName: string) => {
        router.patch(route(routeName, roster.id), {}, { preserveScroll: true });
    };

    const validate = () => {
        router.post(route('rosters.validate', roster.id), {}, { preserveScroll: true });
    };

    if (roster.status === 'locked') {
        return (
            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={validate}
                    className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    Dienstplan prüfen
                </button>
                <span className="py-2 text-sm text-gray-500">Gesperrt</span>
            </div>
        );
    }

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
        </div>
    );
}

function ShiftCreatePanel({
    roster,
    employees,
    shiftTemplates,
    validationResult,
}: {
    roster: RosterItem;
    employees: EmployeeOption[];
    shiftTemplates: ShiftTemplateOption[];
    validationResult: RosterValidationResult | null;
}) {
    const rosterEmployees = employees.filter(
        (employee) => employee.locationId === null || employee.locationId === roster.locationId,
    );
    const rosterShiftTemplates = shiftTemplates.filter(
        (shiftTemplate) => shiftTemplate.locationId === roster.locationId,
    );
    const form = useForm({
        user_id: rosterEmployees[0]?.id ?? '',
        shift_template_id: rosterShiftTemplates[0]?.id ?? '',
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
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div className="border-b border-gray-200 p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-sm text-gray-500">
                            {roster.locationName ?? 'Unbekannter Wohnbereich'} ·{' '}
                            {roster.month}/{roster.year}
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-gray-900">
                            Dienst hinzufügen
                        </h3>
                    </div>
                    <span
                        className={`w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(roster.status)}`}
                    >
                        {roster.statusLabel}
                    </span>
                </div>
            </div>

            <form onSubmit={submit} className="grid gap-4 p-6 lg:grid-cols-5 lg:items-end">
                <div>
                    <InputLabel htmlFor={`user_id_${roster.id}`} value="Mitarbeiter" />
                    <select
                        id={`user_id_${roster.id}`}
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
                    <InputLabel
                        htmlFor={`shift_template_id_${roster.id}`}
                        value="Schicht"
                    />
                    <select
                        id={`shift_template_id_${roster.id}`}
                        value={form.data.shift_template_id}
                        onChange={(event) =>
                            form.setData('shift_template_id', event.target.value)
                        }
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                    >
                        <option value="">Bitte wählen</option>
                        {rosterShiftTemplates.map((shiftTemplate) => (
                            <option key={shiftTemplate.id} value={shiftTemplate.id}>
                                {shiftTemplate.name} · {shiftTemplate.startsAt}-
                                {shiftTemplate.endsAt}
                            </option>
                        ))}
                    </select>
                    <InputError
                        message={form.errors.shift_template_id}
                        className="mt-2"
                    />
                </div>

                <div>
                    <InputLabel htmlFor={`date_${roster.id}`} value="Datum" />
                    <TextInput
                        id={`date_${roster.id}`}
                        type="date"
                        value={form.data.date}
                        onChange={(event) => form.setData('date', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={form.errors.date} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor={`note_${roster.id}`} value="Notiz" />
                    <TextInput
                        id={`note_${roster.id}`}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={form.errors.note} className="mt-2" />
                </div>

                <div className="flex justify-end">
                    <PrimaryButton disabled={form.processing}>
                        Dienst hinzufügen
                    </PrimaryButton>
                </div>
            </form>

            <div className="border-t border-gray-200 p-6">
                {validationResult?.rosterId === roster.id && (
                    <div
                        className={`mb-6 rounded-md border p-4 text-sm ${
                            validationResult.status === 'red'
                                ? 'border-red-200 bg-red-50 text-red-900'
                                : validationResult.status === 'yellow'
                                  ? 'border-amber-200 bg-amber-50 text-amber-900'
                                  : 'border-green-200 bg-green-50 text-green-900'
                        }`}
                    >
                        <p className="font-semibold">
                            {validationResult.status === 'red' &&
                                'Der Dienstplan hat Fehler.'}
                            {validationResult.status === 'yellow' &&
                                'Der Dienstplan hat Hinweise.'}
                            {validationResult.status === 'green' &&
                                'Der Dienstplan erfüllt alle aktuell geprüften Regeln.'}
                        </p>

                        {(validationResult.errors.length > 0 ||
                            validationResult.warnings.length > 0) && (
                            <div className="mt-3 space-y-3">
                                {validationResult.errors.length > 0 && (
                                    <div>
                                        <p className="font-medium">Fehler</p>
                                        <ul className="mt-1 space-y-2">
                                            {validationResult.errors.map((entry, index) => (
                                                <li key={`${entry.code}-error-${index}`}>
                                                    <span className="font-mono text-xs">
                                                        {entry.code}
                                                    </span>{' '}
                                                    {entry.message}
                                                    <pre className="mt-1 overflow-x-auto rounded bg-white/70 p-2 text-xs">
                                                        {JSON.stringify(
                                                            entry.context,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {validationResult.warnings.length > 0 && (
                                    <div>
                                        <p className="font-medium">Hinweise</p>
                                        <ul className="mt-1 space-y-2">
                                            {validationResult.warnings.map(
                                                (entry, index) => (
                                                    <li
                                                        key={`${entry.code}-warning-${index}`}
                                                    >
                                                        <span className="font-mono text-xs">
                                                            {entry.code}
                                                        </span>{' '}
                                                        {entry.message}
                                                        <pre className="mt-1 overflow-x-auto rounded bg-white/70 p-2 text-xs">
                                                            {JSON.stringify(
                                                                entry.context,
                                                                null,
                                                                2,
                                                            )}
                                                        </pre>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <h4 className="text-base font-semibold text-gray-900">
                    Eingetragene Dienste
                </h4>
                {roster.shifts.length === 0 ? (
                    <p className="mt-3 text-sm text-gray-600">
                        Für diesen Monatsdienstplan sind noch keine Dienste eingetragen.
                    </p>
                ) : (
                    <ul className="mt-3 divide-y divide-gray-200">
                        {roster.shifts.map((shift) => (
                            <li
                                key={shift.id}
                                className="grid gap-2 py-3 text-sm text-gray-700 md:grid-cols-[1fr_1.4fr_1.2fr_2fr_auto] md:items-center"
                            >
                                <span className="font-medium text-gray-900">
                                    {shift.date}
                                </span>
                                <span>
                                    {shift.shiftTemplateName ?? 'Unbekannte Schicht'}{' '}
                                    {shift.shiftTemplateCode
                                        ? `(${shift.shiftTemplateCode})`
                                        : ''}
                                </span>
                                <span>{shift.employeeName ?? 'Unbekannt'}</span>
                                <span className="text-gray-500">
                                    {formatDateTime(shift.startsAt)} bis{' '}
                                    {formatDateTime(shift.endsAt)}
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
                )}
            </div>
        </div>
    );
}

export default function RostersIndex({
    locations,
    rosters,
    employees,
    shiftTemplates,
    rosterValidationResult,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        location_id: locations[0]?.id ?? '',
        year: String(new Date().getFullYear()),
        month: String(new Date().getMonth() + 1),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('rosters.store'), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dienstpläne
                </h2>
            }
        >
            <Head title="Dienstpläne" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Monatsdienstplan anlegen
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Hier erstellst und verwaltest du Monatsdienstpläne.
                            </p>
                        </div>

                        <form onSubmit={submit} className="grid gap-4 p-6 lg:grid-cols-4 lg:items-end">
                            <div>
                                <InputLabel htmlFor="location_id" value="Wohnbereich" />
                                <select
                                    id="location_id"
                                    value={data.location_id}
                                    onChange={(event) =>
                                        setData('location_id', event.target.value)
                                    }
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    <option value="">Bitte wählen</option>
                                    {locations.map((location) => (
                                        <option key={location.id} value={location.id}>
                                            {location.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.location_id}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="year" value="Jahr" />
                                <TextInput
                                    id="year"
                                    type="number"
                                    min="2020"
                                    max="2100"
                                    value={data.year}
                                    onChange={(event) =>
                                        setData('year', event.target.value)
                                    }
                                    className="mt-1 block w-full"
                                />
                                <InputError message={errors.year} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="month" value="Monat" />
                                <select
                                    id="month"
                                    value={data.month}
                                    onChange={(event) =>
                                        setData('month', event.target.value)
                                    }
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    {months.map((month) => (
                                        <option
                                            key={month.value}
                                            value={String(month.value)}
                                        >
                                            {month.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.month} className="mt-2" />
                            </div>

                            <div className="flex justify-end">
                                <PrimaryButton disabled={processing}>
                                    Dienstplan anlegen
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Vorhandene Dienstpläne
                            </h3>
                        </div>

                        <div className="p-6">
                            {rosters.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Es sind noch keine Monatsdienstpläne angelegt.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Wohnbereich
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Monat/Jahr
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Status
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Dienste
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Erstellt von
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Veröffentlicht am
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Aktionen
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {rosters.map((roster) => (
                                                <tr key={roster.id}>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                                                        {roster.locationName ??
                                                            'Unbekannter Wohnbereich'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {roster.month}/{roster.year}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        <span
                                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(roster.status)}`}
                                                        >
                                                            {roster.statusLabel}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {roster.shiftsCount}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {roster.createdByName ?? '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {formatDateTime(
                                                            roster.publishedAt,
                                                        )}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        <RosterActions roster={roster} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>

                    {rosters.length > 0 && (
                        <div className="space-y-6">
                            {rosters.map((roster) => (
                                <ShiftCreatePanel
                                    key={roster.id}
                                    roster={roster}
                                    employees={employees}
                                    shiftTemplates={shiftTemplates}
                                    validationResult={rosterValidationResult}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

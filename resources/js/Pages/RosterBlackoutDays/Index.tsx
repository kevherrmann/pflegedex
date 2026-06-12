import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo } from 'react';

type BlackoutDay = {
    id: string;
    locationName: string | null;
    date: string;
    reason: string | null;
    scope: string;
    scopeLabel: string;
    qualificationLabels: string[];
    employeeNames: string[];
    blocksVacation: boolean;
    blocksOvertimeCompensation: boolean;
    createdByName: string | null;
    createdAt: string | null;
};

type LocationOption = { id: string; name: string };
type QualificationOption = { value: string; label: string };
type StaffOption = {
    id: string;
    name: string;
    locationId: string | null;
    qualificationLabel: string | null;
    areaLabel: string | null;
};

type Props = {
    blackoutDays: BlackoutDay[];
    locations: LocationOption[];
    qualificationLevels: QualificationOption[];
    staff: StaffOption[];
};

export default function RosterBlackoutDaysIndex({
    blackoutDays,
    locations,
    qualificationLevels,
    staff,
}: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        location_id: string;
        date: string;
        reason: string;
        scope: string;
        qualification_levels: string[];
        employee_ids: string[];
        blocks_vacation: boolean;
        blocks_overtime_compensation: boolean;
    }>({
        location_id: locations[0]?.id ?? '',
        date: '',
        reason: '',
        scope: 'all',
        qualification_levels: [],
        employee_ids: [],
        blocks_vacation: true,
        blocks_overtime_compensation: true,
    });

    const staffForLocation = useMemo(
        () => staff.filter((member) => member.locationId === data.location_id),
        [staff, data.location_id],
    );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('roster-blackout-days.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const toggleQualification = (value: string) => {
        setData(
            'qualification_levels',
            data.qualification_levels.includes(value)
                ? data.qualification_levels.filter((item) => item !== value)
                : [...data.qualification_levels, value],
        );
    };

    const toggleEmployee = (id: string) => {
        setData(
            'employee_ids',
            data.employee_ids.includes(id)
                ? data.employee_ids.filter((item) => item !== id)
                : [...data.employee_ids, id],
        );
    };

    const scopeOptions = [
        { value: 'all', label: 'Ganzer Wohnbereich' },
        { value: 'qualification', label: 'Bestimmte Qualifikation' },
        { value: 'employees', label: 'Bestimmte Mitarbeiter' },
    ];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Urlaubssperren
                </h2>
            }
        >
            <Head title="Urlaubssperren" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Neue Urlaubssperre
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Sperrt einzelne Tage je Wohnbereich für Urlaubs- und Überstundenfrei-Anträge.
                                Eine Sperre kann den ganzen Wohnbereich, nur bestimmte Qualifikationen oder
                                einzelne Mitarbeiter betreffen.
                            </p>
                        </div>

                        <form onSubmit={submit} className="space-y-6 p-6">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="location_id" value="Wohnbereich" />
                                    <select
                                        id="location_id"
                                        value={data.location_id}
                                        onChange={(event) => {
                                            const locationId = event.target.value;

                                            setData((current) => ({
                                                ...current,
                                                location_id: locationId,
                                                // Mitarbeiterauswahl gehoert zum Wohnbereich.
                                                employee_ids: [],
                                            }));
                                        }}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    >
                                        <option value="">Bitte wählen</option>
                                        {locations.map((location) => (
                                            <option key={location.id} value={location.id}>
                                                {location.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.location_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="date" value="Datum" />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        value={data.date}
                                        onChange={(event) => setData('date', event.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.date} className="mt-2" />
                                </div>
                            </div>

                            {/* Geltungsbereich */}
                            <div>
                                <InputLabel value="Gilt für" />
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {scopeOptions.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => setData('scope', option.value)}
                                            className={`rounded-full px-4 py-2 text-sm font-semibold ${
                                                data.scope === option.value
                                                    ? 'bg-[#9B1C3B] text-white'
                                                    : 'bg-[#F7E8ED] text-[#7F1730] hover:bg-[#efd3dc]'
                                            }`}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                                <InputError message={errors.scope} className="mt-2" />
                            </div>

                            {data.scope === 'qualification' && (
                                <div className="rounded-md border border-gray-200 bg-gray-50 p-4">
                                    <p className="text-sm font-medium text-gray-900">
                                        Betroffene Qualifikationsstufen
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-3">
                                        {qualificationLevels.map((level) => (
                                            <label
                                                key={level.value}
                                                className="flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={data.qualification_levels.includes(level.value)}
                                                    onChange={() => toggleQualification(level.value)}
                                                    className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                />
                                                {level.label}
                                            </label>
                                        ))}
                                    </div>
                                    <InputError message={errors.qualification_levels} className="mt-2" />
                                </div>
                            )}

                            {data.scope === 'employees' && (
                                <div className="rounded-md border border-gray-200 bg-gray-50 p-4">
                                    <p className="text-sm font-medium text-gray-900">
                                        Betroffene Mitarbeiter
                                        {data.employee_ids.length > 0 ? ` (${data.employee_ids.length})` : ''}
                                    </p>
                                    {!data.location_id ? (
                                        <p className="mt-2 text-sm text-gray-600">
                                            Bitte zuerst einen Wohnbereich wählen.
                                        </p>
                                    ) : staffForLocation.length === 0 ? (
                                        <p className="mt-2 text-sm text-gray-600">
                                            Für diesen Wohnbereich sind keine Mitarbeiter hinterlegt.
                                        </p>
                                    ) : (
                                        <div className="mt-3 grid max-h-64 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                            {staffForLocation.map((member) => (
                                                <label
                                                    key={member.id}
                                                    className="flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={data.employee_ids.includes(member.id)}
                                                        onChange={() => toggleEmployee(member.id)}
                                                        className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                    />
                                                    <span className="truncate">
                                                        {member.name}
                                                        {member.qualificationLabel || member.areaLabel ? (
                                                            <span className="block text-xs text-gray-500">
                                                                {member.qualificationLabel ?? member.areaLabel}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    )}
                                    <InputError message={errors.employee_ids} className="mt-2" />
                                </div>
                            )}

                            <div>
                                <InputLabel htmlFor="reason" value="Grund" />
                                <textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(event) => setData('reason', event.target.value)}
                                    rows={4}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                />
                                <InputError message={errors.reason} className="mt-2" />
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                                    <input
                                        type="checkbox"
                                        checked={data.blocks_vacation}
                                        onChange={(event) => setData('blocks_vacation', event.target.checked)}
                                        className="mt-1 rounded border-gray-300 text-[#9B1C3B] shadow-sm focus:ring-[#9B1C3B]"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-gray-900">
                                            Urlaub blockieren
                                        </span>
                                        <span className="block text-sm text-gray-600">
                                            Neue Urlaubsanträge werden für diesen Tag abgelehnt.
                                        </span>
                                    </span>
                                </label>

                                <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                                    <input
                                        type="checkbox"
                                        checked={data.blocks_overtime_compensation}
                                        onChange={(event) =>
                                            setData('blocks_overtime_compensation', event.target.checked)
                                        }
                                        className="mt-1 rounded border-gray-300 text-[#9B1C3B] shadow-sm focus:ring-[#9B1C3B]"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-gray-900">
                                            Überstundenfrei blockieren
                                        </span>
                                        <span className="block text-sm text-gray-600">
                                            Neue Anträge auf Überstundenfrei werden für diesen Tag abgelehnt.
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div className="flex justify-end">
                                <PrimaryButton disabled={processing}>Sperre anlegen</PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">Vorhandene Sperrtage</h3>
                            <p className="mt-1 text-sm text-gray-600">Die neuesten Einträge stehen oben.</p>
                        </div>

                        <div className="p-6">
                            {blackoutDays.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Es sind noch keine Urlaubssperren angelegt.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Datum
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Wohnbereich
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Gilt für
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Blockiert
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Grund
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Angelegt
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {blackoutDays.map((blackoutDay) => (
                                                <tr key={blackoutDay.id}>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                                                        {blackoutDay.date}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {blackoutDay.locationName ?? 'Unbekannt'}
                                                    </td>
                                                    <td className="px-3 py-4 text-sm text-gray-700">
                                                        <span className="font-medium">{blackoutDay.scopeLabel}</span>
                                                        {blackoutDay.qualificationLabels.length > 0 && (
                                                            <span className="block text-xs text-gray-500">
                                                                {blackoutDay.qualificationLabels.join(', ')}
                                                            </span>
                                                        )}
                                                        {blackoutDay.employeeNames.length > 0 && (
                                                            <span className="block text-xs text-gray-500">
                                                                {blackoutDay.employeeNames.join(', ')}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-4 text-sm text-gray-700">
                                                        <div className="flex flex-wrap gap-2">
                                                            {blackoutDay.blocksVacation && (
                                                                <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                                                    Urlaub
                                                                </span>
                                                            )}
                                                            {blackoutDay.blocksOvertimeCompensation && (
                                                                <span className="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800">
                                                                    Überstundenfrei
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="max-w-md px-3 py-4 text-sm text-gray-700">
                                                        {blackoutDay.reason ?? '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                        {blackoutDay.createdByName ?? 'Unbekannt'}
                                                        {blackoutDay.createdAt ? ` · ${blackoutDay.createdAt}` : ''}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

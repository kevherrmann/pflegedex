import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type LocationOption = {
    id: string;
    name: string;
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
};

type Props = {
    locations: LocationOption[];
    rosters: RosterItem[];
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

function RosterActions({ roster }: { roster: RosterItem }) {
    return (
        <div className="flex flex-wrap gap-2">
            <Link
                href={route('rosters.show', roster.id)}
                className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
            >
                Öffnen
            </Link>
        </div>
    );
}

export default function RostersIndex({ locations, rosters }: Props) {
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
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Dienstpläne</h2>
            }
        >
            <Head title="Dienstpläne" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Monatsdienstplan anlegen
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Hier erstellst und verwaltest du Monatsdienstpläne.
                            </p>
                        </div>

                        <form
                            onSubmit={submit}
                            className="grid gap-4 p-4 sm:p-6 lg:grid-cols-4 lg:items-end"
                        >
                            <div>
                                <InputLabel htmlFor="location_id" value="Wohnbereich" />
                                <select
                                    id="location_id"
                                    value={data.location_id}
                                    onChange={(event) => setData('location_id', event.target.value)}
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
                                <InputLabel htmlFor="year" value="Jahr" />
                                <TextInput
                                    id="year"
                                    type="number"
                                    min="2020"
                                    max="2100"
                                    value={data.year}
                                    onChange={(event) => setData('year', event.target.value)}
                                    className="mt-1 block w-full"
                                />
                                <InputError message={errors.year} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="month" value="Monat" />
                                <select
                                    id="month"
                                    value={data.month}
                                    onChange={(event) => setData('month', event.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    {months.map((month) => (
                                        <option key={month.value} value={String(month.value)}>
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
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Vorhandene Dienstpläne
                            </h3>
                        </div>

                        <div className="p-4 sm:p-6">
                            {rosters.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Es sind noch keine Monatsdienstpläne angelegt.
                                </p>
                            ) : (
                                <>
                                    <div className="hidden overflow-x-auto md:block">
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
                                                            {formatDateTime(roster.publishedAt)}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                            <RosterActions roster={roster} />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <ul className="divide-y divide-gray-200 md:hidden">
                                        {rosters.map((roster) => (
                                            <li key={roster.id} className="space-y-3 py-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <p className="font-medium text-gray-900">
                                                        {roster.locationName ??
                                                            'Unbekannter Wohnbereich'}
                                                    </p>
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(roster.status)}`}
                                                    >
                                                        {roster.statusLabel}
                                                    </span>
                                                </div>
                                                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Monat/Jahr
                                                        </dt>
                                                        <dd className="text-gray-700">
                                                            {roster.month}/{roster.year}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Dienste
                                                        </dt>
                                                        <dd className="text-gray-700">
                                                            {roster.shiftsCount}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Erstellt von
                                                        </dt>
                                                        <dd className="text-gray-700">
                                                            {roster.createdByName ?? '-'}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Veröffentlicht am
                                                        </dt>
                                                        <dd className="text-gray-700">
                                                            {formatDateTime(roster.publishedAt)}
                                                        </dd>
                                                    </div>
                                                </dl>
                                                <div className="flex flex-wrap gap-x-4 gap-y-2 pt-1">
                                                    <RosterActions roster={roster} />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

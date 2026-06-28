import SearchField from '@/Components/SearchField';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Location = {
    id: string;
    name: string;
};

type Resident = {
    id: string;
    fullName: string;
    roomNumber: string | null;
    careLevel: number | null;
    locationName: string | null;
};

type ResidentsIndexProps = {
    location: Location | null;
    locations: Location[];
    residents: Resident[];
};

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function ResidentCard({ resident, showLocation }: { resident: Resident; showLocation: boolean }) {
    const chips = [
        `Zimmer ${resident.roomNumber ?? '—'}`,
        `Pflegegrad ${resident.careLevel ?? '—'}`,
        showLocation && resident.locationName ? resident.locationName : null,
    ].filter((chip): chip is string => Boolean(chip));

    return (
        <Link
            href={route('residents.show', resident.id)}
            className="group flex items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] transition hover:shadow-md hover:ring-[#9B1C3B]/40"
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#F7E8ED] text-sm font-bold text-[#7F1730]">
                {initials(resident.fullName)}
            </span>
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-[#333333]">{resident.fullName}</p>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {chips.map((chip) => (
                        <span
                            key={chip}
                            className="rounded-full bg-[#F8F8F8] px-2 py-0.5 text-xs text-[#54595F] ring-1 ring-[#E5E7EB]"
                        >
                            {chip}
                        </span>
                    ))}
                </div>
            </div>
            <svg
                className="h-5 w-5 shrink-0 self-center text-gray-300 transition group-hover:text-[#9B1C3B]"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={2}
                stroke="currentColor"
            >
                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
        </Link>
    );
}

export default function Index({ location, locations, residents }: ResidentsIndexProps) {
    const canManageResidents = usePage().props.auth.permissions.manageResidents;

    const [query, setQuery] = useState('');
    const [careLevelFilter, setCareLevelFilter] = useState<number | null>(null);

    // Vorhandene Pflegegrade als Filter-Optionen (aufsteigend).
    const careLevels = useMemo(
        () =>
            Array.from(
                new Set(
                    residents
                        .map((resident) => resident.careLevel)
                        .filter((level): level is number => level !== null),
                ),
            ).sort((a, b) => a - b),
        [residents],
    );

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return residents.filter((resident) => {
            const matchesQuery =
                needle === '' ||
                resident.fullName.toLowerCase().includes(needle) ||
                (resident.roomNumber ?? '').toLowerCase().includes(needle) ||
                String(resident.careLevel ?? '').includes(needle) ||
                (resident.locationName ?? '').toLowerCase().includes(needle);

            const matchesCareLevel =
                careLevelFilter === null || resident.careLevel === careLevelFilter;

            return matchesQuery && matchesCareLevel;
        });
    }, [residents, query, careLevelFilter]);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Bewohnerdokumentation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">Bewohner</h2>
                </div>
            }
        >
            <Head title="Bewohner" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <section className="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:mb-8 sm:p-6 lg:p-8">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                    {location?.name ?? 'Alle zugeordneten Wohnbereiche'}
                                </p>
                                <h1 className="mt-3 text-2xl font-semibold text-[#333333] sm:text-3xl">
                                    Aktive Bewohner
                                </h1>
                                <p className="mt-4 max-w-3xl leading-7 text-[#54595F]">
                                    Tippe auf einen Bewohner, um zu SIS, Maßnahmenplan, Vitalwerten
                                    und der weiteren Dokumentation zu gelangen.
                                </p>
                            </div>

                            {canManageResidents && (
                                <Link
                                    href={route(
                                        'residents.create',
                                        location ? { location_id: location.id } : {},
                                    )}
                                    className="inline-flex items-center justify-center rounded-md border border-transparent bg-[#9B1C3B] px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-[#7F1730] focus:bg-[#7F1730] focus:outline-none focus:ring-2 focus:ring-[#9B1C3B] focus:ring-offset-2 active:bg-[#7F1730]"
                                >
                                    Bewohner anlegen
                                </Link>
                            )}
                        </div>
                    </section>

                    {locations.length > 1 && (
                        <section className="mb-6 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:mb-8 sm:p-6">
                            <p className="mb-3 text-sm font-semibold text-[#333333]">
                                Wohnbereich filtern
                            </p>
                            <div className="flex flex-wrap gap-3">
                                <Link
                                    href={route('residents.index')}
                                    className={`rounded-full px-4 py-2 text-sm font-semibold ${
                                        !location
                                            ? 'bg-[#9B1C3B] text-white'
                                            : 'bg-[#F7E8ED] text-[#7F1730]'
                                    }`}
                                >
                                    Alle Wohnbereiche
                                </Link>
                                {locations.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={route('residents.index', { location_id: item.id })}
                                        className={`rounded-full px-4 py-2 text-sm font-semibold ${
                                            location?.id === item.id
                                                ? 'bg-[#9B1C3B] text-white'
                                                : 'bg-[#F7E8ED] text-[#7F1730]'
                                        }`}
                                    >
                                        {item.name}
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    {residents.length > 0 && (
                        <section className="mb-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-5">
                            <SearchField
                                value={query}
                                onChange={setQuery}
                                placeholder="Suche nach Name, Zimmer oder Pflegegrad …"
                            />
                            {careLevels.length > 0 && (
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-[#54595F]">
                                        Pflegegrad:
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setCareLevelFilter(null)}
                                        className={`rounded-full px-3 py-1 text-sm font-semibold ${
                                            careLevelFilter === null
                                                ? 'bg-[#9B1C3B] text-white'
                                                : 'bg-[#F7E8ED] text-[#7F1730]'
                                        }`}
                                    >
                                        Alle
                                    </button>
                                    {careLevels.map((level) => (
                                        <button
                                            key={level}
                                            type="button"
                                            onClick={() => setCareLevelFilter(level)}
                                            className={`rounded-full px-3 py-1 text-sm font-semibold ${
                                                careLevelFilter === level
                                                    ? 'bg-[#9B1C3B] text-white'
                                                    : 'bg-[#F7E8ED] text-[#7F1730]'
                                            }`}
                                        >
                                            {level}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </section>
                    )}

                    <div className="mb-3 flex items-baseline justify-between">
                        <h3 className="text-lg font-semibold text-[#333333]">Bewohnerliste</h3>
                        <p className="text-sm text-[#54595F]">
                            {filtered.length} von {residents.length}
                        </p>
                    </div>

                    {residents.length === 0 ? (
                        <div className="rounded-2xl bg-white px-6 py-12 text-center shadow-sm ring-1 ring-[#E5E7EB]">
                            <p className="text-lg font-semibold text-[#333333]">
                                Noch keine aktiven Bewohner vorhanden
                            </p>
                            <p className="mt-2 text-[#54595F]">
                                Lege über „Bewohner anlegen" den ersten Bewohner an.
                            </p>
                        </div>
                    ) : filtered.length === 0 ? (
                        <div className="rounded-2xl bg-white px-6 py-12 text-center shadow-sm ring-1 ring-[#E5E7EB]">
                            <p className="text-lg font-semibold text-[#333333]">Keine Treffer</p>
                            <p className="mt-2 text-[#54595F]">
                                Für diese Suche/Filter gibt es keinen Bewohner.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {filtered.map((resident) => (
                                <ResidentCard
                                    key={resident.id}
                                    resident={resident}
                                    showLocation={locations.length > 1}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

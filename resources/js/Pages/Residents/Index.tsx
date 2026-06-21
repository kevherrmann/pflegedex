import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

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

function ResidentActions({
    resident,
    canManageResidents,
}: {
    resident: Resident;
    canManageResidents: boolean;
}) {
    const linkClass = 'text-sm font-semibold text-[#9B1C3B] hover:underline';

    return (
        <>
            <Link href={route('residents.sis.show', resident.id)} className={linkClass}>
                SIS
            </Link>
            <Link href={route('residents.care-plan.show', resident.id)} className={linkClass}>
                MP
            </Link>
            <Link href={route('residents.vitals.index', resident.id)} className={linkClass}>
                Vitalwerte
            </Link>
            <Link href={route('residents.care-tasks.index', resident.id)} className={linkClass}>
                Nachweis
            </Link>
            <Link href={route('residents.assessments.index', resident.id)} className={linkClass}>
                Assessments
            </Link>
            <Link href={route('residents.medications.index', resident.id)} className={linkClass}>
                Medikation
            </Link>
            <Link href={route('residents.wounds.index', resident.id)} className={linkClass}>
                Wunden
            </Link>
            <Link href={route('residents.quality.index', resident.id)} className={linkClass}>
                Qualität
            </Link>
            {canManageResidents && (
                <Link href={route('residents.edit', resident.id)} className={linkClass}>
                    Bearbeiten
                </Link>
            )}
        </>
    );
}

export default function Index({ location, locations, residents }: ResidentsIndexProps) {
    const canManageResidents = usePage().props.auth.permissions.manageResidents;

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
                                    Diese Liste zeigt aktuell nur aktive Bewohner aus deinem
                                    zugeordneten Wohnbereich. Archivierte Bewohner und andere
                                    Wohnbereiche bleiben ausgeblendet.
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
                                        href={route('residents.index', {
                                            location_id: item.id,
                                        })}
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

                    <section className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <h3 className="text-lg font-semibold text-[#333333]">Bewohnerliste</h3>
                            <p className="mt-1 text-sm text-[#54595F]">
                                {residents.length} aktive Einträge
                            </p>
                        </div>

                        {residents.length > 0 ? (
                            <>
                                <div className="hidden overflow-x-auto md:block">
                                    <table className="min-w-full divide-y divide-[#E5E7EB]">
                                        <thead className="bg-[#F7E8ED]">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                    Name
                                                </th>
                                                {locations.length > 1 && (
                                                    <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                        Wohnbereich
                                                    </th>
                                                )}
                                                <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                    Zimmer
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                    Pflegegrad
                                                </th>
                                                {canManageResidents && (
                                                    <th className="px-6 py-3 text-right text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                        Aktionen
                                                    </th>
                                                )}
                                                {!canManageResidents && (
                                                    <th className="px-6 py-3 text-right text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                        Dokumentation
                                                    </th>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-[#E5E7EB] bg-white">
                                            {residents.map((resident) => (
                                                <tr key={resident.id}>
                                                    <td className="whitespace-nowrap px-6 py-4 font-medium text-[#333333]">
                                                        {resident.fullName}
                                                    </td>
                                                    {locations.length > 1 && (
                                                        <td className="whitespace-nowrap px-6 py-4 text-[#54595F]">
                                                            {resident.locationName ?? '—'}
                                                        </td>
                                                    )}
                                                    <td className="whitespace-nowrap px-6 py-4 text-[#54595F]">
                                                        {resident.roomNumber ?? '—'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-6 py-4 text-[#54595F]">
                                                        {resident.careLevel ?? '—'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-6 py-4 text-right">
                                                        <div className="flex flex-wrap justify-end gap-x-4 gap-y-2">
                                                            <ResidentActions
                                                                resident={resident}
                                                                canManageResidents={
                                                                    canManageResidents
                                                                }
                                                            />
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <ul className="divide-y divide-[#E5E7EB] md:hidden">
                                    {residents.map((resident) => (
                                        <li key={resident.id} className="space-y-3 p-4">
                                            <p className="font-medium text-[#333333]">
                                                {resident.fullName}
                                            </p>
                                            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                                {locations.length > 1 && (
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Wohnbereich
                                                        </dt>
                                                        <dd className="text-[#54595F]">
                                                            {resident.locationName ?? '—'}
                                                        </dd>
                                                    </div>
                                                )}
                                                <div>
                                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                        Zimmer
                                                    </dt>
                                                    <dd className="text-[#54595F]">
                                                        {resident.roomNumber ?? '—'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                        Pflegegrad
                                                    </dt>
                                                    <dd className="text-[#54595F]">
                                                        {resident.careLevel ?? '—'}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <div className="flex flex-wrap gap-x-4 gap-y-2 border-t border-[#E5E7EB] pt-3">
                                                <ResidentActions
                                                    resident={resident}
                                                    canManageResidents={canManageResidents}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine aktiven Bewohner vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Im nächsten Schritt ergänzen wir das Formular zum Anlegen neuer
                                    Bewohner.
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

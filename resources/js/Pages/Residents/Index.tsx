import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

type Location = {
    id: number;
    name: string;
};

type Resident = {
    id: number;
    fullName: string;
    roomNumber: string | null;
    careLevel: number | null;
};

type ResidentsIndexProps = {
    location: Location | null;
    residents: Resident[];
};

export default function Index({ location, residents }: ResidentsIndexProps) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Bewohnerdokumentation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Bewohner
                    </h2>
                </div>
            }
        >
            <Head title="Bewohner" />

            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <section className="mb-8 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                            {location?.name ?? 'Noch kein Wohnbereich'}
                        </p>
                        <h1 className="mt-3 text-3xl font-semibold text-[#333333]">
                            Aktive Bewohner
                        </h1>
                        <p className="mt-4 max-w-3xl leading-7 text-[#54595F]">
                            Diese Liste zeigt aktuell nur aktive Bewohner aus deinem zugeordneten Wohnbereich. Archivierte Bewohner und andere Wohnbereiche bleiben ausgeblendet.
                        </p>
                    </section>

                    <section className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <h3 className="text-lg font-semibold text-[#333333]">
                                Bewohnerliste
                            </h3>
                            <p className="mt-1 text-sm text-[#54595F]">
                                {residents.length} aktive Einträge
                            </p>
                        </div>

                        {residents.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-[#E5E7EB]">
                                    <thead className="bg-[#F7E8ED]">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                Name
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                Zimmer
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider text-[#7F1730]">
                                                Pflegegrad
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#E5E7EB] bg-white">
                                        {residents.map((resident) => (
                                            <tr key={resident.id}>
                                                <td className="whitespace-nowrap px-6 py-4 font-medium text-[#333333]">
                                                    {resident.fullName}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-[#54595F]">
                                                    {resident.roomNumber ?? '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-[#54595F]">
                                                    {resident.careLevel ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine aktiven Bewohner vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Im nächsten Schritt ergänzen wir das Formular zum Anlegen neuer Bewohner.
                                </p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

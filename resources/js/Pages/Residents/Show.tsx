import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

type Resident = {
    id: string;
    fullName: string;
    formalName: string | null;
    roomNumber: string | null;
    careLevel: number | null;
    locationName: string | null;
    status: string;
    admittedOn: string | null;
    birthDate: string | null;
};

type Props = {
    resident: Resident;
};

const STATUS_LABELS: Record<string, string> = {
    present: 'Anwesend',
    on_leave: 'Beurlaubt',
    hospital: 'Krankenhaus',
    discharged: 'Entlassen',
    deceased: 'Verstorben',
};

const STATUS_CLASSES: Record<string, string> = {
    present: 'bg-green-100 text-green-800',
    on_leave: 'bg-amber-100 text-amber-800',
    hospital: 'bg-blue-100 text-blue-800',
    discharged: 'bg-gray-100 text-gray-700',
    deceased: 'bg-gray-200 text-gray-700',
};

type Tile = {
    label: string;
    description: string;
    href: string;
    icon: string;
};

function formatDate(iso: string | null): string | null {
    if (iso === null) {
        return null;
    }
    const parsed = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }
    return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(parsed);
}

function TileCard({ tile }: { tile: Tile }) {
    return (
        <Link
            href={tile.href}
            className="group flex items-start gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] transition hover:shadow-md hover:ring-[#9B1C3B]/40"
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#F7E8ED] text-[#9B1C3B] transition group-hover:bg-[#9B1C3B] group-hover:text-white">
                <svg
                    className="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={1.7}
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d={tile.icon} />
                </svg>
            </span>
            <div className="min-w-0">
                <p className="font-semibold text-[#333333]">{tile.label}</p>
                <p className="mt-1 text-sm leading-6 text-[#54595F]">{tile.description}</p>
            </div>
        </Link>
    );
}

export default function Show({ resident }: Props) {
    const canManageResidents = usePage().props.auth.permissions.manageResidents;

    const tiles: Tile[] = [
        {
            label: 'SIS',
            description: 'Strukturierte Informationssammlung mit Themenfeldern und Risiken.',
            href: route('residents.sis.show', resident.id),
            icon: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        },
        {
            label: 'Maßnahmenplan',
            description: 'Pflegemaßnahmen, Generierung und Evaluation.',
            href: route('residents.care-plan.show', resident.id),
            icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
        },
        {
            label: 'Vitalwerte',
            description: 'Blutdruck, Puls, Temperatur, Gewicht und Verlauf.',
            href: route('residents.vitals.index', resident.id),
            icon: 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z',
        },
        {
            label: 'Durchführungsnachweis',
            description: 'Geplante Maßnahmen täglich quittieren.',
            href: route('residents.care-tasks.index', resident.id),
            icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        },
        {
            label: 'Assessments',
            description: 'Braden, Norton, Sturz, MNA und weitere.',
            href: route('residents.assessments.index', resident.id),
            icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        },
        {
            label: 'Medikation',
            description: 'Medikationsplan und Verabreichungsnachweis (MAR).',
            href: route('residents.medications.index', resident.id),
            icon: 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c.251-.023.501-.05.75-.082a24.301 24.301 0 014.5 0c.249.032.499.06.75.082M9.75 3.104a24.301 24.301 0 00-.75.082m5.25-.082v5.714c0 .597.237 1.17.659 1.591L19 14.5M14.25 3.104c.251-.023.501-.05.75-.082M19 14.5l-1.07 1.07a2.25 2.25 0 01-1.591.659H7.661a2.25 2.25 0 01-1.591-.659L5 14.5m14 0l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
        },
        {
            label: 'Wunden',
            description: 'Wunddokumentation mit Verlauf und Status.',
            href: route('residents.wounds.index', resident.id),
            icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
        },
        {
            label: 'Qualität',
            description: 'Qualitätsindikatoren nach §113b SGB XI.',
            href: route('residents.quality.index', resident.id),
            icon: 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z',
        },
    ];

    if (canManageResidents) {
        tiles.push({
            label: 'Stammdaten bearbeiten',
            description: 'Status, Aufnahme/Entlassung, Versicherung, Diagnosen.',
            href: route('residents.edit', resident.id),
            icon: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125',
        });
    }

    const birthDate = formatDate(resident.birthDate);
    const admittedOn = formatDate(resident.admittedOn);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Bewohnerdokumentation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        {resident.fullName}
                    </h2>
                </div>
            }
        >
            <Head title={resident.fullName} />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-[#9B1C3B] hover:underline"
                    >
                        ← Zurück zur Bewohnerliste
                    </Link>

                    {/* Bewohner-Kopf */}
                    <section className="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h1 className="text-2xl font-semibold text-[#333333] sm:text-3xl">
                                    {resident.fullName}
                                </h1>
                                <p className="mt-2 text-[#54595F]">
                                    {[
                                        `Zimmer ${resident.roomNumber ?? '—'}`,
                                        `Pflegegrad ${resident.careLevel ?? '—'}`,
                                        resident.locationName,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {(birthDate || admittedOn) && (
                                    <p className="mt-1 text-sm text-[#54595F]">
                                        {birthDate && `geb. ${birthDate}`}
                                        {birthDate && admittedOn && ' · '}
                                        {admittedOn && `aufgenommen ${admittedOn}`}
                                    </p>
                                )}
                            </div>
                            <span
                                className={`inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold ${
                                    STATUS_CLASSES[resident.status] ?? 'bg-gray-100 text-gray-700'
                                }`}
                            >
                                {STATUS_LABELS[resident.status] ?? resident.status}
                            </span>
                        </div>
                    </section>

                    {/* Modul-Kacheln */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {tiles.map((tile) => (
                            <TileCard key={tile.label} tile={tile} />
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

type LocationOption = { id: string; name: string };

type ResidentOption = {
    id: string;
    fullName: string;
    locationId: string | null;
    locationName: string | null;
    reportCount: number;
    completedCategoryCount: number;
    missingCategoryCount: number;
};

type CategoryTab = {
    name: string;
    reportCount: number;
    completed: boolean;
};

type CareReport = {
    id: string;
    residentName: string | null;
    locationName: string | null;
    authorName: string | null;
    occurredAt: string;
    category: string;
    body: string;
    signed: boolean;
    signedAt: string | null;
    signedByName: string | null;
    versionCount: number;
};

type CareReportsIndexProps = {
    residents: ResidentOption[];
    selectedResident: ResidentOption | null;
    selectedDate: string;
    categories: string[];
    categoryTabs: CategoryTab[];
    reportsByCategory: Record<string, CareReport[]>;
    locations: LocationOption[];
};

export default function Index({
    residents,
    selectedResident,
    selectedDate,
    categories,
    categoryTabs,
    reportsByCategory,
    locations,
}: CareReportsIndexProps) {
    const now = new Date();
    const [dateFilter, setDateFilter] = useState(selectedDate);
    const [search, setSearch] = useState('');
    const [locationFilter, setLocationFilter] = useState<string>('all');
    const [showComposer, setShowComposer] = useState(false);
    const defaultOccurredAt = `${selectedDate}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const { data, setData, post, processing, errors, reset } = useForm({
        resident_id: selectedResident ? String(selectedResident.id) : '',
        occurred_at: defaultOccurredAt,
        category: categories[0] ?? 'Beobachtung',
        body: '',
    });

    const filteredResidents = useMemo(() => {
        const term = search.trim().toLowerCase();

        return residents.filter((resident) => {
            const matchesSearch = term === '' || resident.fullName.toLowerCase().includes(term);
            const matchesLocation = locationFilter === 'all' || resident.locationId === locationFilter;

            return matchesSearch && matchesLocation;
        });
    }, [residents, search, locationFilter]);

    const totalMissing = useMemo(
        () => residents.reduce((sum, resident) => sum + resident.missingCategoryCount, 0),
        [residents],
    );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('care-reports.store'), {
            onSuccess: () => {
                reset('body');
                setShowComposer(false);
            },
        });
    };

    const signReport = (report: CareReport): void => {
        if (report.signed) {
            return;
        }

        router.post(route('care-reports.sign', report.id), {}, { preserveScroll: true });
    };

    const openComposer = (category?: string) => {
        if (selectedResident) {
            setData((current) => ({
                ...current,
                resident_id: String(selectedResident.id),
                ...(category ? { category } : {}),
            }));
        }
        setShowComposer(true);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Dokumentation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Pflegeberichte
                    </h2>
                </div>
            }
        >
            <Head title="Pflegeberichte" />

            <div className="bg-[#F8F8F8] py-8">
                <div className="mx-auto grid max-w-[1800px] gap-6 px-4 sm:px-6 lg:grid-cols-[340px_minmax(0,1fr)] lg:px-8">
                    {/* Bewohner-Leiste */}
                    <aside className="lg:sticky lg:top-6 lg:h-[calc(100vh-7rem)]">
                        <section className="flex h-full flex-col rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                            <div className="border-b border-[#E5E7EB] px-5 py-4">
                                <div className="flex items-baseline justify-between">
                                    <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                        Bewohner
                                    </p>
                                    <span className="text-xs text-[#54595F]">
                                        {filteredResidents.length}/{residents.length}
                                    </span>
                                </div>

                                <input
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Bewohner suchen…"
                                    className="mt-3 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                />

                                {locations.length > 1 && (
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        <button
                                            type="button"
                                            onClick={() => setLocationFilter('all')}
                                            className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                                locationFilter === 'all'
                                                    ? 'bg-[#9B1C3B] text-white'
                                                    : 'bg-[#F7E8ED] text-[#7F1730] hover:bg-[#efd3dc]'
                                            }`}
                                        >
                                            Alle
                                        </button>
                                        {locations.map((location) => (
                                            <button
                                                key={location.id}
                                                type="button"
                                                onClick={() => setLocationFilter(location.id)}
                                                className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                                    locationFilter === location.id
                                                        ? 'bg-[#9B1C3B] text-white'
                                                        : 'bg-[#F7E8ED] text-[#7F1730] hover:bg-[#efd3dc]'
                                                }`}
                                            >
                                                {location.name}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="min-h-0 flex-1 overflow-y-auto">
                                {filteredResidents.length > 0 ? (
                                    <div className="divide-y divide-[#E5E7EB]">
                                        {filteredResidents.map((resident) => {
                                            const selected = selectedResident?.id === resident.id;

                                            return (
                                                <Link
                                                    key={resident.id}
                                                    href={route('care-reports.index', {
                                                        resident_id: resident.id,
                                                        date: selectedDate,
                                                    })}
                                                    preserveScroll
                                                    className={`block px-5 py-3 transition ${
                                                        selected ? 'bg-[#F7E8ED]' : 'hover:bg-[#F8F8F8]'
                                                    }`}
                                                >
                                                    <div className="flex items-center justify-between gap-3">
                                                        <p className="truncate font-semibold text-[#333333]">
                                                            {resident.fullName}
                                                        </p>
                                                        <span
                                                            className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                                resident.missingCategoryCount > 0
                                                                    ? 'bg-amber-100 text-amber-800'
                                                                    : 'bg-emerald-100 text-emerald-800'
                                                            }`}
                                                            title="Gefüllte Kategorien"
                                                        >
                                                            {resident.completedCategoryCount}/{categories.length}
                                                        </span>
                                                    </div>
                                                    <p className="mt-0.5 truncate text-xs text-[#54595F]">
                                                        {resident.locationName ?? 'Wohnbereich offen'}
                                                    </p>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="px-5 py-10 text-center text-sm text-[#54595F]">
                                        {residents.length === 0
                                            ? 'Keine zugeordneten Bewohner vorhanden.'
                                            : 'Kein Bewohner passt zur Suche.'}
                                    </div>
                                )}
                            </div>

                            {residents.length > 0 && (
                                <div className="border-t border-[#E5E7EB] px-5 py-3 text-xs text-[#54595F]">
                                    {totalMissing > 0
                                        ? `${totalMissing} offene Kategorien insgesamt`
                                        : 'Alle Kategorien dokumentiert'}
                                </div>
                            )}
                        </section>
                    </aside>

                    {/* Berichtsbereich */}
                    <section className="min-w-0 rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="flex flex-col gap-4 border-b border-[#E5E7EB] px-6 py-5 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                    Bewohnerakte
                                </p>
                                <h1 className="mt-2 text-3xl font-semibold text-[#333333]">
                                    {selectedResident?.fullName ?? 'Kein Bewohner ausgewählt'}
                                </h1>
                                {selectedResident && (
                                    <p className="mt-2 text-[#54595F]">
                                        {selectedResident.locationName} ·{' '}
                                        {selectedResident.completedCategoryCount}/{categories.length} Kategorien am{' '}
                                        {selectedDate} dokumentiert
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-wrap items-end gap-3">
                                <div>
                                    <InputLabel htmlFor="date_filter" value="Dokumentationstag" />
                                    <div className="mt-1 flex items-center gap-2">
                                        <input
                                            id="date_filter"
                                            type="date"
                                            value={dateFilter}
                                            onChange={(event) => setDateFilter(event.target.value)}
                                            className="block rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        />
                                        <Link
                                            href={route('care-reports.index', {
                                                date: dateFilter,
                                                ...(selectedResident ? { resident_id: selectedResident.id } : {}),
                                            })}
                                            preserveScroll
                                            className="inline-flex items-center justify-center rounded-md border border-[#E5E7EB] bg-white px-3 py-2 text-sm font-semibold text-[#7F1730] hover:bg-[#F8F8F8]"
                                        >
                                            Anzeigen
                                        </Link>
                                    </div>
                                </div>

                                {selectedResident && !showComposer && (
                                    <PrimaryButton type="button" onClick={() => openComposer()}>
                                        + Neuer Bericht
                                    </PrimaryButton>
                                )}
                            </div>
                        </div>

                        {!selectedResident ? (
                            <div className="px-6 py-16 text-center text-[#54595F]">
                                Wähle links einen Bewohner aus, um die Berichte nach Kategorien zu sehen.
                            </div>
                        ) : (
                            <>
                                {/* On-demand-Composer */}
                                {showComposer && (
                                    <div className="border-b border-[#E5E7EB] bg-[#FCF6F8] px-6 py-5">
                                        <div className="flex items-center justify-between">
                                            <h3 className="text-lg font-semibold text-[#333333]">
                                                Neuer Bericht für {selectedResident.fullName}
                                            </h3>
                                            <button
                                                type="button"
                                                onClick={() => setShowComposer(false)}
                                                className="text-sm font-semibold text-[#54595F] hover:underline"
                                            >
                                                Abbrechen
                                            </button>
                                        </div>

                                        <form onSubmit={submit} className="mt-4 space-y-4">
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div>
                                                    <InputLabel htmlFor="occurred_at" value="Zeitpunkt" />
                                                    <input
                                                        id="occurred_at"
                                                        type="datetime-local"
                                                        value={data.occurred_at}
                                                        onChange={(event) => setData('occurred_at', event.target.value)}
                                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                        required
                                                    />
                                                    <InputError message={errors.occurred_at} className="mt-2" />
                                                </div>

                                                <div>
                                                    <InputLabel htmlFor="category" value="Kategorie" />
                                                    <select
                                                        id="category"
                                                        value={data.category}
                                                        onChange={(event) => setData('category', event.target.value)}
                                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                    >
                                                        {categories.map((category) => (
                                                            <option key={category} value={category}>
                                                                {category}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <InputError message={errors.category} className="mt-2" />
                                                </div>
                                            </div>

                                            <div>
                                                <InputLabel htmlFor="body" value="Bericht" />
                                                <textarea
                                                    id="body"
                                                    value={data.body}
                                                    onChange={(event) => setData('body', event.target.value)}
                                                    rows={5}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                    required
                                                />
                                                <InputError message={errors.body} className="mt-2" />
                                            </div>

                                            <div className="flex justify-end gap-2">
                                                <SecondaryButton type="button" onClick={() => setShowComposer(false)}>
                                                    Abbrechen
                                                </SecondaryButton>
                                                <PrimaryButton disabled={processing}>
                                                    Bericht speichern
                                                </PrimaryButton>
                                            </div>
                                        </form>
                                    </div>
                                )}

                                {/* Kategorie-Navigation */}
                                <div className="sticky top-0 z-10 border-b border-[#E5E7EB] bg-white/95 px-6 py-3 backdrop-blur">
                                    <div className="flex flex-wrap gap-2">
                                        {categoryTabs.map((tab) => (
                                            <a
                                                key={tab.name}
                                                href={`#category-${tab.name}`}
                                                className={`rounded-full px-3.5 py-1.5 text-sm font-semibold ${
                                                    tab.completed
                                                        ? 'bg-[#9B1C3B] text-white'
                                                        : 'bg-[#F7E8ED] text-[#7F1730]'
                                                }`}
                                            >
                                                {tab.name} ({tab.reportCount})
                                            </a>
                                        ))}
                                    </div>
                                </div>

                                <div className="divide-y divide-[#E5E7EB]">
                                    {categoryTabs.map((tab) => {
                                        const reports = reportsByCategory[tab.name] ?? [];

                                        return (
                                            <section
                                                key={tab.name}
                                                id={`category-${tab.name}`}
                                                className="scroll-mt-20 px-6 py-6"
                                            >
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <h2 className="text-xl font-semibold text-[#333333]">
                                                            {tab.name}
                                                        </h2>
                                                        <p className="mt-1 text-sm text-[#54595F]">
                                                            {reports.length > 0
                                                                ? `${reports.length} Eintrag/Einträge`
                                                                : 'Noch kein Eintrag in dieser Kategorie'}
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => openComposer(tab.name)}
                                                        className="self-start rounded-md bg-[#F7E8ED] px-3 py-2 text-sm font-semibold text-[#7F1730] hover:bg-[#efd3dc]"
                                                    >
                                                        + Eintrag in {tab.name}
                                                    </button>
                                                </div>

                                                {reports.length > 0 ? (
                                                    <div className="mt-5 grid gap-4 xl:grid-cols-2">
                                                        {reports.map((report) => (
                                                            <article
                                                                key={report.id}
                                                                className="flex flex-col rounded-xl border border-[#E5E7EB] bg-[#FBFBFB] p-4"
                                                            >
                                                                <div className="flex items-start justify-between gap-4">
                                                                    <div>
                                                                        <p className="text-xs text-gray-500">
                                                                            {report.occurredAt}
                                                                        </p>
                                                                        <p className="text-xs text-gray-500">
                                                                            Erfasst von {report.authorName ?? 'unbekannt'}
                                                                        </p>
                                                                    </div>

                                                                    {report.signed ? (
                                                                        <span className="shrink-0 rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800">
                                                                            Signiert
                                                                        </span>
                                                                    ) : (
                                                                        <span className="shrink-0 rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-yellow-800">
                                                                            Entwurf
                                                                        </span>
                                                                    )}
                                                                </div>

                                                                <p className="mt-2 whitespace-pre-line text-sm text-gray-700">
                                                                    {report.body}
                                                                </p>

                                                                <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
                                                                    <span>Versionen: {report.versionCount}</span>

                                                                    {report.signed ? (
                                                                        <span>
                                                                            Signiert von {report.signedByName ?? 'unbekannt'} am{' '}
                                                                            {report.signedAt ?? 'unbekannt'}
                                                                        </span>
                                                                    ) : (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => signReport(report)}
                                                                            className="rounded-md bg-[#7F1730] px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#641226]"
                                                                        >
                                                                            Signieren
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            </article>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="mt-5 rounded-xl border border-dashed border-[#E5E7EB] p-5 text-sm text-[#54595F]">
                                                        Für {tab.name} wurde bei diesem Bewohner noch nichts dokumentiert.
                                                    </div>
                                                )}
                                            </section>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

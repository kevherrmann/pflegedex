import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Markdown from '@/Components/Markdown';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { SyntheticEvent, useMemo, useState } from 'react';

type LocationOption = { id: string; name: string };

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

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
    textBlocks: Record<string, string[]>;
};

export default function Index({
    residents,
    selectedResident,
    selectedDate,
    categories,
    categoryTabs,
    reportsByCategory,
    locations,
    textBlocks,
}: CareReportsIndexProps) {
    const now = new Date();
    // Auf dem Handy entscheidet die URL über Liste vs. Akte (Master-Detail):
    // mit resident_id → Akte, ohne → Bewohnerliste. Am Desktop sind beide sichtbar.
    const showDetailOnMobile = usePage().url.includes('resident_id=');
    const [dateFilter, setDateFilter] = useState(selectedDate);
    const [search, setSearch] = useState('');
    const [locationFilter, setLocationFilter] = useState<string>('all');
    const [showComposer, setShowComposer] = useState(false);
    const defaultOccurredAt = `${selectedDate}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const { data, setData, post, processing, errors, reset, transform } = useForm({
        resident_id: selectedResident ? String(selectedResident.id) : '',
        occurred_at: defaultOccurredAt,
        category: categories[0] ?? 'Beobachtung',
        body: '',
    });

    const filteredResidents = useMemo(() => {
        const term = search.trim().toLowerCase();

        return residents.filter((resident) => {
            const matchesSearch = term === '' || resident.fullName.toLowerCase().includes(term);
            const matchesLocation =
                locationFilter === 'all' || resident.locationId === locationFilter;

            return matchesSearch && matchesLocation;
        });
    }, [residents, search, locationFilter]);

    const totalMissing = useMemo(
        () => residents.reduce((sum, resident) => sum + resident.missingCategoryCount, 0),
        [residents],
    );

    const submit = (sign: boolean) => (event: SyntheticEvent) => {
        event.preventDefault();
        transform((current) => ({ ...current, sign }));
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

    // Welche Bausteine stecken aktuell als eigene Zeile im Text (für den Aktiv-Zustand der Chips).
    const activeBlocks = useMemo(
        () => new Set(data.body.split('\n').map((line) => line.trim())),
        [data.body],
    );

    const toggleTextBlock = (phrase: string) => {
        setData((current) => {
            const lines = current.body.split('\n');
            const isPresent = lines.some((line) => line.trim() === phrase);

            if (isPresent) {
                // Erneuter Klick entfernt die Baustein-Zeile wieder.
                const kept = lines.filter((line) => line.trim() !== phrase);

                return {
                    ...current,
                    body: kept.join('\n').replace(/^\n+/, '').replace(/\n+$/, ''),
                };
            }

            const next = current.body.replace(/\s+$/, '');

            return {
                ...current,
                body: next.length > 0 ? `${next}\n${phrase}` : phrase,
            };
        });
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

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto grid max-w-[1800px] gap-6 px-4 sm:px-6 lg:grid-cols-[340px_minmax(0,1fr)] lg:px-8">
                    {/* Bewohner-Leiste — auf dem Handy ausgeblendet, sobald ein Bewohner gewählt ist (Master-Detail) */}
                    <aside
                        className={`${
                            showDetailOnMobile ? 'hidden lg:block' : 'block'
                        } lg:sticky lg:top-6 lg:h-[calc(100vh-7rem)]`}
                    >
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
                                    <div className="flex flex-col gap-3 p-3 lg:gap-0 lg:divide-y lg:divide-[#E5E7EB] lg:p-0">
                                        {filteredResidents.map((resident) => {
                                            const selected = selectedResident?.id === resident.id;

                                            return (
                                                <Link
                                                    key={resident.id}
                                                    href={route('care-reports.index', {
                                                        resident_id: resident.id,
                                                        date: selectedDate,
                                                    })}
                                                    className={`group flex items-center gap-3 rounded-2xl p-3 shadow-sm ring-1 ring-[#E5E7EB] transition hover:shadow-md hover:ring-[#9B1C3B]/40 lg:rounded-none lg:px-5 lg:py-3 lg:shadow-none lg:ring-0 lg:hover:shadow-none ${
                                                        selected
                                                            ? 'bg-[#F7E8ED] ring-[#9B1C3B]/40 lg:ring-0'
                                                            : 'bg-white lg:bg-transparent lg:hover:bg-[#F8F8F8]'
                                                    }`}
                                                >
                                                    <span
                                                        className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold text-[#7F1730] ${
                                                            selected ? 'bg-white' : 'bg-[#F7E8ED]'
                                                        }`}
                                                    >
                                                        {initials(resident.fullName)}
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate font-semibold text-[#333333]">
                                                            {resident.fullName}
                                                        </p>
                                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                            <span className="rounded-full bg-[#F8F8F8] px-2 py-0.5 text-xs text-[#54595F] ring-1 ring-[#E5E7EB]">
                                                                {resident.locationName ??
                                                                    'Wohnbereich offen'}
                                                            </span>
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                                    resident.missingCategoryCount >
                                                                    0
                                                                        ? 'bg-amber-100 text-amber-800'
                                                                        : 'bg-emerald-100 text-emerald-800'
                                                                }`}
                                                                title="Dokumentierte Kategorien"
                                                            >
                                                                {resident.completedCategoryCount}/
                                                                {categories.length} Kategorien
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <svg
                                                        className="h-5 w-5 shrink-0 self-center text-gray-300 transition group-hover:text-[#9B1C3B]"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        strokeWidth={2}
                                                        stroke="currentColor"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                            d="M8.25 4.5l7.5 7.5-7.5 7.5"
                                                        />
                                                    </svg>
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

                    {/* Berichtsbereich — auf dem Handy nur sichtbar, wenn ein Bewohner gewählt ist */}
                    <section
                        className={`${
                            showDetailOnMobile ? 'block' : 'hidden lg:block'
                        } min-w-0 rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]`}
                    >
                        {selectedResident && (
                            <div className="border-b border-[#E5E7EB] px-4 py-3 lg:hidden">
                                <Link
                                    href={route('care-reports.index', { date: selectedDate })}
                                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-[#7F1730]"
                                >
                                    <svg
                                        className="h-4 w-4"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.08 10l3.71 3.71a.75.75 0 1 1-1.06 1.06l-4.24-4.24a.75.75 0 0 1 0-1.06l4.24-4.24a.75.75 0 0 1 1.06 0Z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                    Bewohnerliste
                                </Link>
                            </div>
                        )}
                        <div className="flex flex-col gap-4 border-b border-[#E5E7EB] px-6 py-5 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                    Bewohnerakte
                                </p>
                                <h1 className="mt-2 text-2xl font-semibold text-[#333333] sm:text-3xl">
                                    {selectedResident?.fullName ?? 'Kein Bewohner ausgewählt'}
                                </h1>
                                {selectedResident && (
                                    <p className="mt-2 text-[#54595F]">
                                        {selectedResident.locationName} ·{' '}
                                        {selectedResident.completedCategoryCount}/
                                        {categories.length} Kategorien am {selectedDate}{' '}
                                        dokumentiert
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
                                                ...(selectedResident
                                                    ? { resident_id: selectedResident.id }
                                                    : {}),
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
                                Wähle links einen Bewohner aus, um die Berichte nach Kategorien zu
                                sehen.
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

                                        <form onSubmit={submit(true)} className="mt-4 space-y-4">
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div>
                                                    <InputLabel
                                                        htmlFor="occurred_at"
                                                        value="Zeitpunkt"
                                                    />
                                                    <input
                                                        id="occurred_at"
                                                        type="datetime-local"
                                                        value={data.occurred_at}
                                                        onChange={(event) =>
                                                            setData(
                                                                'occurred_at',
                                                                event.target.value,
                                                            )
                                                        }
                                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.occurred_at}
                                                        className="mt-2"
                                                    />
                                                </div>

                                                <div>
                                                    <InputLabel
                                                        htmlFor="category"
                                                        value="Kategorie"
                                                    />
                                                    <select
                                                        id="category"
                                                        value={data.category}
                                                        onChange={(event) =>
                                                            setData('category', event.target.value)
                                                        }
                                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                    >
                                                        {categories.map((category) => (
                                                            <option key={category} value={category}>
                                                                {category}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <InputError
                                                        message={errors.category}
                                                        className="mt-2"
                                                    />
                                                </div>
                                            </div>

                                            {(textBlocks[data.category] ?? []).length > 0 && (
                                                <div className="rounded-xl bg-white p-3 ring-1 ring-[#E5E7EB]">
                                                    <p className="text-sm font-semibold text-[#333333]">
                                                        Textbausteine
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-[#54595F]">
                                                        Antippen fügt ein, erneutes Antippen
                                                        entfernt wieder – frei formuliert wird nur
                                                        die Abweichung.
                                                    </p>
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {(textBlocks[data.category] ?? []).map(
                                                            (phrase) => {
                                                                const active =
                                                                    activeBlocks.has(phrase);

                                                                return (
                                                                    <button
                                                                        key={phrase}
                                                                        type="button"
                                                                        onClick={() =>
                                                                            toggleTextBlock(phrase)
                                                                        }
                                                                        aria-pressed={active}
                                                                        title={
                                                                            active
                                                                                ? 'Antippen zum Entfernen'
                                                                                : 'Antippen zum Einfügen'
                                                                        }
                                                                        className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition ${
                                                                            active
                                                                                ? 'bg-[#9B1C3B] text-white ring-[#9B1C3B] hover:bg-[#7F1730]'
                                                                                : 'bg-[#F7E8ED] text-[#7F1730] ring-[#9B1C3B]/15 hover:bg-[#efd3dc]'
                                                                        }`}
                                                                    >
                                                                        <span className="text-sm leading-none">
                                                                            {active ? '✓' : '+'}
                                                                        </span>
                                                                        {phrase}
                                                                    </button>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            )}

                                            <div>
                                                <InputLabel htmlFor="body" value="Bericht" />
                                                <textarea
                                                    id="body"
                                                    value={data.body}
                                                    onChange={(event) =>
                                                        setData('body', event.target.value)
                                                    }
                                                    rows={5}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                    required
                                                />
                                                <InputError
                                                    message={errors.body}
                                                    className="mt-2"
                                                />
                                            </div>

                                            <div className="flex flex-wrap justify-end gap-2">
                                                <SecondaryButton
                                                    type="button"
                                                    onClick={() => setShowComposer(false)}
                                                >
                                                    Abbrechen
                                                </SecondaryButton>
                                                <SecondaryButton
                                                    type="button"
                                                    disabled={processing}
                                                    onClick={submit(false)}
                                                >
                                                    Als Entwurf speichern
                                                </SecondaryButton>
                                                <PrimaryButton disabled={processing}>
                                                    Speichern & signieren
                                                </PrimaryButton>
                                            </div>
                                            <p className="text-xs text-[#54595F]">
                                                Mit „Speichern &amp; signieren" wird der Eintrag
                                                sofort rechtsverbindlich freigegeben und gegen
                                                Änderungen gesperrt. Ein Entwurf kann später noch
                                                bearbeitet und dann signiert werden.
                                            </p>
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
                                                                            Erfasst von{' '}
                                                                            {report.authorName ??
                                                                                'unbekannt'}
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

                                                                <Markdown
                                                                    className="mt-2"
                                                                    content={report.body}
                                                                />

                                                                <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
                                                                    <span>
                                                                        Versionen:{' '}
                                                                        {report.versionCount}
                                                                    </span>

                                                                    {report.signed ? (
                                                                        <span>
                                                                            Signiert von{' '}
                                                                            {report.signedByName ??
                                                                                'unbekannt'}{' '}
                                                                            am{' '}
                                                                            {report.signedAt ??
                                                                                'unbekannt'}
                                                                        </span>
                                                                    ) : (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() =>
                                                                                signReport(report)
                                                                            }
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
                                                        Für {tab.name} wurde bei diesem Bewohner
                                                        noch nichts dokumentiert.
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

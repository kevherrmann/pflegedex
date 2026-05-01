import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type ResidentOption = {
    id: number;
    fullName: string;
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
    id: number;
    residentName: string | null;
    locationName: string | null;
    authorName: string | null;
    occurredAt: string;
    category: string;
    body: string;
};

type CareReportsIndexProps = {
    residents: ResidentOption[];
    selectedResident: ResidentOption | null;
    categories: string[];
    categoryTabs: CategoryTab[];
    reportsByCategory: Record<string, CareReport[]>;
};

export default function Index({
    residents,
    selectedResident,
    categories,
    categoryTabs,
    reportsByCategory,
}: CareReportsIndexProps) {
    const now = new Date();
    const defaultOccurredAt = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const { data, setData, post, processing, errors, reset } = useForm({
        resident_id: selectedResident ? String(selectedResident.id) : '',
        occurred_at: defaultOccurredAt,
        category: categories[0] ?? 'Beobachtung',
        body: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('care-reports.store'), {
            onSuccess: () => reset('body'),
        });
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

            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[320px_1fr_420px] lg:px-8">
                    <section className="h-fit rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-5 py-4">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Bewohner
                            </p>
                            <h1 className="mt-2 text-2xl font-semibold text-[#333333]">
                                Auswahl
                            </h1>
                        </div>

                        {residents.length > 0 ? (
                            <div className="divide-y divide-[#E5E7EB]">
                                {residents.map((resident) => {
                                    const selected = selectedResident?.id === resident.id;

                                    return (
                                        <Link
                                            key={resident.id}
                                            href={route('care-reports.index', { resident_id: resident.id })}
                                            className={`block px-5 py-4 transition ${selected ? 'bg-[#F7E8ED]' : 'hover:bg-[#F8F8F8]'}`}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-semibold text-[#333333]">
                                                        {resident.fullName}
                                                    </p>
                                                    <p className="mt-1 text-sm text-[#54595F]">
                                                        {resident.locationName ?? 'Wohnbereich offen'}
                                                    </p>
                                                </div>
                                                <span className="rounded-full bg-white px-2 py-1 text-xs font-semibold text-[#7F1730] ring-1 ring-[#E5E7EB]">
                                                    {resident.reportCount}
                                                </span>
                                            </div>
                                            <p className="mt-3 text-xs font-semibold text-[#54595F]">
                                                {resident.completedCategoryCount}/{categories.length} Kategorien gefüllt
                                            </p>
                                            {resident.missingCategoryCount > 0 && (
                                                <p className="mt-1 text-xs text-amber-700">
                                                    {resident.missingCategoryCount} Kategorien noch offen
                                                </p>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="px-5 py-10 text-sm text-[#54595F]">
                                Keine zugeordneten Bewohner vorhanden.
                            </div>
                        )}
                    </section>

                    <section className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Bewohnerakte
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold text-[#333333]">
                                {selectedResident?.fullName ?? 'Kein Bewohner ausgewählt'}
                            </h1>
                            {selectedResident && (
                                <p className="mt-3 text-[#54595F]">
                                    {selectedResident.locationName} · {selectedResident.completedCategoryCount}/{categories.length} Kategorien dokumentiert
                                </p>
                            )}
                        </div>

                        {selectedResident ? (
                            <>
                                <div className="border-b border-[#E5E7EB] px-6 py-4">
                                    <div className="flex flex-wrap gap-2">
                                        {categoryTabs.map((tab) => (
                                            <a
                                                key={tab.name}
                                                href={`#category-${tab.name}`}
                                                className={`rounded-full px-4 py-2 text-sm font-semibold ${tab.completed ? 'bg-[#9B1C3B] text-white' : 'bg-[#F7E8ED] text-[#7F1730]'}`}
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
                                            <section key={tab.name} id={`category-${tab.name}`} className="scroll-mt-6 px-6 py-6">
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <h2 className="text-xl font-semibold text-[#333333]">
                                                            {tab.name}
                                                        </h2>
                                                        <p className="mt-1 text-sm text-[#54595F]">
                                                            {reports.length > 0 ? `${reports.length} Eintrag/Einträge` : 'Noch kein Eintrag in dieser Kategorie'}
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => setData('category', tab.name)}
                                                        className="rounded-md bg-[#F7E8ED] px-3 py-2 text-sm font-semibold text-[#7F1730] hover:bg-[#efd3dc]"
                                                    >
                                                        Kategorie im Formular wählen
                                                    </button>
                                                </div>

                                                {reports.length > 0 ? (
                                                    <div className="mt-5 space-y-4">
                                                        {reports.map((report) => (
                                                            <article key={report.id} className="rounded-xl border border-[#E5E7EB] bg-[#F8F8F8] p-4">
                                                                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                                    <p className="text-sm font-semibold text-[#7F1730]">
                                                                        {report.occurredAt}
                                                                    </p>
                                                                    <p className="text-sm text-[#54595F]">
                                                                        Erfasst von {report.authorName ?? 'unbekannt'}
                                                                    </p>
                                                                </div>
                                                                <p className="mt-3 whitespace-pre-line leading-7 text-[#333333]">
                                                                    {report.body}
                                                                </p>
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
                        ) : (
                            <div className="px-6 py-12 text-center text-[#54595F]">
                                Wähle links einen Bewohner aus, um die Berichte nach Kategorien zu sehen.
                            </div>
                        )}
                    </section>

                    <section className="h-fit rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-xl font-semibold text-[#333333]">
                            Bericht erfassen
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-[#54595F]">
                            Der Bericht wird dem ausgewählten Bewohner und der Kategorie zugeordnet.
                        </p>

                        <form onSubmit={submit} className="mt-6 space-y-5">
                            <div>
                                <InputLabel htmlFor="resident_id" value="Bewohner" />
                                <select
                                    id="resident_id"
                                    value={data.resident_id}
                                    onChange={(event) => setData('resident_id', event.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    required
                                >
                                    <option value="">Bitte auswählen</option>
                                    {residents.map((resident) => (
                                        <option key={resident.id} value={resident.id}>
                                            {resident.fullName}{resident.locationName ? ` · ${resident.locationName}` : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.resident_id} className="mt-2" />
                            </div>

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
                                    {categories.map((category) => <option key={category} value={category}>{category}</option>)}
                                </select>
                                <InputError message={errors.category} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="body" value="Bericht" />
                                <textarea
                                    id="body"
                                    value={data.body}
                                    onChange={(event) => setData('body', event.target.value)}
                                    rows={7}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    required
                                />
                                <InputError message={errors.body} className="mt-2" />
                            </div>

                            <PrimaryButton disabled={processing || residents.length === 0}>
                                Bericht speichern
                            </PrimaryButton>
                        </form>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

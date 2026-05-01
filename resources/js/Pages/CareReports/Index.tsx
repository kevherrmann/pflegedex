import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type ResidentOption = { id: number; fullName: string; locationName: string | null };
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
    reports: CareReport[];
    residents: ResidentOption[];
    categories: string[];
};

export default function Index({ reports, residents, categories }: CareReportsIndexProps) {
    const now = new Date();
    const defaultOccurredAt = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const { data, setData, post, processing, errors, reset } = useForm({
        resident_id: residents.length === 1 ? String(residents[0].id) : '',
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
                <div className="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[1fr_420px] lg:px-8">
                    <section className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Verlauf
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold text-[#333333]">
                                Aktuelle Pflegeberichte
                            </h1>
                            <p className="mt-3 text-[#54595F]">
                                Sichtbar sind nur Berichte aus deinen zugeordneten Wohnbereichen.
                            </p>
                        </div>

                        {reports.length > 0 ? (
                            <div className="divide-y divide-[#E5E7EB]">
                                {reports.map((report) => (
                                    <article key={report.id} className="px-6 py-5">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h3 className="text-lg font-semibold text-[#333333]">
                                                    {report.residentName}
                                                </h3>
                                                <p className="mt-1 text-sm text-[#54595F]">
                                                    {report.locationName} · {report.category}
                                                </p>
                                            </div>
                                            <p className="text-sm font-semibold text-[#7F1730]">
                                                {report.occurredAt}
                                            </p>
                                        </div>
                                        <p className="mt-4 whitespace-pre-line leading-7 text-[#333333]">
                                            {report.body}
                                        </p>
                                        <p className="mt-3 text-sm text-[#54595F]">
                                            Erfasst von {report.authorName ?? 'unbekannt'}
                                        </p>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine Pflegeberichte vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Lege rechts den ersten Bericht für einen Bewohner an.
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="h-fit rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-xl font-semibold text-[#333333]">
                            Bericht erfassen
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-[#54595F]">
                            Der Bericht wird unveränderbar dem Bewohner, Wohnbereich und deinem Benutzerkonto zugeordnet.
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

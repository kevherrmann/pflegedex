import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = { id: string; fullName: string; locationName: string | null };
type IndicatorOption = { value: string; label: string; quality: string };
type Indicator = { value: string; label: string; area: number; options: IndicatorOption[] };

type Props = {
    resident: Resident;
    period: string;
    periods: string[];
    indicators: Indicator[];
    answers: Record<string, string>;
    note: string | null;
    assessedOn: string | null;
};

const AREA_LABELS: Record<number, string> = {
    1: 'Bereich 1 – Erhalt der Selbständigkeit',
    2: 'Bereich 2 – Schutz vor Schädigungen',
    3: 'Bereich 3 – Besondere Bedarfslagen',
};

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function Resident({
    resident,
    period,
    periods,
    indicators,
    answers,
    note,
    assessedOn,
}: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        period: string;
        assessed_on: string;
        answers: Record<string, string>;
        note: string;
    }>({
        period,
        assessed_on: assessedOn ?? todayIso(),
        answers: { ...answers },
        note: note ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('residents.quality.store', resident.id), { preserveScroll: true });
    };

    const changePeriod = (next: string) => {
        router.get(
            route('residents.quality.index', resident.id),
            { period: next },
            { preserveScroll: true },
        );
    };

    const areas = [1, 2, 3];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Qualitätsindikatoren
                </h2>
            }
        >
            <Head title="Qualitätsindikatoren" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={route('residents.index')}
                            className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                        >
                            Zurück zur Bewohner-Übersicht
                        </Link>
                        <Link
                            href={route('quality.evaluation', { period })}
                            className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-[#9B1C3B] transition hover:bg-gray-50"
                        >
                            Auswertung ansehen
                        </Link>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-4 overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <div>
                            <p className="text-sm text-gray-500">
                                {resident.locationName ?? 'Unbekannter Wohnbereich'}
                            </p>
                            <h3 className="mt-1 text-lg font-semibold text-gray-900">
                                {resident.fullName}
                            </h3>
                        </div>
                        <div>
                            <InputLabel htmlFor="period-select" value="Erhebungshalbjahr" />
                            <select
                                id="period-select"
                                className="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={period}
                                onChange={(e) => changePeriod(e.target.value)}
                            >
                                {periods.map((p) => (
                                    <option key={p} value={p}>
                                        {p}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <form
                        onSubmit={submit}
                        className="space-y-6 overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6"
                    >
                        <div className="max-w-xs">
                            <InputLabel htmlFor="assessed_on" value="Erhebungsdatum" />
                            <TextInput
                                id="assessed_on"
                                type="date"
                                className="mt-1 block w-full"
                                value={data.assessed_on}
                                onChange={(e) => setData('assessed_on', e.target.value)}
                            />
                            <InputError className="mt-2" message={errors.assessed_on} />
                        </div>

                        {areas.map((area) => (
                            <div key={area}>
                                <h4 className="mb-2 text-sm font-bold uppercase tracking-wider text-[#9B1C3B]">
                                    {AREA_LABELS[area]}
                                </h4>
                                <div className="space-y-3">
                                    {indicators
                                        .filter((indicator) => indicator.area === area)
                                        .map((indicator) => (
                                            <div
                                                key={indicator.value}
                                                className="grid gap-2 sm:grid-cols-[1fr_16rem] sm:items-center"
                                            >
                                                <span className="text-sm text-gray-800">
                                                    {indicator.label}
                                                </span>
                                                <select
                                                    className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    value={data.answers[indicator.value] ?? ''}
                                                    onChange={(e) =>
                                                        setData('answers', {
                                                            ...data.answers,
                                                            [indicator.value]: e.target.value,
                                                        })
                                                    }
                                                >
                                                    <option value="">– keine Angabe –</option>
                                                    {indicator.options.map((o) => (
                                                        <option key={o.value} value={o.value}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        ))}
                                </div>
                            </div>
                        ))}

                        <div>
                            <InputLabel htmlFor="note" value="Notiz (optional)" />
                            <textarea
                                id="note"
                                rows={2}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={data.note}
                                onChange={(e) => setData('note', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end">
                            <PrimaryButton disabled={processing}>Erhebung speichern</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

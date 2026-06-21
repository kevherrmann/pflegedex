import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

type Resident = {
    id: string;
    fullName: string;
    locationName: string | null;
};

type CatalogOption = { value: number; label: string };
type CatalogItem = { key: string; label: string; options: CatalogOption[] };
type Definition = { value: string; label: string; catalog: CatalogItem[] };

type AssessmentEntry = {
    id: string;
    type: string;
    typeLabel: string;
    assessedOn: string;
    totalScore: number | null;
    riskLevel: string | null;
    note: string | null;
    nextDue: string | null;
    assessedByName: string | null;
    answers: { label: string; value: string }[];
};

type Props = {
    resident: Resident;
    assessments: AssessmentEntry[];
    definitions: Definition[];
};

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function Index({ resident, assessments, definitions }: Props) {
    const [type, setType] = useState<string>(definitions[0]?.value ?? '');

    const activeCatalog = useMemo<CatalogItem[]>(
        () => definitions.find((d) => d.value === type)?.catalog ?? [],
        [definitions, type],
    );

    const { data, setData, post, processing, errors, reset } = useForm<{
        type: string;
        assessed_on: string;
        answers: Record<string, string>;
        note: string;
    }>({
        type,
        assessed_on: todayIso(),
        answers: {},
        note: '',
    });

    const changeType = (next: string) => {
        setType(next);
        setData((current) => ({ ...current, type: next, answers: {} }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('residents.assessments.store', resident.id), {
            preserveScroll: true,
            onSuccess: () => reset('answers', 'note'),
        });
    };

    const remove = (assessment: AssessmentEntry) => {
        if (!window.confirm('Dieses Assessment wirklich löschen?')) {
            return;
        }
        router.delete(route('residents.assessments.destroy', [resident.id, assessment.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Assessments</h2>
            }
        >
            <Head title="Assessments" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Bewohner-Übersicht
                    </Link>

                    <div className="overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <p className="text-sm text-gray-500">
                            {resident.locationName ?? 'Unbekannter Wohnbereich'}
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-gray-900">
                            {resident.fullName}
                        </h3>
                    </div>

                    <form
                        onSubmit={submit}
                        className="overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6"
                    >
                        <h3 className="text-lg font-semibold text-gray-900">
                            Assessment durchführen
                        </h3>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="type" value="Instrument" />
                                <select
                                    id="type"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={type}
                                    onChange={(e) => changeType(e.target.value)}
                                >
                                    {definitions.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="assessed_on" value="Datum" />
                                <TextInput
                                    id="assessed_on"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={data.assessed_on}
                                    onChange={(e) => setData('assessed_on', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.assessed_on} />
                            </div>
                        </div>

                        <div className="mt-4 space-y-3">
                            {activeCatalog.map((item) => (
                                <div key={item.key}>
                                    <InputLabel htmlFor={`item-${item.key}`} value={item.label} />
                                    <select
                                        id={`item-${item.key}`}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        value={data.answers[item.key] ?? ''}
                                        onChange={(e) =>
                                            setData('answers', {
                                                ...data.answers,
                                                [item.key]: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">– bitte wählen –</option>
                                        {item.options.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.value} – {o.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        className="mt-1"
                                        message={
                                            errors[`answers.${item.key}` as keyof typeof errors]
                                        }
                                    />
                                </div>
                            ))}
                        </div>

                        <div className="mt-4">
                            <InputLabel htmlFor="note" value="Notiz (optional)" />
                            <textarea
                                id="note"
                                rows={2}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={data.note}
                                onChange={(e) => setData('note', e.target.value)}
                            />
                            <InputError className="mt-2" message={errors.note} />
                        </div>

                        <div className="mt-4 flex justify-end">
                            <PrimaryButton disabled={processing}>Speichern</PrimaryButton>
                        </div>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">Verlauf</h3>
                        </div>
                        <div className="space-y-4 p-4 sm:p-6">
                            {assessments.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Noch keine Assessments erfasst.
                                </p>
                            ) : (
                                assessments.map((a) => (
                                    <div
                                        key={a.id}
                                        className="rounded-lg border border-gray-200 p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <h4 className="font-semibold text-gray-900">
                                                    {a.typeLabel}
                                                </h4>
                                                <p className="text-sm text-gray-500">
                                                    {a.assessedOn}
                                                    {a.assessedByName
                                                        ? ` · ${a.assessedByName}`
                                                        : ''}
                                                    {a.nextDue
                                                        ? ` · nächste Bewertung: ${a.nextDue}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold text-gray-900">
                                                    Score: {a.totalScore ?? '–'}
                                                </p>
                                                {a.riskLevel ? (
                                                    <span className="inline-flex rounded-full bg-[#F7E8ED] px-2 py-0.5 text-xs font-semibold text-[#7F1730]">
                                                        {a.riskLevel}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>

                                        <ul className="mt-3 grid gap-1 text-sm text-gray-700 sm:grid-cols-2">
                                            {a.answers.map((ans, idx) => (
                                                <li key={idx}>
                                                    <span className="text-gray-500">
                                                        {ans.label}:
                                                    </span>{' '}
                                                    {ans.value}
                                                </li>
                                            ))}
                                        </ul>

                                        {a.note ? (
                                            <p className="mt-2 text-sm text-gray-600">{a.note}</p>
                                        ) : null}

                                        <div className="mt-3 text-right">
                                            <DangerButton type="button" onClick={() => remove(a)}>
                                                Löschen
                                            </DangerButton>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

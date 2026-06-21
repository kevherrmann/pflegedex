import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Resident = {
    id: string;
    pseudonym: string;
    fullName: string;
    formalName: string;
};

type TopicCatalog = { number: number; label: string };

type Props = {
    resident: Resident;
    topics: TopicCatalog[];
};

type FormShape = {
    grundbotschaft: string;
    topics: Array<{ topic_number: number; content: string }>;
};

export default function Create({ resident, topics }: Props) {
    const { data, setData, post, processing, errors } = useForm<FormShape>({
        grundbotschaft: '',
        topics: topics.map((t) => ({ topic_number: t.number, content: '' })),
    });

    const setTopicContent = (idx: number, value: string) => {
        const next = [...data.topics];
        next[idx] = { ...next[idx], content: value };
        setData('topics', next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('residents.care-plan.store', resident.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    Maßnahmenplan anlegen · {resident.formalName}
                </h2>
            }
        >
            <Head title={`Maßnahmenplan anlegen – ${resident.fullName}`} />

            <div className="py-12">
                <form onSubmit={submit} className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                            Grundbotschaft
                        </h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Kurze, immer geltende Hinweise zum Bewohner (z.B. „Ansprache mit Vorname
                            und Du", „Pflege nur zu zweit"). Optional.
                        </p>
                        <textarea
                            id="grundbotschaft"
                            rows={4}
                            value={data.grundbotschaft}
                            onChange={(e) => setData('grundbotschaft', e.target.value)}
                            className="mt-3 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                        />
                        {errors.grundbotschaft && (
                            <p className="mt-1 text-xs text-red-600">{errors.grundbotschaft}</p>
                        )}
                    </div>

                    <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                            Themenblöcke
                        </h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Nur die Themen befüllen, die für diesen Bewohner relevant sind. Leere
                            Felder werden nicht gespeichert.
                        </p>
                        <div className="mt-4 space-y-5">
                            {topics.map((t, idx) => (
                                <div key={t.number}>
                                    <label
                                        className="block text-sm font-semibold text-gray-800"
                                        htmlFor={`topic-${t.number}`}
                                    >
                                        {t.number}. {t.label}
                                    </label>
                                    <textarea
                                        id={`topic-${t.number}`}
                                        rows={3}
                                        value={data.topics[idx].content}
                                        onChange={(e) => setTopicContent(idx, e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    />
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route('residents.care-plan.show', resident.id)}
                            className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline"
                        >
                            Abbrechen
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-[#7A1430] disabled:opacity-60"
                        >
                            Anlegen
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

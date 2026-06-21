import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = {
    id: string;
    pseudonym: string;
    fullName: string;
    locationId: string;
};

type TopicCatalog = { number: number; label: string };
type RiskCatalog = { kind: string; label: string };

type Props = {
    resident: Resident;
    topics: TopicCatalog[];
    risks: RiskCatalog[];
};

type FormShape = {
    opening_question: string;
    topics: Array<{ topic_number: number; content: string }>;
    risks: Array<{
        risk_kind: string;
        is_at_risk: boolean;
        needs_further_assessment: boolean;
        notes: string;
    }>;
};

export default function Create({ resident, topics, risks }: Props) {
    const aiStatus = (
        usePage().props as {
            ai?: { available: boolean; modelPresent: boolean; reason: string | null };
        }
    ).ai;
    const aiAvailable = (aiStatus?.available ?? false) && (aiStatus?.modelPresent ?? false);

    const { data, setData, post, processing, errors } = useForm<FormShape>({
        opening_question: '',
        topics: topics.map((t) => ({ topic_number: t.number, content: '' })),
        risks: risks.map((r) => ({
            risk_kind: r.kind,
            is_at_risk: false,
            needs_further_assessment: false,
            notes: '',
        })),
    });

    const setTopicContent = (idx: number, value: string) => {
        const next = [...data.topics];
        next[idx] = { ...next[idx], content: value };
        setData('topics', next);
    };

    const setRiskField = <K extends keyof FormShape['risks'][number]>(
        idx: number,
        key: K,
        value: FormShape['risks'][number][K],
    ) => {
        const next = [...data.risks];
        next[idx] = { ...next[idx], [key]: value };
        setData('risks', next);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('residents.sis.store', resident.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    SIS anlegen · {resident.fullName}
                </h2>
            }
        >
            <Head title={`SIS anlegen - ${resident.fullName}`} />
            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                            <p className="mb-4 text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                {resident.pseudonym}
                            </p>
                            <p className="mb-4 text-sm text-gray-600">
                                Erste fachliche Einschätzung der für die Pflege und Betreuung
                                relevanten Risiken und Phänomene. Fertigstellung innerhalb von 14
                                Tagen nach Aufnahme.
                            </p>
                            {aiAvailable ? (
                                <p className="mb-6 rounded-md bg-[#FAE7EC]/40 px-3 py-2 text-xs text-gray-700">
                                    💡 <strong>Stichpunkte reichen.</strong> Beim Anlegen formuliert
                                    die KI automatisch fachlichen Fließtext aus Ihren Notizen. Sie
                                    können das Ergebnis anschließend nochmal überarbeiten.
                                </p>
                            ) : (
                                <p className="mb-6 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    <strong>Hinweis:</strong> Die KI-Ausformulierung ist gerade
                                    nicht verfügbar
                                    {aiStatus?.reason ? ` (${aiStatus.reason})` : ''}. Die SIS wird
                                    so gespeichert, wie Sie sie eingeben — Sie können sie später
                                    nochmal mit KI ausformulieren lassen.
                                </p>
                            )}

                            <label
                                className="block text-sm font-medium text-gray-700"
                                htmlFor="opening_question"
                            >
                                Was bewegt Sie im Augenblick?
                            </label>
                            <textarea
                                id="opening_question"
                                rows={3}
                                value={data.opening_question}
                                onChange={(e) => setData('opening_question', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                            />
                            {errors.opening_question && (
                                <p className="mt-1 text-xs text-red-600">
                                    {errors.opening_question}
                                </p>
                            )}
                        </div>

                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                            <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                Themenfelder
                            </h3>
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
                                            value={data.topics[idx]?.content ?? ''}
                                            onChange={(e) => setTopicContent(idx, e.target.value)}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                            <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                Risikomatrix
                            </h3>
                            <div className="mt-4 space-y-4">
                                {risks.map((r, idx) => (
                                    <div
                                        key={r.kind}
                                        className="rounded-md border border-gray-200 p-4"
                                    >
                                        <p className="font-medium text-gray-800">{r.label}</p>
                                        <div className="mt-3 flex flex-wrap gap-6">
                                            <label className="inline-flex items-center text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={data.risks[idx]?.is_at_risk ?? false}
                                                    onChange={(e) =>
                                                        setRiskField(
                                                            idx,
                                                            'is_at_risk',
                                                            e.target.checked,
                                                        )
                                                    }
                                                    className="mr-2 rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                />
                                                Risiko erkannt
                                            </label>
                                            <label className="inline-flex items-center text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        data.risks[idx]?.needs_further_assessment ??
                                                        false
                                                    }
                                                    onChange={(e) =>
                                                        setRiskField(
                                                            idx,
                                                            'needs_further_assessment',
                                                            e.target.checked,
                                                        )
                                                    }
                                                    className="mr-2 rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                />
                                                Weitere Einschätzung nötig
                                            </label>
                                        </div>
                                        <textarea
                                            placeholder="Notizen (optional)"
                                            rows={2}
                                            value={data.risks[idx]?.notes ?? ''}
                                            onChange={(e) =>
                                                setRiskField(idx, 'notes', e.target.value)
                                            }
                                            className="mt-3 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full rounded-md bg-[#9B1C3B] px-6 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-[#7A1430] disabled:opacity-50 sm:w-auto"
                            >
                                SIS anlegen{aiAvailable ? ' & mit KI ausformulieren' : ''}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

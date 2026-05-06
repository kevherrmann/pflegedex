import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Resident = {
    id: string;
    pseudonym: string;
    fullName: string;
    locationId: string;
};

type SisTopic = { topicNumber: number; content: string | null };
type SisRisk = {
    riskKind: string;
    isAtRisk: boolean;
    needsFurtherAssessment: boolean;
    notes: string | null;
};

type Sis = {
    id: string;
    openingQuestion: string | null;
    startedAt: string | null;
    completedAt: string | null;
    evaluatedAt: string | null;
    nextEvaluationDue: string | null;
    isOverdue: boolean;
    versionCount: number;
    topics: SisTopic[];
    risks: SisRisk[];
};

type TopicCatalog = { number: number; label: string };
type RiskCatalog = { kind: string; label: string };

type Props = {
    resident: Resident;
    sis: Sis | null;
    canEdit: boolean;
    topics: TopicCatalog[];
    risks: RiskCatalog[];
};

export default function Show({ resident, sis, canEdit, topics, risks }: Props) {
    const handleEvaluate = () => {
        if (!confirm('SIS-Evaluation jetzt speichern? Der nächste Termin wird auf +8 Wochen gesetzt.')) {
            return;
        }
        router.post(route('residents.sis.evaluate', resident.id));
    };

    const topicByNumber = new Map(sis?.topics.map((t) => [t.topicNumber, t]) ?? []);
    const riskByKind = new Map(sis?.risks.map((r) => [r.riskKind, r]) ?? []);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    SIS · {resident.fullName} <span className="text-sm font-normal text-gray-500">({resident.pseudonym})</span>
                </h2>
            }
        >
            <Head title={`SIS - ${resident.fullName}`} />
            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {sis === null ? (
                        <div className="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-[#E5E7EB]">
                            <p className="text-gray-700">Für diesen Bewohner ist noch keine SIS angelegt.</p>
                            {canEdit && (
                                <Link
                                    href={route('residents.sis.create', resident.id)}
                                    className="mt-4 inline-block rounded-md bg-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-[#7A1430]"
                                >
                                    SIS anlegen
                                </Link>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* Header-Karte mit Fristen */}
                            <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">Strukturierte Informationssammlung</p>
                                        <p className="mt-2 text-sm text-gray-600">
                                            Begonnen: {sis.startedAt ?? '—'} · Fertiggestellt: {sis.completedAt ?? 'offen'} · Letzte Evaluation:{' '}
                                            {sis.evaluatedAt ?? 'noch keine'}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            Nächste Evaluation:{' '}
                                            {sis.nextEvaluationDue ? (
                                                <span className={sis.isOverdue ? 'font-semibold text-red-700' : 'text-gray-700'}>
                                                    {sis.nextEvaluationDue}
                                                    {sis.isOverdue && ' (überfällig)'}
                                                </span>
                                            ) : (
                                                <span className="text-gray-500">noch nicht geplant</span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-xs text-gray-500">{sis.versionCount} Version(en) im Archiv.</p>
                                    </div>
                                    {canEdit && (
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={route('residents.sis.edit', resident.id)}
                                                className="rounded-md border border-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#9B1C3B] hover:bg-[#FAE7EC]"
                                            >
                                                Bearbeiten
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={handleEvaluate}
                                                className="rounded-md bg-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-[#7A1430]"
                                            >
                                                Evaluieren
                                            </button>
                                        </div>
                                    )}
                                </div>
                                {sis.openingQuestion && (
                                    <div className="mt-6 rounded-md bg-[#FAE7EC]/30 p-4">
                                        <p className="text-xs font-bold uppercase tracking-widest text-[#9B1C3B]">Was bewegt Sie?</p>
                                        <p className="mt-1 whitespace-pre-line text-sm text-gray-800">{sis.openingQuestion}</p>
                                    </div>
                                )}
                            </div>

                            {/* Themenfelder */}
                            <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                                <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">Themenfelder</h3>
                                <ul className="mt-4 space-y-4">
                                    {topics.map((t) => {
                                        const entry = topicByNumber.get(t.number);
                                        return (
                                            <li key={t.number} className="border-l-4 border-[#9B1C3B] pl-4">
                                                <p className="text-sm font-semibold text-gray-800">
                                                    {t.number}. {t.label}
                                                </p>
                                                <p className="mt-1 whitespace-pre-line text-sm text-gray-700">
                                                    {entry?.content || <span className="italic text-gray-400">Keine Angabe.</span>}
                                                </p>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>

                            {/* Risikomatrix */}
                            <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                                <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">Risikomatrix</h3>
                                <table className="mt-4 w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-widest text-gray-500">
                                            <th className="py-2">Risiko</th>
                                            <th className="py-2">Risiko erkannt</th>
                                            <th className="py-2">Weitere Einschätzung nötig</th>
                                            <th className="py-2">Notizen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {risks.map((r) => {
                                            const entry = riskByKind.get(r.kind);
                                            return (
                                                <tr key={r.kind} className="border-b border-gray-100">
                                                    <td className="py-3 font-medium text-gray-800">{r.label}</td>
                                                    <td className="py-3">{entry?.isAtRisk ? '✓ ja' : '— nein'}</td>
                                                    <td className="py-3">{entry?.needsFurtherAssessment ? '✓ ja' : '— nein'}</td>
                                                    <td className="py-3 text-gray-700">{entry?.notes || <span className="text-gray-400">—</span>}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

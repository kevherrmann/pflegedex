import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

type Generation = {
    id: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | string;
    progress: number;
    totalSteps: number;
    errorMessage: string | null;
};

type Props = {
    resident: Resident;
    sis: Sis | null;
    canEdit: boolean;
    carePlanExists: boolean;
    topics: TopicCatalog[];
    risks: RiskCatalog[];
    latestGeneration: Generation | null;
};

export default function Show({
    resident,
    sis,
    canEdit,
    carePlanExists,
    topics,
    risks,
    latestGeneration,
}: Props) {
    const [generation, setGeneration] = useState<Generation | null>(latestGeneration);

    // Polling: solange status='pending' oder 'running' ist, alle 2s den Status holen.
    // Sobald terminal (completed/failed): ein letztes router.reload(), damit die SIS-Texte
    // aus der DB neu geladen werden.
    useEffect(() => {
        if (generation === null) return;
        if (generation.status === 'completed' || generation.status === 'failed') return;

        const handle = setInterval(async () => {
            try {
                const url = route('residents.sis.generate.show', [resident.id, generation.id]);
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = (await res.json()) as Generation;
                setGeneration(data);
                if (data.status === 'completed' || data.status === 'failed') {
                    clearInterval(handle);
                    if (data.status === 'completed') {
                        router.reload({ only: ['sis', 'latestGeneration'] });
                    }
                }
            } catch {
                // Netzwerk-Fehler stillschweigend ignorieren - der User sieht den
                // letzten bekannten Status, naechster Tick holt nach.
            }
        }, 2000);

        return () => clearInterval(handle);
    }, [generation, resident.id]);

    const handleEvaluate = () => {
        if (
            !confirm(
                'SIS-Evaluation jetzt speichern? Der nächste Termin wird auf +8 Wochen gesetzt.',
            )
        ) {
            return;
        }
        router.post(route('residents.sis.evaluate', resident.id));
    };

    const handleComplete = () => {
        if (
            !confirm(
                'SIS jetzt fachlich fertigstellen? Damit ist sie als abgeschlossen markiert und ein Maßnahmenplan kann erstellt werden. Inhalte bleiben weiter editierbar.',
            )
        ) {
            return;
        }
        router.post(route('residents.sis.complete', resident.id));
    };

    const handleGenerateCarePlan = () => {
        if (
            !confirm(
                'KI-Erstellung des Maßnahmenplans starten? Das dauert je nach Auslastung mehrere Minuten. Du kannst die Seite verlassen und später zurückkehren.',
            )
        ) {
            return;
        }
        router.post(route('residents.care-plan.generate.start', resident.id));
    };

    const isRunning =
        generation !== null && (generation.status === 'pending' || generation.status === 'running');
    const justFailed = generation !== null && generation.status === 'failed';

    const aiStatus = (
        usePage().props as {
            ai?: { available: boolean; modelPresent: boolean; reason: string | null };
        }
    ).ai;
    const aiAvailable = (aiStatus?.available ?? false) && (aiStatus?.modelPresent ?? false);
    const topicByNumber = new Map(sis?.topics.map((t) => [t.topicNumber, t]) ?? []);
    const riskByKind = new Map(sis?.risks.map((r) => [r.riskKind, r]) ?? []);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    SIS · {resident.fullName}{' '}
                    <span className="text-sm font-normal text-gray-500">
                        ({resident.pseudonym})
                    </span>
                </h2>
            }
        >
            <Head title={`SIS - ${resident.fullName}`} />
            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {isRunning && generation !== null && (
                        <div className="rounded-2xl border border-[#9B1C3B]/30 bg-[#FAE7EC]/40 p-4 shadow-sm sm:p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-3 w-3 animate-pulse rounded-full bg-[#9B1C3B]"></div>
                                <div className="flex-1">
                                    <p className="text-sm font-bold uppercase tracking-widest text-[#9B1C3B]">
                                        KI-Ausformulierung läuft
                                    </p>
                                    <p className="mt-1 text-xs text-gray-700">
                                        Schritt {generation.progress} von {generation.totalSteps} ·
                                        {generation.status === 'pending'
                                            ? ' wartet auf Worker …'
                                            : ' formuliert …'}
                                    </p>
                                    <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-white">
                                        <div
                                            className="h-full bg-[#9B1C3B] transition-all"
                                            style={{
                                                width: `${
                                                    generation.totalSteps > 0
                                                        ? Math.round(
                                                              (generation.progress /
                                                                  generation.totalSteps) *
                                                                  100,
                                                          )
                                                        : 0
                                                }%`,
                                            }}
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {justFailed && generation !== null && (
                        <div className="rounded-2xl border border-red-300 bg-red-50 p-4 shadow-sm sm:p-6">
                            <p className="text-sm font-bold uppercase tracking-widest text-red-800">
                                KI-Generierung fehlgeschlagen
                            </p>
                            <p className="mt-1 text-xs text-red-900">
                                {generation.errorMessage ??
                                    'Unbekannter Fehler. Bitte erneut versuchen.'}
                            </p>
                            <p className="mt-1 text-xs text-red-900">
                                Die Stichpunkte sind weiterhin gespeichert — Sie können den Versuch
                                über „Bearbeiten" wiederholen.
                            </p>
                        </div>
                    )}

                    {canEdit && !aiAvailable && !isRunning && (
                        <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-widest text-amber-900">
                                KI-Funktion nicht verfügbar
                            </p>
                            <p className="mt-1 text-xs text-amber-900">
                                {aiStatus?.reason ??
                                    'Ollama-Service oder Modell ist gerade nicht erreichbar.'}{' '}
                                Speichern und Bearbeiten funktioniert weiterhin normal.
                            </p>
                        </div>
                    )}
                    {sis === null ? (
                        <div className="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                            <p className="text-gray-700">
                                Für diesen Bewohner ist noch keine SIS angelegt.
                            </p>
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
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                            Strukturierte Informationssammlung
                                        </p>
                                        <p className="mt-2 text-sm text-gray-600">
                                            Begonnen: {sis.startedAt ?? '—'} · Fertiggestellt:{' '}
                                            {sis.completedAt ?? 'offen'} · Letzte Evaluation:{' '}
                                            {sis.evaluatedAt ?? 'noch keine'}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            Nächste Evaluation:{' '}
                                            {sis.nextEvaluationDue ? (
                                                <span
                                                    className={
                                                        sis.isOverdue
                                                            ? 'font-semibold text-red-700'
                                                            : 'text-gray-700'
                                                    }
                                                >
                                                    {sis.nextEvaluationDue}
                                                    {sis.isOverdue && ' (überfällig)'}
                                                </span>
                                            ) : (
                                                <span className="text-gray-500">
                                                    noch nicht geplant
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-xs text-gray-500">
                                            {sis.versionCount} Version(en) im Archiv.
                                        </p>
                                    </div>
                                    {canEdit && (
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={route('residents.sis.edit', resident.id)}
                                                className="rounded-md border border-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#9B1C3B] hover:bg-[#FAE7EC]"
                                            >
                                                Bearbeiten
                                            </Link>
                                            <a
                                                href={route('residents.sis.pdf', resident.id)}
                                                className="rounded-md border border-[#54595F] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#54595F] hover:bg-gray-50"
                                            >
                                                PDF
                                            </a>
                                            {sis.completedAt === null && (
                                                <button
                                                    type="button"
                                                    onClick={handleComplete}
                                                    className="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-emerald-800"
                                                >
                                                    Fertigstellen
                                                </button>
                                            )}
                                            {sis.completedAt !== null && !carePlanExists && (
                                                <button
                                                    type="button"
                                                    onClick={handleGenerateCarePlan}
                                                    className="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-emerald-800"
                                                >
                                                    MP generieren
                                                </button>
                                            )}
                                            {sis.completedAt !== null && carePlanExists && (
                                                <Link
                                                    href={route(
                                                        'residents.care-plan.show',
                                                        resident.id,
                                                    )}
                                                    className="rounded-md border border-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#9B1C3B] hover:bg-[#FAE7EC]"
                                                >
                                                    Maßnahmenplan
                                                </Link>
                                            )}
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
                                        <p className="text-xs font-bold uppercase tracking-widest text-[#9B1C3B]">
                                            Was bewegt Sie?
                                        </p>
                                        <p className="mt-1 whitespace-pre-line text-sm text-gray-800">
                                            {sis.openingQuestion}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Themenfelder */}
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                    Themenfelder
                                </h3>
                                <ul className="mt-4 space-y-4">
                                    {topics.map((t) => {
                                        const entry = topicByNumber.get(t.number);
                                        return (
                                            <li
                                                key={t.number}
                                                className="border-l-4 border-[#9B1C3B] pl-4"
                                            >
                                                <p className="text-sm font-semibold text-gray-800">
                                                    {t.number}. {t.label}
                                                </p>
                                                <p className="mt-1 whitespace-pre-line text-sm text-gray-700">
                                                    {entry?.content || (
                                                        <span className="italic text-gray-400">
                                                            Keine Angabe.
                                                        </span>
                                                    )}
                                                </p>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>

                            {/* Risikomatrix */}
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                    Risikomatrix
                                </h3>
                                <div className="overflow-x-auto">
                                    <table className="mt-4 w-full min-w-[32rem] text-sm">
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
                                                    <tr
                                                        key={r.kind}
                                                        className="border-b border-gray-100"
                                                    >
                                                        <td className="py-3 font-medium text-gray-800">
                                                            {r.label}
                                                        </td>
                                                        <td className="py-3">
                                                            {entry?.isAtRisk ? '✓ ja' : '— nein'}
                                                        </td>
                                                        <td className="py-3">
                                                            {entry?.needsFurtherAssessment
                                                                ? '✓ ja'
                                                                : '— nein'}
                                                        </td>
                                                        <td className="py-3 text-gray-700">
                                                            {entry?.notes || (
                                                                <span className="text-gray-400">
                                                                    —
                                                                </span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

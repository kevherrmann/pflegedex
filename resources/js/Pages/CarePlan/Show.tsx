import Markdown from '@/Components/Markdown';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Resident = {
    id: string;
    pseudonym: string;
    fullName: string;
    formalName: string;
};

type CarePlanTopicEntry = { topicNumber: number; content: string };

type CarePlan = {
    id: string;
    grundbotschaft: string | null;
    startedAt: string | null;
    evaluatedAt: string | null;
    nextEvaluationDue: string | null;
    isOverdue: boolean;
    versionCount: number;
    topics: CarePlanTopicEntry[];
};

type SisStatus = {
    exists: boolean;
    completed: boolean;
    completedAt: string | null;
};

type TopicCatalog = { number: number; label: string };

type Generation = {
    id: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | string;
    progress: number;
    totalSteps: number;
    errorMessage: string | null;
};

type Props = {
    resident: Resident;
    carePlan: CarePlan | null;
    sisStatus: SisStatus;
    canEdit: boolean;
    topics: TopicCatalog[];
    latestGeneration: Generation | null;
};

export default function Show({
    resident,
    carePlan,
    sisStatus,
    canEdit,
    topics,
    latestGeneration,
}: Props) {
    const [generation, setGeneration] = useState<Generation | null>(latestGeneration);

    useEffect(() => {
        if (generation === null) return;
        if (generation.status === 'completed' || generation.status === 'failed') return;

        const handle = setInterval(async () => {
            try {
                const url = route('residents.care-plan.generate.show', [
                    resident.id,
                    generation.id,
                ]);
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = (await res.json()) as Generation;
                setGeneration(data);
                if (data.status === 'completed' || data.status === 'failed') {
                    clearInterval(handle);
                    if (data.status === 'completed') {
                        router.reload({ only: ['carePlan', 'latestGeneration'] });
                    }
                }
            } catch {
                // Netzwerk-Fehler stillschweigend ignorieren - naechster Tick holt nach.
            }
        }, 2000);

        return () => clearInterval(handle);
    }, [generation, resident.id]);

    const handleEvaluate = () => {
        if (
            !confirm(
                'Maßnahmenplan-Evaluation jetzt speichern? Der nächste Termin wird auf +8 Wochen gesetzt.',
            )
        ) {
            return;
        }
        router.post(route('residents.care-plan.evaluate', resident.id));
    };

    const handleRegenerate = () => {
        if (
            !confirm(
                'KI-Erstellung erneut starten? Der bestehende Maßnahmenplan wird durch das Ergebnis überschrieben (vorheriger Stand bleibt im Versionsarchiv erhalten).',
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

    const labelByNumber = new Map(topics.map((t) => [t.number, t.label]));

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    Maßnahmenplan · {resident.formalName}
                </h2>
            }
        >
            <Head title={`Maßnahmenplan – ${resident.fullName}`} />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {isRunning && generation !== null && (
                        <div className="rounded-2xl border border-[#9B1C3B]/30 bg-[#FAE7EC]/40 p-4 shadow-sm sm:p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-3 w-3 animate-pulse rounded-full bg-[#9B1C3B]"></div>
                                <div className="flex-1">
                                    <p className="text-sm font-bold uppercase tracking-widest text-[#9B1C3B]">
                                        KI-Erstellung des Maßnahmenplans läuft
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
                                Der Maßnahmenplan ist weiterhin angelegt — Sie können den Versuch
                                über „Erneut generieren" wiederholen oder den MP manuell bearbeiten.
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
                                Bearbeiten und Speichern funktioniert weiterhin normal.
                            </p>
                        </div>
                    )}

                    {!sisStatus.exists && (
                        <div className="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 sm:p-6">
                            <p className="text-sm font-semibold text-amber-900">SIS fehlt</p>
                            <p className="mt-1 text-sm text-amber-800">
                                Für diesen Bewohner ist noch keine SIS angelegt. Der Maßnahmenplan
                                kann erst nach Fertigstellung der SIS erstellt werden.
                            </p>
                            <Link
                                href={route('residents.sis.show', resident.id)}
                                className="mt-3 inline-block text-sm font-semibold text-amber-900 underline"
                            >
                                Zur SIS
                            </Link>
                        </div>
                    )}
                    {sisStatus.exists && !sisStatus.completed && (
                        <div className="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 sm:p-6">
                            <p className="text-sm font-semibold text-amber-900">
                                SIS noch nicht fertiggestellt
                            </p>
                            <p className="mt-1 text-sm text-amber-800">
                                Der Maßnahmenplan kann erst angelegt werden, wenn die SIS fachlich
                                fertiggestellt wurde (Button „Fertigstellen" auf der SIS-Seite).
                            </p>
                            <Link
                                href={route('residents.sis.show', resident.id)}
                                className="mt-3 inline-block text-sm font-semibold text-amber-900 underline"
                            >
                                Zur SIS
                            </Link>
                        </div>
                    )}

                    {carePlan === null ? (
                        sisStatus.completed && (
                            <div className="rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <p className="text-gray-700">
                                    Für diesen Bewohner ist noch kein Maßnahmenplan angelegt.
                                </p>
                                <p className="mt-1 text-xs text-gray-500">
                                    SIS fertiggestellt am {sisStatus.completedAt ?? '—'}.
                                </p>
                                {canEdit && (
                                    <p className="mt-3 text-xs text-gray-500">
                                        Über die SIS-Seite kann ein Maßnahmenplan per Button „MP
                                        generieren" aus der SIS erstellt werden.
                                    </p>
                                )}
                            </div>
                        )
                    ) : (
                        <>
                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                            Maßnahmenplan
                                        </p>
                                        <p className="mt-2 text-sm text-gray-600">
                                            Begonnen: {carePlan.startedAt ?? '—'} · Letzte
                                            Evaluation: {carePlan.evaluatedAt ?? 'noch keine'}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            Nächste Evaluation:{' '}
                                            {carePlan.nextEvaluationDue ? (
                                                <span
                                                    className={
                                                        carePlan.isOverdue
                                                            ? 'font-semibold text-red-700'
                                                            : 'text-gray-700'
                                                    }
                                                >
                                                    {carePlan.nextEvaluationDue}
                                                    {carePlan.isOverdue && ' (überfällig)'}
                                                </span>
                                            ) : (
                                                <span className="text-gray-500">
                                                    noch nicht geplant
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-xs text-gray-500">
                                            {carePlan.versionCount} Version(en) im Archiv.
                                        </p>
                                    </div>
                                    {canEdit && (
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={route(
                                                    'residents.care-plan.edit',
                                                    resident.id,
                                                )}
                                                className="rounded-md border border-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#9B1C3B] hover:bg-[#FAE7EC]"
                                            >
                                                Bearbeiten
                                            </Link>
                                            <a
                                                href={route('residents.care-plan.pdf', resident.id)}
                                                className="rounded-md border border-[#54595F] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-[#54595F] hover:bg-gray-50"
                                            >
                                                PDF
                                            </a>
                                            {!isRunning && (
                                                <button
                                                    type="button"
                                                    onClick={handleRegenerate}
                                                    disabled={!aiAvailable}
                                                    className="rounded-md border border-emerald-700 px-4 py-2 text-sm font-semibold uppercase tracking-widest text-emerald-700 hover:bg-emerald-50 disabled:opacity-50"
                                                    title={
                                                        aiAvailable ? '' : 'KI ist nicht verfügbar'
                                                    }
                                                >
                                                    Erneut generieren
                                                </button>
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

                                {carePlan.grundbotschaft && (
                                    <div className="mt-6 rounded-md bg-[#FAE7EC]/30 p-4">
                                        <p className="text-xs font-bold uppercase tracking-widest text-[#9B1C3B]">
                                            Grundbotschaft
                                        </p>
                                        <Markdown
                                            className="mt-1"
                                            content={carePlan.grundbotschaft}
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                                <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                    Themenblöcke
                                </h3>
                                {carePlan.topics.length === 0 ? (
                                    <p className="mt-4 text-sm text-gray-500">
                                        Noch keine Themenblöcke befüllt. Über „Bearbeiten" können
                                        relevante Themen ergänzt werden.
                                    </p>
                                ) : (
                                    <div className="mt-4 space-y-5">
                                        {carePlan.topics.map((t) => (
                                            <div key={t.topicNumber}>
                                                <p className="text-sm font-semibold text-gray-800">
                                                    {t.topicNumber}.{' '}
                                                    {labelByNumber.get(t.topicNumber) ?? '—'}
                                                </p>
                                                <Markdown className="mt-1" content={t.content} />
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

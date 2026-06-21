import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type TodoItem = {
    residentId: string;
    pseudonym: string;
    name: string;
    severity: 'red' | 'yellow';
    startedAt?: string;
    dueDate?: string;
    completedAt?: string;
    assessmentType?: string;
};

type RunningItem = {
    generationId: string;
    kind: 'sis' | 'mp';
    residentId: string;
    pseudonym: string;
    name: string;
    status: string;
    progress: number;
    totalSteps: number;
    startedAt: string | null;
};

type FailedItem = {
    generationId: string;
    kind: 'sis' | 'mp';
    residentId: string;
    pseudonym: string;
    name: string;
    errorMessage: string | null;
    finishedAt: string | null;
};

type RecentItem = {
    id: string;
    pseudonym: string;
    name: string;
    locationName: string | null;
    createdAt: string | null;
    hasSis: boolean;
    sisCompleted: boolean;
    hasCarePlan: boolean;
};

type Props = {
    todo: {
        sisOverdueAdmission: TodoItem[];
        sisEvalOverdue: TodoItem[];
        sisEvalSoon: TodoItem[];
        mpEvalOverdue: TodoItem[];
        mpEvalSoon: TodoItem[];
        sisCompletedNoMp: TodoItem[];
        assessmentEvalOverdue: TodoItem[];
        assessmentEvalSoon: TodoItem[];
        totalRed: number;
        totalYellow: number;
        totalGap: number;
    };
    running: {
        sisActive: RunningItem[];
        mpActive: RunningItem[];
        sisFailed: FailedItem[];
        mpFailed: FailedItem[];
    };
    recent: RecentItem[];
};

function targetRoute(kind: 'sis' | 'mp', residentId: string): string {
    return kind === 'sis'
        ? route('residents.sis.show', residentId)
        : route('residents.care-plan.show', residentId);
}

function TodoRow({
    item,
    label,
    targetRoute: target,
    extraText,
}: {
    item: TodoItem;
    label: string;
    targetRoute: string;
    extraText: string | null;
}) {
    const dotClass = item.severity === 'red' ? 'bg-red-600' : 'bg-amber-500';
    return (
        <Link
            href={target}
            className="flex items-center gap-3 rounded-md px-3 py-2 hover:bg-gray-50"
        >
            <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${dotClass}`} />
            <span className="flex flex-1 flex-wrap items-baseline gap-x-2">
                <span className="text-sm font-semibold text-gray-800">{item.name}</span>
                <span className="text-xs text-gray-500">{item.pseudonym}</span>
                <span className="text-xs text-gray-600">· {label}</span>
                {extraText && <span className="text-xs text-gray-500">· {extraText}</span>}
            </span>
        </Link>
    );
}

export default function Dashboard({ todo, running, recent }: Props) {
    const allTodoEmpty = todo.totalRed === 0 && todo.totalYellow === 0 && todo.totalGap === 0;
    const anyRunning =
        running.sisActive.length > 0 ||
        running.mpActive.length > 0 ||
        running.sisFailed.length > 0 ||
        running.mpFailed.length > 0;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Pflegedex
                    </p>
                    <h2 className="mt-1 text-xl font-semibold leading-tight text-[#333333]">
                        Dashboard
                    </h2>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* ============ Block 1: Was muss ich tun? ============ */}
                    <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                            <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                Aufgaben
                            </h3>
                            <div className="flex gap-4 text-xs">
                                {todo.totalRed > 0 && (
                                    <span className="font-semibold text-red-700">
                                        {todo.totalRed} überfällig
                                    </span>
                                )}
                                {todo.totalYellow > 0 && (
                                    <span className="font-semibold text-amber-700">
                                        {todo.totalYellow} demnächst
                                    </span>
                                )}
                                {todo.totalGap > 0 && (
                                    <span className="font-semibold text-amber-700">
                                        {todo.totalGap} ohne MP
                                    </span>
                                )}
                            </div>
                        </div>

                        {allTodoEmpty ? (
                            <p className="mt-4 text-sm text-gray-500">
                                Nichts überfällig, nichts in den nächsten 7 Tagen fällig.
                            </p>
                        ) : (
                            <div className="mt-4 space-y-1">
                                {todo.sisOverdueAdmission.map((item) => (
                                    <TodoRow
                                        key={`sis-adm-${item.residentId}`}
                                        item={item}
                                        label="SIS nicht fertiggestellt (>14 Tage)"
                                        targetRoute={targetRoute('sis', item.residentId)}
                                        extraText={
                                            item.startedAt ? `begonnen ${item.startedAt}` : null
                                        }
                                    />
                                ))}
                                {todo.sisEvalOverdue.map((item) => (
                                    <TodoRow
                                        key={`sis-eo-${item.residentId}`}
                                        item={item}
                                        label="SIS-Evaluation überfällig"
                                        targetRoute={targetRoute('sis', item.residentId)}
                                        extraText={
                                            item.dueDate ? `fällig seit ${item.dueDate}` : null
                                        }
                                    />
                                ))}
                                {todo.mpEvalOverdue.map((item) => (
                                    <TodoRow
                                        key={`mp-eo-${item.residentId}`}
                                        item={item}
                                        label="MP-Evaluation überfällig"
                                        targetRoute={targetRoute('mp', item.residentId)}
                                        extraText={
                                            item.dueDate ? `fällig seit ${item.dueDate}` : null
                                        }
                                    />
                                ))}
                                {todo.assessmentEvalOverdue.map((item) => (
                                    <TodoRow
                                        key={`as-eo-${item.residentId}-${item.assessmentType}`}
                                        item={item}
                                        label={`Assessment überfällig: ${item.assessmentType}`}
                                        targetRoute={route(
                                            'residents.assessments.index',
                                            item.residentId,
                                        )}
                                        extraText={
                                            item.dueDate ? `fällig seit ${item.dueDate}` : null
                                        }
                                    />
                                ))}
                                {todo.sisEvalSoon.map((item) => (
                                    <TodoRow
                                        key={`sis-es-${item.residentId}`}
                                        item={item}
                                        label="SIS-Evaluation in <7 Tagen"
                                        targetRoute={targetRoute('sis', item.residentId)}
                                        extraText={item.dueDate ?? null}
                                    />
                                ))}
                                {todo.mpEvalSoon.map((item) => (
                                    <TodoRow
                                        key={`mp-es-${item.residentId}`}
                                        item={item}
                                        label="MP-Evaluation in <7 Tagen"
                                        targetRoute={targetRoute('mp', item.residentId)}
                                        extraText={item.dueDate ?? null}
                                    />
                                ))}
                                {todo.assessmentEvalSoon.map((item) => (
                                    <TodoRow
                                        key={`as-es-${item.residentId}-${item.assessmentType}`}
                                        item={item}
                                        label={`Assessment in <7 Tagen: ${item.assessmentType}`}
                                        targetRoute={route(
                                            'residents.assessments.index',
                                            item.residentId,
                                        )}
                                        extraText={item.dueDate ?? null}
                                    />
                                ))}
                                {todo.sisCompletedNoMp.map((item) => (
                                    <TodoRow
                                        key={`nomp-${item.residentId}`}
                                        item={item}
                                        label="SIS fertig, MP fehlt"
                                        targetRoute={targetRoute('sis', item.residentId)}
                                        extraText={
                                            item.completedAt ? `seit ${item.completedAt}` : null
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </section>

                    {/* ============ Block 2: Was läuft gerade? ============ */}
                    {anyRunning && (
                        <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                            <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                KI-Generierungen
                            </h3>

                            {(running.sisActive.length > 0 || running.mpActive.length > 0) && (
                                <div className="mt-4">
                                    <p className="text-xs font-semibold uppercase tracking-widest text-emerald-700">
                                        Aktiv
                                    </p>
                                    <div className="mt-2 space-y-2">
                                        {[...running.sisActive, ...running.mpActive].map((g) => {
                                            const pct =
                                                g.totalSteps > 0
                                                    ? Math.round((g.progress / g.totalSteps) * 100)
                                                    : 0;
                                            return (
                                                <Link
                                                    key={g.generationId}
                                                    href={targetRoute(g.kind, g.residentId)}
                                                    className="block rounded-md px-3 py-2 hover:bg-gray-50"
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm">
                                                            <span className="font-semibold text-gray-800">
                                                                {g.name}
                                                            </span>
                                                            <span className="ml-2 text-xs text-gray-500">
                                                                {g.pseudonym}
                                                            </span>
                                                            <span className="ml-3 rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                                                {g.kind === 'sis' ? 'SIS' : 'MP'}
                                                            </span>
                                                        </div>
                                                        <div className="text-xs text-gray-600">
                                                            {g.progress} / {g.totalSteps} (
                                                            {g.status})
                                                        </div>
                                                    </div>
                                                    <div className="mt-1 h-1 w-full overflow-hidden rounded-full bg-gray-200">
                                                        <div
                                                            className="h-full bg-emerald-600 transition-all"
                                                            style={{ width: `${pct}%` }}
                                                        />
                                                    </div>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {(running.sisFailed.length > 0 || running.mpFailed.length > 0) && (
                                <div className="mt-4">
                                    <p className="text-xs font-semibold uppercase tracking-widest text-red-700">
                                        Fehlgeschlagen
                                    </p>
                                    <div className="mt-2 space-y-2">
                                        {[...running.sisFailed, ...running.mpFailed].map((g) => (
                                            <Link
                                                key={g.generationId}
                                                href={targetRoute(g.kind, g.residentId)}
                                                className="block rounded-md px-3 py-2 hover:bg-gray-50"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div className="text-sm">
                                                        <span className="font-semibold text-gray-800">
                                                            {g.name}
                                                        </span>
                                                        <span className="ml-2 text-xs text-gray-500">
                                                            {g.pseudonym}
                                                        </span>
                                                        <span className="ml-3 rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800">
                                                            {g.kind === 'sis' ? 'SIS' : 'MP'}
                                                        </span>
                                                    </div>
                                                </div>
                                                {g.errorMessage && (
                                                    <p className="mt-1 text-xs text-red-700">
                                                        {g.errorMessage}
                                                    </p>
                                                )}
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </section>
                    )}

                    {/* ============ Block 3: Schnellzugriff ============ */}
                    <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                            <h3 className="text-base font-bold uppercase tracking-widest text-[#333333]">
                                Zuletzt aufgenommen
                            </h3>
                            <Link
                                href={route('residents.index')}
                                className="text-xs font-semibold uppercase tracking-widest text-[#9B1C3B] hover:underline"
                            >
                                Alle Bewohner
                            </Link>
                        </div>

                        {recent.length === 0 ? (
                            <p className="mt-4 text-sm text-gray-500">Noch keine Bewohner.</p>
                        ) : (
                            <div className="mt-4 space-y-1">
                                {recent.map((r) => (
                                    <div
                                        key={r.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2 hover:bg-gray-50"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={route('residents.edit', r.id)}
                                                className="text-sm font-semibold text-gray-800 hover:underline"
                                            >
                                                {r.name}
                                            </Link>
                                            <span className="ml-2 text-xs text-gray-500">
                                                {r.pseudonym}
                                            </span>
                                            {r.locationName && (
                                                <span className="ml-2 text-xs text-gray-500">
                                                    · {r.locationName}
                                                </span>
                                            )}
                                            {r.createdAt && (
                                                <span className="ml-2 text-xs text-gray-500">
                                                    · seit {r.createdAt}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 gap-2">
                                            {r.hasSis ? (
                                                <Link
                                                    href={route('residents.sis.show', r.id)}
                                                    className={`rounded px-2 py-0.5 text-xs font-semibold ${
                                                        r.sisCompleted
                                                            ? 'bg-emerald-50 text-emerald-800'
                                                            : 'bg-amber-50 text-amber-800'
                                                    }`}
                                                >
                                                    SIS {r.sisCompleted ? '✓' : '…'}
                                                </Link>
                                            ) : (
                                                <Link
                                                    href={route('residents.sis.show', r.id)}
                                                    className="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600"
                                                >
                                                    SIS —
                                                </Link>
                                            )}
                                            {r.hasCarePlan ? (
                                                <Link
                                                    href={route('residents.care-plan.show', r.id)}
                                                    className="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800"
                                                >
                                                    MP ✓
                                                </Link>
                                            ) : (
                                                <Link
                                                    href={route('residents.care-plan.show', r.id)}
                                                    className="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600"
                                                >
                                                    MP —
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

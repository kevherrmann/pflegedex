import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';

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

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function StatCard({
    value,
    label,
    hint,
    tone,
}: {
    value: number;
    label: string;
    hint: string;
    tone: 'red' | 'amber' | 'neutral';
}) {
    const toneClass =
        value === 0
            ? 'text-gray-300'
            : tone === 'red'
              ? 'text-red-600'
              : tone === 'amber'
                ? 'text-amber-600'
                : 'text-[#9B1C3B]';

    return (
        <div className="rounded-2xl bg-white p-4 text-center shadow-sm ring-1 ring-[#E5E7EB] sm:p-5">
            <p className={`text-3xl font-bold tabular-nums ${toneClass}`}>{value}</p>
            <p className="mt-1 text-xs font-medium text-[#54595F] sm:text-sm">{label}</p>
            <p className="mt-0.5 text-[10px] leading-tight text-gray-400 sm:text-xs">{hint}</p>
        </div>
    );
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
            className="flex items-start gap-3 rounded-xl px-3 py-2.5 transition hover:bg-[#F7E8ED]/40 active:bg-[#F7E8ED]/60"
        >
            <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${dotClass}`} />
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-semibold text-gray-800">{item.name}</span>
                    <span className="text-xs text-gray-400">{item.pseudonym}</span>
                </span>
                <span className="mt-0.5 block text-sm text-gray-600">
                    {label}
                    {extraText && <span className="text-gray-400"> · {extraText}</span>}
                </span>
            </span>
        </Link>
    );
}

function RecentCard({ r }: { r: RecentItem }) {
    const meta = [r.pseudonym, r.locationName, r.createdAt ? `seit ${r.createdAt}` : null]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="min-w-0 rounded-xl bg-white p-3 ring-1 ring-[#E5E7EB] sm:p-4">
            <Link href={route('residents.show', r.id)} className="group flex items-center gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#F7E8ED] text-sm font-bold text-[#7F1730]">
                    {initials(r.name)}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-[#333333] group-hover:text-[#9B1C3B]">
                        {r.name}
                    </p>
                    <p className="mt-0.5 truncate text-xs text-[#54595F]">{meta}</p>
                </div>
                <svg
                    className="h-5 w-5 shrink-0 text-gray-300 transition group-hover:text-[#9B1C3B]"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M8.25 4.5l7.5 7.5-7.5 7.5"
                    />
                </svg>
            </Link>
            <div className="mt-3 flex flex-wrap gap-2">
                <Link
                    href={route('residents.sis.show', r.id)}
                    className={`rounded-full px-3 py-1 text-xs font-semibold ${
                        r.hasSis
                            ? r.sisCompleted
                                ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                                : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200'
                            : 'bg-gray-100 text-gray-500 ring-1 ring-gray-200'
                    }`}
                >
                    SIS {r.hasSis ? (r.sisCompleted ? '✓' : '…') : '—'}
                </Link>
                <Link
                    href={route('residents.care-plan.show', r.id)}
                    className={`rounded-full px-3 py-1 text-xs font-semibold ${
                        r.hasCarePlan
                            ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                            : 'bg-gray-100 text-gray-500 ring-1 ring-gray-200'
                    }`}
                >
                    MP {r.hasCarePlan ? '✓' : '—'}
                </Link>
            </div>
        </div>
    );
}

function SectionCard({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h3 className="text-sm font-bold uppercase tracking-[0.18em] text-[#333333]">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
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

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* Kennzahlen */}
                    <div className="grid grid-cols-3 gap-3 sm:gap-4">
                        <StatCard
                            value={todo.totalRed}
                            label="Überfällig"
                            hint="Termine fällig"
                            tone="red"
                        />
                        <StatCard
                            value={todo.totalYellow}
                            label="Demnächst"
                            hint="in ≤ 7 Tagen"
                            tone="amber"
                        />
                        <StatCard
                            value={todo.totalGap}
                            label="MP offen"
                            hint="SIS fertig, MP fehlt"
                            tone="neutral"
                        />
                    </div>

                    {/* ============ Block 1: Aufgaben ============ */}
                    <SectionCard title="Aufgaben">
                        {allTodoEmpty ? (
                            <div className="mt-4 flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-4 ring-1 ring-emerald-200">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                                    ✓
                                </span>
                                <p className="text-sm font-medium text-emerald-800">
                                    Alles erledigt – nichts überfällig oder in den nächsten 7 Tagen
                                    fällig.
                                </p>
                            </div>
                        ) : (
                            <div className="mt-3 space-y-0.5">
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
                    </SectionCard>

                    {/* ============ Block 2: KI-Generierungen ============ */}
                    {anyRunning && (
                        <SectionCard title="KI-Generierungen">
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
                                                    className="block rounded-xl px-3 py-2.5 transition hover:bg-[#F8F8F8]"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-gray-800">
                                                                {g.name}
                                                            </span>
                                                            <span className="text-xs text-gray-400">
                                                                {g.pseudonym}
                                                            </span>
                                                            <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                                                {g.kind === 'sis' ? 'SIS' : 'MP'}
                                                            </span>
                                                        </div>
                                                        <span className="text-xs text-gray-600">
                                                            {g.progress} / {g.totalSteps}
                                                        </span>
                                                    </div>
                                                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
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
                                                className="block rounded-xl px-3 py-2.5 transition hover:bg-[#F8F8F8]"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold text-gray-800">
                                                        {g.name}
                                                    </span>
                                                    <span className="text-xs text-gray-400">
                                                        {g.pseudonym}
                                                    </span>
                                                    <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800">
                                                        {g.kind === 'sis' ? 'SIS' : 'MP'}
                                                    </span>
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
                        </SectionCard>
                    )}

                    {/* ============ Block 3: Zuletzt aufgenommen ============ */}
                    <SectionCard
                        title="Zuletzt aufgenommen"
                        action={
                            <Link
                                href={route('residents.index')}
                                className="text-xs font-semibold uppercase tracking-widest text-[#9B1C3B] hover:underline"
                            >
                                Alle Bewohner
                            </Link>
                        }
                    >
                        {recent.length === 0 ? (
                            <p className="mt-4 text-sm text-gray-500">Noch keine Bewohner.</p>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {recent.map((r) => (
                                    <RecentCard key={r.id} r={r} />
                                ))}
                            </div>
                        )}
                    </SectionCard>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

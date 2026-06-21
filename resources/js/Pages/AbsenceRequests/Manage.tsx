import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type AbsenceItem = {
    id: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    startsOn: string;
    endsOn: string;
    startDay: number;
    endDay: number;
    continuesBefore: boolean;
    continuesAfter: boolean;
    daysCount: string;
    note: string | null;
    hitsBlackout: boolean;
    overrideReason: string | null;
    requestedByName: string | null;
    decidedByName: string | null;
    decidedAt: string | null;
};

type Employee = {
    id: string;
    name: string;
    employmentAreaLabel: string | null;
    qualificationLabel: string | null;
    absences: AbsenceItem[];
};

type Group = {
    locationId: string;
    locationName: string;
    employees: Employee[];
};

type Day = {
    day: number;
    date: string;
    weekdayShort: string;
    isWeekend: boolean;
};

type Props = {
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    currentMonth: string;
    today: string;
    days: Day[];
    groups: Group[];
    openRequestsCount: number;
};

type Selection = { employee: Employee; absence: AbsenceItem };

const NAME_COL = 200;
const DAY_COL = 34;

// Farbe nach Art, Intensitaet nach Status. Offene Antraege sind gestrichelt
// und heller, damit man bestaetigte von zu entscheidenden unterscheidet.
function barClass(absence: AbsenceItem): string {
    const isVacation = absence.type === 'vacation';
    const approved = absence.status === 'approved';

    if (isVacation) {
        return approved
            ? 'bg-emerald-500 text-white ring-emerald-600'
            : 'bg-emerald-100 text-emerald-900 ring-1 ring-dashed ring-emerald-500';
    }

    return approved
        ? 'bg-indigo-500 text-white ring-indigo-600'
        : 'bg-indigo-100 text-indigo-900 ring-1 ring-dashed ring-indigo-500';
}

function shortTypeLabel(absence: AbsenceItem): string {
    return absence.type === 'vacation' ? 'Urlaub' : 'Überstd.';
}

function statusBadgeClass(status: string): string {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-red-100 text-red-800';
    }

    if (status === 'cancelled') {
        return 'bg-gray-100 text-gray-700';
    }

    return 'bg-amber-100 text-amber-800';
}

export default function ManageAbsenceRequests({
    month,
    monthLabel,
    prevMonth,
    nextMonth,
    currentMonth,
    today,
    days,
    groups,
    openRequestsCount,
}: Props) {
    const [selection, setSelection] = useState<Selection | null>(null);
    const [showRejectForm, setShowRejectForm] = useState(false);
    const [showOverrideForm, setShowOverrideForm] = useState(false);

    const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({
        rejection_reason: '',
        override_reason: '',
    });

    const gridMinWidth = NAME_COL + days.length * DAY_COL;
    const gridTemplate = `${NAME_COL}px repeat(${days.length}, minmax(0, 1fr))`;

    const goToMonth = (target: string) => {
        router.get(
            route('absence-requests.manage'),
            { month: target },
            { preserveScroll: true, preserveState: false },
        );
    };

    const openAbsence = (employee: Employee, absence: AbsenceItem) => {
        clearErrors();
        reset();
        setShowRejectForm(false);
        setShowOverrideForm(false);
        setSelection({ employee, absence });
    };

    const closeDialog = () => {
        setSelection(null);
        setShowRejectForm(false);
        setShowOverrideForm(false);
        clearErrors();
        reset();
    };

    const approve = (absenceId: string) => {
        router.patch(
            route('absence-requests.approve', absenceId),
            {},
            { preserveScroll: true, onSuccess: closeDialog },
        );
    };

    const submitOverrideApprove = (event: FormEvent, absenceId: string) => {
        event.preventDefault();

        patch(route('absence-requests.approve', absenceId), {
            preserveScroll: true,
            onSuccess: closeDialog,
        });
    };

    const submitReject = (event: FormEvent, absenceId: string) => {
        event.preventDefault();

        patch(route('absence-requests.reject', absenceId), {
            preserveScroll: true,
            onSuccess: closeDialog,
        });
    };

    const totalEmployees = groups.reduce((sum, group) => sum + group.employees.length, 0);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Abwesenheitsplanung
                </h2>
            }
        >
            <Head title="Abwesenheitsplanung" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        {/* Kopfzeile mit Monatsnavigation */}
                        <div className="flex flex-col gap-4 border-b border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Team-Übersicht · {monthLabel}
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    Urlaub und Überstundenausgleich pro Mitarbeiter. Klicke auf
                                    einen Balken für Details und Entscheidung.
                                    {openRequestsCount > 0 && (
                                        <span className="ml-1 font-medium text-amber-700">
                                            {openRequestsCount} offene
                                            {openRequestsCount === 1 ? 'r' : ''} Antrag
                                            {openRequestsCount === 1 ? '' : 'e'}.
                                        </span>
                                    )}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <SecondaryButton type="button" onClick={() => goToMonth(prevMonth)}>
                                    ‹ Vormonat
                                </SecondaryButton>
                                {month !== currentMonth && (
                                    <SecondaryButton
                                        type="button"
                                        onClick={() => goToMonth(currentMonth)}
                                    >
                                        Heute
                                    </SecondaryButton>
                                )}
                                <SecondaryButton type="button" onClick={() => goToMonth(nextMonth)}>
                                    Folgemonat ›
                                </SecondaryButton>
                            </div>
                        </div>

                        {/* Legende */}
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-gray-200 px-6 py-3 text-xs text-gray-600">
                            <span className="flex items-center gap-2">
                                <span className="inline-block h-3 w-5 rounded bg-emerald-500" />{' '}
                                Urlaub (genehmigt)
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="inline-block h-3 w-5 rounded bg-emerald-100 ring-1 ring-dashed ring-emerald-500" />{' '}
                                Urlaub (offen)
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="inline-block h-3 w-5 rounded bg-indigo-500" />{' '}
                                Überstundenausgleich (genehmigt)
                            </span>
                            <span className="flex items-center gap-2">
                                <span className="inline-block h-3 w-5 rounded bg-indigo-100 ring-1 ring-dashed ring-indigo-500" />{' '}
                                Überstundenausgleich (offen)
                            </span>
                        </div>

                        {totalEmployees === 0 ? (
                            <p className="p-6 text-sm text-gray-600">
                                Für deine Wohnbereiche sind keine Mitarbeiter mit
                                Abwesenheitsanspruch hinterlegt.
                            </p>
                        ) : (
                            <>
                                <div className="hidden overflow-x-auto md:block">
                                    <div style={{ minWidth: gridMinWidth }}>
                                        {/* Tagesleiste */}
                                        <div
                                            className="sticky top-0 z-20 grid border-b border-gray-200 bg-gray-50"
                                            style={{ gridTemplateColumns: gridTemplate }}
                                        >
                                            <div className="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                Mitarbeiter
                                            </div>
                                            {days.map((day) => {
                                                const isToday = day.date === today;

                                                return (
                                                    <div
                                                        key={day.date}
                                                        className={`border-l border-gray-100 py-1 text-center ${
                                                            day.isWeekend ? 'bg-gray-100' : ''
                                                        } ${isToday ? 'bg-amber-100' : ''}`}
                                                    >
                                                        <div className="text-[10px] uppercase text-gray-400">
                                                            {day.weekdayShort}
                                                        </div>
                                                        <div className="text-xs font-medium text-gray-700">
                                                            {day.day}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {/* Gruppen nach Wohnbereich */}
                                        {groups.map((group) => (
                                            <div key={group.locationId}>
                                                <div className="sticky left-0 z-10 bg-[#9B1C3B]/5 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-[#9B1C3B]">
                                                    {group.locationName}
                                                </div>

                                                {group.employees.length === 0 ? (
                                                    <p className="px-3 py-2 text-xs text-gray-400">
                                                        Keine Mitarbeiter in diesem Wohnbereich.
                                                    </p>
                                                ) : (
                                                    group.employees.map((employee) => (
                                                        <div
                                                            key={employee.id}
                                                            className="grid items-center border-b border-gray-100 hover:bg-gray-50/60"
                                                            style={{
                                                                gridTemplateColumns: gridTemplate,
                                                            }}
                                                        >
                                                            <div className="sticky left-0 z-10 truncate bg-white px-3 py-2">
                                                                <div className="truncate text-sm font-medium text-gray-800">
                                                                    {employee.name}
                                                                </div>
                                                                {employee.qualificationLabel && (
                                                                    <div className="truncate text-[11px] text-gray-500">
                                                                        {
                                                                            employee.qualificationLabel
                                                                        }
                                                                    </div>
                                                                )}
                                                            </div>

                                                            {/* Hintergrund-Tageszellen */}
                                                            {days.map((day, dayIndex) => {
                                                                const isToday = day.date === today;

                                                                return (
                                                                    <div
                                                                        key={day.date}
                                                                        className={`h-9 border-l border-gray-100 ${
                                                                            day.isWeekend
                                                                                ? 'bg-gray-50'
                                                                                : ''
                                                                        } ${isToday ? 'bg-amber-50' : ''}`}
                                                                        style={{
                                                                            gridColumnStart:
                                                                                dayIndex + 2,
                                                                            gridRow: 1,
                                                                        }}
                                                                    />
                                                                );
                                                            })}

                                                            {/* Abwesenheits-Balken */}
                                                            {employee.absences.map((absence) => (
                                                                <button
                                                                    key={absence.id}
                                                                    type="button"
                                                                    onClick={() =>
                                                                        openAbsence(
                                                                            employee,
                                                                            absence,
                                                                        )
                                                                    }
                                                                    title={`${absence.typeLabel}: ${absence.startsOn} – ${absence.endsOn} (${absence.statusLabel})${
                                                                        absence.hitsBlackout
                                                                            ? ' · Urlaubssperre'
                                                                            : ''
                                                                    }`}
                                                                    className={`z-10 mx-0.5 flex h-6 items-center gap-1 overflow-hidden rounded px-1.5 text-[11px] font-medium ring-1 ${barClass(
                                                                        absence,
                                                                    )} ${absence.hitsBlackout ? 'ring-2 ring-red-500' : ''} ${
                                                                        absence.continuesBefore
                                                                            ? 'rounded-l-none'
                                                                            : ''
                                                                    } ${absence.continuesAfter ? 'rounded-r-none' : ''}`}
                                                                    style={{
                                                                        gridColumn: `${absence.startDay + 1} / ${absence.endDay + 2}`,
                                                                        gridRow: 1,
                                                                    }}
                                                                >
                                                                    {absence.hitsBlackout && (
                                                                        <span aria-hidden>⚠</span>
                                                                    )}
                                                                    <span className="truncate">
                                                                        {shortTypeLabel(absence)}
                                                                    </span>
                                                                </button>
                                                            ))}
                                                        </div>
                                                    ))
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Mobile: nach Wohnbereich gruppierte, antippbare Liste */}
                                <div className="space-y-5 p-4 md:hidden">
                                    {groups.map((group) => {
                                        const employeesWithAbsences = group.employees.filter(
                                            (employee) => employee.absences.length > 0,
                                        );

                                        return (
                                            <div key={group.locationId} className="space-y-3">
                                                <h4 className="text-xs font-bold uppercase tracking-wide text-[#9B1C3B]">
                                                    {group.locationName}
                                                </h4>

                                                {employeesWithAbsences.length === 0 ? (
                                                    <p className="text-sm text-gray-500">
                                                        Keine Abwesenheiten in diesem Monat.
                                                    </p>
                                                ) : (
                                                    <ul className="space-y-3">
                                                        {employeesWithAbsences.map((employee) => (
                                                            <li
                                                                key={employee.id}
                                                                className="rounded-lg border border-gray-200 p-3"
                                                            >
                                                                <div className="mb-2">
                                                                    <p className="text-sm font-semibold text-gray-800">
                                                                        {employee.name}
                                                                    </p>
                                                                    {employee.qualificationLabel && (
                                                                        <p className="text-[11px] text-gray-500">
                                                                            {
                                                                                employee.qualificationLabel
                                                                            }
                                                                        </p>
                                                                    )}
                                                                </div>
                                                                <ul className="space-y-2">
                                                                    {employee.absences.map(
                                                                        (absence) => (
                                                                            <li key={absence.id}>
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() =>
                                                                                        openAbsence(
                                                                                            employee,
                                                                                            absence,
                                                                                        )
                                                                                    }
                                                                                    className="flex w-full items-center justify-between gap-3 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-left transition hover:bg-gray-100"
                                                                                >
                                                                                    <span className="min-w-0">
                                                                                        <span className="flex items-center gap-2">
                                                                                            <span
                                                                                                aria-hidden
                                                                                                className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${
                                                                                                    absence.type ===
                                                                                                    'vacation'
                                                                                                        ? 'bg-emerald-500'
                                                                                                        : 'bg-indigo-500'
                                                                                                }`}
                                                                                            />
                                                                                            <span className="truncate text-sm font-medium text-gray-900">
                                                                                                {
                                                                                                    absence.typeLabel
                                                                                                }
                                                                                            </span>
                                                                                            {absence.hitsBlackout && (
                                                                                                <span
                                                                                                    aria-hidden
                                                                                                    className="text-red-600"
                                                                                                >
                                                                                                    ⚠
                                                                                                </span>
                                                                                            )}
                                                                                        </span>
                                                                                        <span className="mt-0.5 block text-xs text-gray-600">
                                                                                            {
                                                                                                absence.startsOn
                                                                                            }{' '}
                                                                                            –{' '}
                                                                                            {
                                                                                                absence.endsOn
                                                                                            }{' '}
                                                                                            ·{' '}
                                                                                            {
                                                                                                absence.daysCount
                                                                                            }{' '}
                                                                                            Tage
                                                                                        </span>
                                                                                    </span>
                                                                                    <span
                                                                                        className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusBadgeClass(
                                                                                            absence.status,
                                                                                        )}`}
                                                                                    >
                                                                                        {
                                                                                            absence.statusLabel
                                                                                        }
                                                                                    </span>
                                                                                </button>
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            {/* Detail-Dialog */}
            {selection && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onClick={closeDialog}
                >
                    <div
                        className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-6"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    {selection.employee.name}
                                </h3>
                                {selection.employee.employmentAreaLabel && (
                                    <p className="text-sm text-gray-500">
                                        {selection.employee.employmentAreaLabel}
                                        {selection.employee.qualificationLabel
                                            ? ` · ${selection.employee.qualificationLabel}`
                                            : ''}
                                    </p>
                                )}
                            </div>
                            <span
                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass(
                                    selection.absence.status,
                                )}`}
                            >
                                {selection.absence.statusLabel}
                            </span>
                        </div>

                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-500">Art</dt>
                                <dd className="font-medium text-gray-900">
                                    {selection.absence.typeLabel}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-500">Zeitraum</dt>
                                <dd className="text-right font-medium text-gray-900">
                                    {selection.absence.startsOn} – {selection.absence.endsOn}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-500">Tage</dt>
                                <dd className="font-medium text-gray-900">
                                    {selection.absence.daysCount}
                                </dd>
                            </div>
                            {selection.absence.note && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-gray-500">Notiz</dt>
                                    <dd className="text-right text-gray-800">
                                        {selection.absence.note}
                                    </dd>
                                </div>
                            )}
                            {selection.absence.decidedByName && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-gray-500">Entschieden von</dt>
                                    <dd className="text-right text-gray-700">
                                        {selection.absence.decidedByName}
                                        {selection.absence.decidedAt
                                            ? ` · ${selection.absence.decidedAt}`
                                            : ''}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        {selection.absence.overrideReason && (
                            <div className="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                                <span className="font-medium">Ausnahme von Urlaubssperre:</span>{' '}
                                {selection.absence.overrideReason}
                            </div>
                        )}

                        {selection.absence.status === 'requested' &&
                            selection.absence.hitsBlackout && (
                                <div className="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-800">
                                    ⚠ Dieser Antrag fällt in eine Urlaubssperre. Eine Genehmigung
                                    ist nur als dokumentierte Ausnahme mit Begründung möglich.
                                </div>
                            )}

                        {selection.absence.status === 'requested' ? (
                            <div className="mt-6">
                                {!showRejectForm && !showOverrideForm && (
                                    <div className="flex justify-end gap-2">
                                        <SecondaryButton
                                            type="button"
                                            onClick={() => {
                                                clearErrors();
                                                reset();
                                                setShowOverrideForm(false);
                                                setShowRejectForm(true);
                                            }}
                                        >
                                            Ablehnen
                                        </SecondaryButton>
                                        {selection.absence.hitsBlackout ? (
                                            <PrimaryButton
                                                type="button"
                                                onClick={() => {
                                                    clearErrors();
                                                    reset();
                                                    setShowRejectForm(false);
                                                    setShowOverrideForm(true);
                                                }}
                                            >
                                                Als Ausnahme genehmigen
                                            </PrimaryButton>
                                        ) : (
                                            <PrimaryButton
                                                type="button"
                                                disabled={processing}
                                                onClick={() => approve(selection.absence.id)}
                                            >
                                                Genehmigen
                                            </PrimaryButton>
                                        )}
                                    </div>
                                )}

                                {showOverrideForm && (
                                    <form
                                        onSubmit={(event) =>
                                            submitOverrideApprove(event, selection.absence.id)
                                        }
                                        className="space-y-3 rounded-xl bg-amber-50 p-4"
                                    >
                                        <label
                                            htmlFor="override_reason"
                                            className="block text-sm font-medium text-gray-700"
                                        >
                                            Begründung der Ausnahme
                                        </label>
                                        <textarea
                                            id="override_reason"
                                            value={data.override_reason}
                                            onChange={(event) =>
                                                setData('override_reason', event.target.value)
                                            }
                                            rows={3}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                            placeholder="Warum wird trotz Urlaubssperre genehmigt? (z. B. dringender familiärer Grund, Besetzung gesichert)"
                                        />
                                        <InputError message={errors.override_reason} />
                                        <div className="flex justify-end gap-2">
                                            <SecondaryButton
                                                type="button"
                                                onClick={() => {
                                                    clearErrors();
                                                    reset();
                                                    setShowOverrideForm(false);
                                                }}
                                            >
                                                Zurück
                                            </SecondaryButton>
                                            <PrimaryButton disabled={processing}>
                                                Ausnahme genehmigen
                                            </PrimaryButton>
                                        </div>
                                    </form>
                                )}

                                {showRejectForm && (
                                    <form
                                        onSubmit={(event) =>
                                            submitReject(event, selection.absence.id)
                                        }
                                        className="space-y-3 rounded-xl bg-gray-50 p-4"
                                    >
                                        <label
                                            htmlFor="rejection_reason"
                                            className="block text-sm font-medium text-gray-700"
                                        >
                                            Ablehnungsgrund
                                        </label>
                                        <textarea
                                            id="rejection_reason"
                                            value={data.rejection_reason}
                                            onChange={(event) =>
                                                setData('rejection_reason', event.target.value)
                                            }
                                            rows={3}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                            placeholder="Zum Beispiel: Mindestbesetzung wäre gefährdet."
                                        />
                                        <InputError message={errors.rejection_reason} />
                                        <div className="flex justify-end gap-2">
                                            <SecondaryButton
                                                type="button"
                                                onClick={() => {
                                                    clearErrors();
                                                    reset();
                                                    setShowRejectForm(false);
                                                }}
                                            >
                                                Zurück
                                            </SecondaryButton>
                                            <PrimaryButton disabled={processing}>
                                                Ablehnung speichern
                                            </PrimaryButton>
                                        </div>
                                    </form>
                                )}
                            </div>
                        ) : (
                            <div className="mt-6 flex justify-end">
                                <SecondaryButton type="button" onClick={closeDialog}>
                                    Schließen
                                </SecondaryButton>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

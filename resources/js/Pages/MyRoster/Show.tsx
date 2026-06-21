import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ReactNode, useState } from 'react';

type MyShift = {
    id: string;
    shiftTemplateName: string | null;
    shiftTemplateCode: string | null;
    shiftTemplateColor: string | null;
    locationName: string | null;
    startsAt: string;
    endsAt: string;
    minutes: number;
    note: string | null;
    isLocked: boolean;
};

type TeamGroup = {
    shiftTemplateName: string | null;
    shiftTemplateCode: string | null;
    shiftTemplateColor: string | null;
    startsAt: string;
    endsAt: string;
    isOwnShift: boolean;
    colleagues: string[];
};

type MyDay = {
    date: string;
    dayLabel: string;
    weekdayLabel: string;
    isWeekend: boolean;
    isToday: boolean;
    shifts: MyShift[];
    absence: {
        typeLabel: string;
        startsOn: string;
        endsOn: string;
    } | null;
    team: TeamGroup[];
};

type Props = {
    month: {
        year: number;
        month: number;
        label: string;
        previous: string;
        next: string;
        daysInMonth: number;
    };
    days: MyDay[];
    summary: {
        shiftCount: number;
        plannedMinutes: number;
        targetMinutes: number;
        weekends: number;
        nightShifts: number;
        workDays: number;
    };
    hasUnpublishedRoster: boolean;
};

const WEEKDAY_HEADERS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const ACCENT = '#9B1C3B';

function formatMinutesAsHours(minutes: number): string {
    return `${(minutes / 60).toFixed(1).replace('.', ',')} h`;
}

function dayOfMonth(date: string): number {
    return Number(date.slice(8, 10));
}

// Wochentag Montag=0 … Sonntag=6 (für das Kalender-Raster).
function weekdayMondayFirst(date: string): number {
    return (new Date(`${date}T00:00:00`).getDay() + 6) % 7;
}

function ShiftChip({ shift }: { shift: MyShift }) {
    return (
        <div
            className="rounded-md border border-gray-200 bg-white p-2.5"
            style={{ borderLeftWidth: '4px', borderLeftColor: shift.shiftTemplateColor ?? ACCENT }}
        >
            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span className="text-sm font-semibold text-gray-900">
                    {shift.shiftTemplateName ?? 'Dienst'}
                </span>
                <span className="text-sm text-gray-700">
                    {shift.startsAt}–{shift.endsAt}
                </span>
                <span className="text-xs text-gray-500">
                    ({formatMinutesAsHours(shift.minutes)})
                </span>
                {shift.isLocked && (
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">
                        Gesperrt
                    </span>
                )}
            </div>
            {shift.locationName && (
                <p className="mt-0.5 text-xs text-gray-500">{shift.locationName}</p>
            )}
            {shift.note && <p className="mt-1 text-xs italic text-gray-600">{shift.note}</p>}
        </div>
    );
}

function SectionLabel({ children }: { children: ReactNode }) {
    return (
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">{children}</p>
    );
}

function DayDetailModal({ day, onClose }: { day: MyDay; onClose: () => void }) {
    const hasOwnShift = day.shifts.length > 0;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
            onClick={onClose}
        >
            <div
                onClick={(event) => event.stopPropagation()}
                className="max-h-[85vh] w-full overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-w-lg sm:rounded-2xl"
            >
                {/* Kopf mit Akzent */}
                <div className="flex items-center gap-4 border-b border-gray-100 bg-[#F7E8ED]/50 p-5 sm:p-6">
                    <div className="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-2xl bg-[#9B1C3B] text-white">
                        <span className="text-xl font-bold leading-none">
                            {dayOfMonth(day.date)}
                        </span>
                        <span className="text-[10px] font-medium uppercase">
                            {day.weekdayLabel}
                        </span>
                    </div>
                    <div className="min-w-0 flex-1">
                        <h3 className="text-lg font-semibold text-gray-900">{day.dayLabel}</h3>
                        {day.isToday && (
                            <span className="mt-0.5 inline-flex rounded-full bg-[#9B1C3B]/10 px-2 py-0.5 text-[11px] font-semibold text-[#9B1C3B]">
                                Heute
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Schließen"
                        className="rounded-full p-1.5 text-gray-500 transition hover:bg-white hover:text-gray-800"
                    >
                        <svg
                            className="h-6 w-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            strokeWidth={1.8}
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <div className="max-h-[60vh] space-y-5 overflow-y-auto p-5 sm:p-6">
                    {/* Eigener Status */}
                    <section className="space-y-2">
                        <SectionLabel>Mein Tag</SectionLabel>
                        {day.absence && (
                            <div className="flex items-center gap-2 rounded-lg bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 ring-1 ring-teal-200">
                                <span className="h-2 w-2 rounded-full bg-teal-500" />
                                {day.absence.typeLabel}
                            </div>
                        )}
                        {hasOwnShift
                            ? day.shifts.map((shift) => <ShiftChip key={shift.id} shift={shift} />)
                            : !day.absence && (
                                  <p className="text-sm text-gray-500">
                                      Du hast an diesem Tag keinen eigenen Dienst.
                                  </p>
                              )}
                    </section>

                    {/* Team */}
                    {day.team.length > 0 && (
                        <section className="space-y-2">
                            <SectionLabel>
                                {hasOwnShift
                                    ? 'Mit wem arbeite ich zusammen?'
                                    : 'Wer ist im Dienst?'}
                            </SectionLabel>
                            <div className="space-y-2">
                                {day.team.map((group, index) => (
                                    <div
                                        key={`${group.shiftTemplateCode ?? 'x'}-${index}`}
                                        className="rounded-lg border border-gray-200 bg-white p-3"
                                        style={{
                                            borderLeftWidth: '4px',
                                            borderLeftColor: group.shiftTemplateColor ?? ACCENT,
                                        }}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold text-gray-900">
                                                {group.shiftTemplateName ?? 'Dienst'}
                                            </span>
                                            <span className="text-sm text-gray-600">
                                                {group.startsAt}–{group.endsAt} Uhr
                                            </span>
                                            {group.isOwnShift && (
                                                <span className="rounded-full bg-[#9B1C3B]/10 px-2 py-0.5 text-[11px] font-semibold text-[#9B1C3B]">
                                                    meine Schicht
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                                            {group.colleagues.map((colleague) => (
                                                <span
                                                    key={colleague}
                                                    className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700"
                                                >
                                                    {colleague}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </div>
    );
}

function CalendarGrid({ days, onSelect }: { days: MyDay[]; onSelect: (day: MyDay) => void }) {
    if (days.length === 0) {
        return null;
    }

    const leadingBlanks = weekdayMondayFirst(days[0].date);
    const cells: (MyDay | null)[] = [...Array(leadingBlanks).fill(null), ...days];

    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return (
        <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
            <div className="grid grid-cols-7 border-b border-gray-200 bg-gray-50">
                {WEEKDAY_HEADERS.map((label, index) => (
                    <div
                        key={label}
                        className={
                            'px-1 py-2 text-center text-[11px] font-semibold uppercase tracking-wide sm:text-xs ' +
                            (index >= 5 ? 'text-[#9B1C3B]' : 'text-gray-500')
                        }
                    >
                        {label}
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-7">
                {cells.map((day, index) => {
                    if (day === null) {
                        return (
                            <div
                                key={`blank-${index}`}
                                className="min-h-[4.5rem] border-b border-r border-gray-100 bg-gray-50/40 sm:min-h-[7rem]"
                            />
                        );
                    }

                    const hasOwnContent = day.shifts.length > 0 || day.absence !== null;
                    const cellClass =
                        'min-h-[4.5rem] border-b border-r border-gray-100 p-1 text-left align-top transition hover:bg-[#F7E8ED]/40 sm:min-h-[7rem] sm:p-1.5 ' +
                        (day.isWeekend ? 'bg-gray-50/60 ' : 'bg-white ') +
                        (day.isToday ? 'ring-2 ring-inset ring-[#9B1C3B] ' : '');

                    const dayNumber = (
                        <span
                            className={
                                'inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs font-semibold ' +
                                (day.isToday ? 'bg-[#9B1C3B] text-white' : 'text-gray-700')
                            }
                        >
                            {dayOfMonth(day.date)}
                        </span>
                    );

                    return (
                        <button
                            key={day.date}
                            type="button"
                            onClick={() => onSelect(day)}
                            className={cellClass}
                        >
                            {dayNumber}
                            <div className="mt-1 space-y-1">
                                {day.absence && (
                                    <span className="block truncate rounded bg-teal-100 px-1 py-0.5 text-[10px] font-semibold text-teal-800 sm:text-[11px]">
                                        {day.absence.typeLabel}
                                    </span>
                                )}
                                {day.shifts.map((shift) => (
                                    <span
                                        key={shift.id}
                                        className="flex items-center gap-1 rounded border-l-2 bg-gray-50 px-1 py-0.5 text-[10px] sm:text-[11px]"
                                        style={{
                                            borderLeftColor: shift.shiftTemplateColor ?? ACCENT,
                                        }}
                                    >
                                        <span className="truncate font-medium text-gray-800">
                                            {shift.shiftTemplateName ?? 'Dienst'}
                                        </span>
                                        <span className="hidden text-gray-500 sm:inline">
                                            {shift.startsAt}
                                        </span>
                                    </span>
                                ))}
                                {!hasOwnContent && (
                                    <span className="block text-[10px] text-gray-400 sm:text-[11px]">
                                        frei
                                        {day.team.length > 0 && (
                                            <span className="hidden sm:inline"> · Team</span>
                                        )}
                                    </span>
                                )}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default function MyRosterShow({ month, days, summary, hasUnpublishedRoster }: Props) {
    const [selectedDay, setSelectedDay] = useState<MyDay | null>(null);

    const stats: Array<{ label: string; value: string; hint: string | null }> = [
        { label: 'Dienste', value: String(summary.shiftCount), hint: 'im Monat' },
        {
            label: 'Geplante Stunden',
            value: (summary.plannedMinutes / 60).toFixed(1).replace('.', ','),
            hint:
                summary.targetMinutes > 0
                    ? `von ${(summary.targetMinutes / 60).toFixed(1).replace('.', ',')} Soll`
                    : null,
        },
        { label: 'Arbeitstage', value: String(summary.workDays), hint: 'mit Dienst' },
        { label: 'Wochenenden', value: String(summary.weekends), hint: 'mit Dienst' },
        { label: 'Nachtdienste', value: String(summary.nightShifts), hint: 'im Monat' },
    ];

    const todayIso = new Date().toISOString().slice(0, 10);
    const nextShiftDay = days.find((day) => day.shifts.length > 0 && day.date >= todayIso) ?? null;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Mein Dienstplan
                </h2>
            }
        >
            <Head title="Mein Dienstplan" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* Monatsnavigation */}
                    <div className="flex items-center justify-between gap-2 rounded-2xl bg-white p-2 shadow-sm ring-1 ring-gray-200">
                        <Link
                            href={route('my-roster.show', { month: month.previous })}
                            className="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900"
                            preserveState={false}
                        >
                            ← <span className="hidden sm:inline">Vormonat</span>
                        </Link>
                        <span className="text-base font-semibold text-gray-900 sm:text-lg">
                            {month.label}
                        </span>
                        <Link
                            href={route('my-roster.show', { month: month.next })}
                            className="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900"
                            preserveState={false}
                        >
                            <span className="hidden sm:inline">Folgemonat</span> →
                        </Link>
                    </div>

                    {hasUnpublishedRoster && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                            Der Dienstplan für diesen Monat ist noch in Bearbeitung und noch nicht
                            veröffentlicht.
                        </div>
                    )}

                    {/* Nächster Dienst */}
                    {nextShiftDay && (
                        <div className="overflow-hidden rounded-2xl bg-gradient-to-r from-[#9B1C3B] to-[#7F1730] p-5 text-white shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/70">
                                Nächster Dienst
                            </p>
                            <div className="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span className="text-2xl font-semibold">
                                    {nextShiftDay.dayLabel}
                                </span>
                                <span className="text-white/80">{nextShiftDay.weekdayLabel}</span>
                                {nextShiftDay.shifts.map((shift) => (
                                    <span key={shift.id} className="text-lg font-medium">
                                        {shift.shiftTemplateName} {shift.startsAt}–{shift.endsAt}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Kennzahlen */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 xl:grid-cols-5">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200"
                            >
                                <p className="text-sm text-gray-500">{stat.label}</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {stat.value}
                                </p>
                                {stat.hint && (
                                    <p className="mt-1 text-xs text-gray-500">{stat.hint}</p>
                                )}
                            </div>
                        ))}
                    </div>

                    {/* Kalender (responsive, Tag antippen für Details) */}
                    <CalendarGrid days={days} onSelect={setSelectedDay} />
                    <p className="text-center text-xs text-gray-500">
                        Tippe auf einen Tag mit Dienst, um Details und das Team zu sehen.
                    </p>
                </div>
            </div>

            {selectedDay && (
                <DayDetailModal day={selectedDay} onClose={() => setSelectedDay(null)} />
            )}
        </AuthenticatedLayout>
    );
}

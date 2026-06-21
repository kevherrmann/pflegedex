import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

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

function ShiftChip({ shift, compact = false }: { shift: MyShift; compact?: boolean }) {
    return (
        <div
            className={
                'rounded-md border border-gray-200 bg-white ' + (compact ? 'px-1.5 py-1' : 'p-2.5')
            }
            style={{ borderLeftWidth: '4px', borderLeftColor: shift.shiftTemplateColor ?? ACCENT }}
        >
            <div className={'flex flex-wrap items-center gap-x-2 ' + (compact ? '' : 'gap-y-0.5')}>
                <span
                    className={'font-semibold text-gray-900 ' + (compact ? 'text-xs' : 'text-sm')}
                >
                    {shift.shiftTemplateName ?? 'Dienst'}
                </span>
                <span className={compact ? 'text-[11px] text-gray-600' : 'text-sm text-gray-700'}>
                    {shift.startsAt}–{shift.endsAt}
                </span>
                {!compact && (
                    <span className="text-xs text-gray-500">
                        ({formatMinutesAsHours(shift.minutes)})
                    </span>
                )}
                {shift.isLocked && !compact && (
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">
                        Gesperrt
                    </span>
                )}
            </div>
            {!compact && shift.locationName && (
                <p className="mt-0.5 text-xs text-gray-500">{shift.locationName}</p>
            )}
            {!compact && shift.note && (
                <p className="mt-1 text-xs italic text-gray-600">{shift.note}</p>
            )}
        </div>
    );
}

function CalendarGrid({ days }: { days: MyDay[] }) {
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
                            'px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide ' +
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
                                className="min-h-[7.5rem] border-b border-r border-gray-100 bg-gray-50/40"
                            />
                        );
                    }

                    return (
                        <div
                            key={day.date}
                            className={
                                'min-h-[7.5rem] border-b border-r border-gray-100 p-1.5 ' +
                                (day.isWeekend ? 'bg-gray-50/60 ' : 'bg-white ') +
                                (day.isToday ? 'ring-2 ring-inset ring-[#9B1C3B]' : '')
                            }
                        >
                            <div className="mb-1 flex items-center justify-between">
                                <span
                                    className={
                                        'inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-semibold ' +
                                        (day.isToday ? 'bg-[#9B1C3B] text-white' : 'text-gray-700')
                                    }
                                >
                                    {dayOfMonth(day.date)}
                                </span>
                            </div>

                            <div className="space-y-1">
                                {day.absence && (
                                    <span className="block truncate rounded bg-teal-100 px-1.5 py-0.5 text-[11px] font-semibold text-teal-800">
                                        {day.absence.typeLabel}
                                    </span>
                                )}
                                {day.shifts.map((shift) => (
                                    <ShiftChip key={shift.id} shift={shift} compact />
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function TeamBlock({ team, hasOwnShift }: { team: TeamGroup[]; hasOwnShift: boolean }) {
    return (
        <div className="rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-200">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                {hasOwnShift ? 'Mit wem arbeite ich zusammen?' : 'Wer ist im Dienst?'}
            </p>
            <div className="mt-1.5 space-y-1">
                {team.map((group, index) => (
                    <div
                        key={`${group.shiftTemplateCode ?? 'unbekannt'}-${index}`}
                        className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs"
                    >
                        <span className="inline-flex items-center gap-1.5 font-semibold text-gray-700">
                            <span
                                className="h-2 w-2 shrink-0 rounded-full"
                                style={{ backgroundColor: group.shiftTemplateColor ?? ACCENT }}
                            />
                            {group.shiftTemplateName ?? 'Dienst'}
                            {group.isOwnShift && (
                                <span className="rounded-full bg-[#9B1C3B]/10 px-1.5 py-0.5 text-[10px] font-semibold text-[#9B1C3B]">
                                    meine Schicht
                                </span>
                            )}
                        </span>
                        <span className="text-gray-500">
                            {group.startsAt}–{group.endsAt} Uhr
                        </span>
                        <span className="text-gray-700">{group.colleagues.join(', ')}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function AgendaCard({ day }: { day: MyDay }) {
    return (
        <div
            className={
                'rounded-2xl bg-white p-4 shadow-sm ring-1 ' +
                (day.isToday ? 'ring-2 ring-[#9B1C3B]' : 'ring-gray-200')
            }
        >
            <div className="flex items-center gap-3">
                <div
                    className={
                        'flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl ' +
                        (day.isToday
                            ? 'bg-[#9B1C3B] text-white'
                            : day.isWeekend
                              ? 'bg-[#F7E8ED] text-[#7F1730]'
                              : 'bg-gray-100 text-gray-700')
                    }
                >
                    <span className="text-base font-bold leading-none">{dayOfMonth(day.date)}</span>
                    <span className="text-[10px] font-medium uppercase">{day.weekdayLabel}</span>
                </div>
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-gray-900">{day.dayLabel}</p>
                    {day.isToday && (
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-[#9B1C3B]">
                            Heute
                        </p>
                    )}
                </div>
                {day.absence && (
                    <span className="ml-auto inline-flex rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-800">
                        {day.absence.typeLabel}
                    </span>
                )}
            </div>

            {day.shifts.length > 0 && (
                <div className="mt-3 space-y-2">
                    {day.shifts.map((shift) => (
                        <ShiftChip key={shift.id} shift={shift} />
                    ))}
                </div>
            )}

            {day.team.length > 0 && (
                <div className="mt-3">
                    <TeamBlock team={day.team} hasOwnShift={day.shifts.length > 0} />
                </div>
            )}
        </div>
    );
}

export default function MyRosterShow({ month, days, summary, hasUnpublishedRoster }: Props) {
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

    // Agenda: nur Tage mit Diensten oder Abwesenheit – das hält die Liste übersichtlich.
    const agendaDays = days.filter((day) => day.shifts.length > 0 || day.absence !== null);

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

                    {/* Kalender (ab Desktop) */}
                    <div className="hidden lg:block">
                        <CalendarGrid days={days} />
                    </div>

                    {/* Agenda (alle Größen; auf Mobile die Hauptansicht) */}
                    <div>
                        <h3 className="mb-3 text-lg font-semibold text-gray-900">
                            Dienste im {month.label}
                        </h3>
                        {agendaDays.length === 0 ? (
                            <div className="rounded-2xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200">
                                In diesem Monat sind für dich keine Dienste aus veröffentlichten
                                Dienstplänen und keine genehmigten Abwesenheiten hinterlegt.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {agendaDays.map((day) => (
                                    <AgendaCard key={day.date} day={day} />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

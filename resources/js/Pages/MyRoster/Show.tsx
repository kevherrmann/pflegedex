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

function formatMinutesAsHours(minutes: number): string {
    return `${(minutes / 60).toFixed(1).replace('.', ',')} h`;
}

function ShiftRow({ shift }: { shift: MyShift }) {
    return (
        <div
            className="rounded-lg border border-gray-200 bg-white py-2 pl-3 pr-3"
            style={{
                borderLeftWidth: '4px',
                borderLeftColor: shift.shiftTemplateColor ?? '#9B1C3B',
            }}
        >
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="text-sm font-semibold text-gray-900">
                    {shift.shiftTemplateName ?? 'Dienst'}
                </span>
                <span className="text-sm text-gray-700">
                    {shift.startsAt}–{shift.endsAt} Uhr
                </span>
                <span className="text-xs text-gray-500">
                    ({formatMinutesAsHours(shift.minutes)})
                </span>
                {shift.isLocked && (
                    <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
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

function DayRow({ day }: { day: MyDay }) {
    const hasContent = day.shifts.length > 0 || day.absence !== null;

    return (
        <div
            className={
                'flex gap-4 px-4 py-3 sm:px-6 ' +
                (day.isWeekend ? 'bg-gray-50 ' : 'bg-white ') +
                (day.isToday ? 'ring-2 ring-inset ring-[#9B1C3B]' : '')
            }
        >
            <div className="w-14 shrink-0 pt-1">
                <p
                    className={
                        'text-sm font-semibold ' +
                        (day.isToday ? 'text-[#9B1C3B]' : 'text-gray-900')
                    }
                >
                    {day.dayLabel}
                </p>
                <p className="text-xs text-gray-500">{day.weekdayLabel}</p>
                {day.isToday && (
                    <p className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#9B1C3B]">
                        Heute
                    </p>
                )}
            </div>

            <div className="min-w-0 flex-1 space-y-2">
                {day.absence && (
                    <span className="inline-flex rounded-full bg-teal-100 px-2.5 py-1 text-xs font-semibold text-teal-800">
                        {day.absence.typeLabel}
                    </span>
                )}

                {day.shifts.map((shift) => (
                    <ShiftRow key={shift.id} shift={shift} />
                ))}

                {!hasContent && <p className="pt-1 text-sm text-gray-400">frei</p>}

                {day.team.length > 0 && (
                    <TeamBlock team={day.team} hasOwnShift={day.shifts.length > 0} />
                )}
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
                                style={{
                                    backgroundColor: group.shiftTemplateColor ?? '#9B1C3B',
                                }}
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

export default function MyRosterShow({ month, days, summary, hasUnpublishedRoster }: Props) {
    const stats: Array<{ label: string; value: string; hint: string | null }> = [
        {
            label: 'Dienste',
            value: String(summary.shiftCount),
            hint: 'im Monat',
        },
        {
            label: 'Geplante Stunden',
            value: (summary.plannedMinutes / 60).toFixed(1).replace('.', ','),
            hint:
                summary.targetMinutes > 0
                    ? `von ${(summary.targetMinutes / 60).toFixed(1).replace('.', ',')} Soll-Std.`
                    : null,
        },
        {
            label: 'Arbeitstage',
            value: String(summary.workDays),
            hint: 'Tage mit Dienst',
        },
        {
            label: 'Wochenenden',
            value: String(summary.weekends),
            hint: 'mit Dienst',
        },
        {
            label: 'Nachtdienste',
            value: String(summary.nightShifts),
            hint: 'im Monat',
        },
    ];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Mein Dienstplan
                </h2>
            }
        >
            <Head title="Mein Dienstplan" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between gap-2 rounded-lg bg-white p-3 shadow-sm sm:rounded-lg">
                        <Link
                            href={route('my-roster.show', { month: month.previous })}
                            className="rounded-md px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900"
                            preserveState={false}
                        >
                            ← Vormonat
                        </Link>
                        <span className="text-base font-semibold text-gray-900">{month.label}</span>
                        <Link
                            href={route('my-roster.show', { month: month.next })}
                            className="rounded-md px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900"
                            preserveState={false}
                        >
                            Folgemonat →
                        </Link>
                    </div>

                    {hasUnpublishedRoster && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                            Der Dienstplan für diesen Monat ist noch in Bearbeitung und noch nicht
                            veröffentlicht.
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
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

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Dienste im {month.label}
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Deine Dienste aus veröffentlichten Dienstplänen sowie genehmigte
                                Abwesenheiten.
                            </p>
                        </div>

                        <div className="divide-y divide-gray-100">
                            {days.map((day) => (
                                <DayRow key={day.date} day={day} />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

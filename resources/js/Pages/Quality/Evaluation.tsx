import InputLabel from '@/Components/InputLabel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Result = {
    value: string;
    label: string;
    area: number;
    good: number;
    bad: number;
    excluded: number;
    assessed: number;
    percentGood: number | null;
};

type Props = {
    period: string;
    periods: string[];
    residentsAssessed: number;
    locationNames: string[];
    results: Result[];
};

const AREA_LABELS: Record<number, string> = {
    1: 'Bereich 1 – Erhalt der Selbständigkeit',
    2: 'Bereich 2 – Schutz vor Schädigungen',
    3: 'Bereich 3 – Besondere Bedarfslagen',
};

function barColor(percent: number | null): string {
    if (percent === null) {
        return 'bg-gray-300';
    }
    if (percent >= 80) {
        return 'bg-emerald-500';
    }
    if (percent >= 50) {
        return 'bg-amber-500';
    }
    return 'bg-red-500';
}

export default function Evaluation({
    period,
    periods,
    residentsAssessed,
    locationNames,
    results,
}: Props) {
    const areas = [1, 2, 3];

    const changePeriod = (next: string) => {
        router.get(route('quality.evaluation'), { period: next }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Qualitätsindikatoren – Auswertung
                </h2>
            }
        >
            <Head title="QI-Auswertung" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-end justify-between gap-4 overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <div>
                            <p className="text-sm text-gray-500">{locationNames.join(', ')}</p>
                            <h3 className="mt-1 text-lg font-semibold text-gray-900">
                                {residentsAssessed} erhobene Bewohner im Halbjahr {period}
                            </h3>
                            <p className="mt-1 text-xs text-gray-500">
                                Vereinfachte interne Auswertung ohne offizielle Risikoadjustierung.
                            </p>
                        </div>
                        <div>
                            <InputLabel htmlFor="period-select" value="Halbjahr" />
                            <select
                                id="period-select"
                                className="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={period}
                                onChange={(e) => changePeriod(e.target.value)}
                            >
                                {periods.map((p) => (
                                    <option key={p} value={p}>
                                        {p}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {residentsAssessed === 0 ? (
                        <div className="rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm sm:p-6">
                            Für dieses Halbjahr liegen noch keine Erhebungen vor. Erfasse die
                            Indikatoren je Bewohner über die Bewohner-Übersicht („Qualität").
                        </div>
                    ) : (
                        areas.map((area) => (
                            <div
                                key={area}
                                className="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                            >
                                <div className="border-b border-gray-200 px-6 py-3">
                                    <h4 className="text-sm font-bold uppercase tracking-wider text-[#9B1C3B]">
                                        {AREA_LABELS[area]}
                                    </h4>
                                </div>
                                <div className="divide-y divide-gray-100">
                                    {results
                                        .filter((r) => r.area === area)
                                        .map((r) => (
                                            <div key={r.value} className="px-6 py-3">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="text-sm font-medium text-gray-800">
                                                        {r.label}
                                                    </span>
                                                    <span className="text-sm text-gray-600">
                                                        {r.percentGood === null
                                                            ? 'nicht erhoben'
                                                            : `${r.percentGood}% gut`}
                                                        <span className="ml-2 text-xs text-gray-400">
                                                            ({r.good}/{r.assessed} bewertet
                                                            {r.excluded > 0
                                                                ? `, ${r.excluded} ausgeschlossen`
                                                                : ''}
                                                            )
                                                        </span>
                                                    </span>
                                                </div>
                                                <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                                    <div
                                                        className={`h-2 rounded-full ${barColor(r.percentGood)}`}
                                                        style={{
                                                            width: `${r.percentGood ?? 0}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

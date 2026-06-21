import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = { id: string; fullName: string; locationName: string | null };
type Option = { value: string; label: string };

type WoundAssessment = {
    id: string;
    assessedOn: string;
    stage: string | null;
    stageLabel: string | null;
    lengthMm: number | null;
    widthMm: number | null;
    depthMm: number | null;
    pain: number | null;
    woundDescription: string | null;
    measures: string | null;
    assessedByName: string | null;
};

type Wound = {
    id: string;
    bodySite: string;
    type: string;
    typeLabel: string;
    acquiredInHouse: boolean;
    openedOn: string;
    closedOn: string | null;
    status: string;
    statusLabel: string;
    note: string | null;
    createdByName: string | null;
    assessments: WoundAssessment[];
};

type Props = {
    resident: Resident;
    wounds: Wound[];
    types: Option[];
    statuses: Option[];
    stages: Option[];
};

function size(a: WoundAssessment): string {
    if (a.lengthMm === null && a.widthMm === null && a.depthMm === null) {
        return '–';
    }
    return `${a.lengthMm ?? '?'} × ${a.widthMm ?? '?'} × ${a.depthMm ?? '?'} mm`;
}

function WoundCard({
    resident,
    wound,
    statuses,
    stages,
}: {
    resident: Resident;
    wound: Wound;
    statuses: Option[];
    stages: Option[];
}) {
    const assessForm = useForm<{
        assessed_on: string;
        stage: string;
        length_mm: string;
        width_mm: string;
        depth_mm: string;
        pain: string;
        wound_description: string;
        measures: string;
    }>({
        assessed_on: '',
        stage: '',
        length_mm: '',
        width_mm: '',
        depth_mm: '',
        pain: '',
        wound_description: '',
        measures: '',
    });

    const submitAssessment: FormEventHandler = (e) => {
        e.preventDefault();
        assessForm.post(route('residents.wounds.assessments.store', [resident.id, wound.id]), {
            preserveScroll: true,
            onSuccess: () => assessForm.reset(),
        });
    };

    const changeStatus = (status: string) => {
        router.patch(
            route('residents.wounds.status', [resident.id, wound.id]),
            { status },
            { preserveScroll: true },
        );
    };

    const removeAssessment = (assessment: WoundAssessment) => {
        if (!window.confirm('Diesen Verlaufseintrag wirklich entfernen?')) {
            return;
        }
        router.delete(route('residents.wounds.assessments.destroy', [resident.id, assessment.id]), {
            preserveScroll: true,
        });
    };

    return (
        <div className="rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h4 className="font-semibold text-gray-900">
                            {wound.typeLabel} – {wound.bodySite}
                        </h4>
                        <span className="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                            {wound.statusLabel}
                        </span>
                        {wound.acquiredInHouse ? (
                            <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                im Haus erworben
                            </span>
                        ) : null}
                    </div>
                    <p className="text-sm text-gray-500">
                        Seit {wound.openedOn}
                        {wound.closedOn ? ` · abgeheilt ${wound.closedOn}` : ''}
                        {wound.createdByName ? ` · angelegt von ${wound.createdByName}` : ''}
                    </p>
                    {wound.note ? <p className="mt-1 text-sm text-gray-600">{wound.note}</p> : null}
                </div>
                <div className="flex items-center gap-2">
                    <select
                        aria-label="Wundstatus"
                        className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={wound.status}
                        onChange={(e) => changeStatus(e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {wound.assessments.length > 0 ? (
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr className="text-left text-gray-500">
                                <th className="py-2 pr-4 font-medium">Datum</th>
                                <th className="py-2 pr-4 font-medium">Stadium</th>
                                <th className="py-2 pr-4 font-medium">Größe (L×B×T)</th>
                                <th className="py-2 pr-4 font-medium">Schmerz</th>
                                <th className="py-2 pr-4 font-medium">Maßnahmen</th>
                                <th className="py-2 pr-4 font-medium">Erfasst von</th>
                                <th className="py-2 pr-4 font-medium" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 text-gray-700">
                            {wound.assessments.map((a) => (
                                <tr key={a.id}>
                                    <td className="whitespace-nowrap py-2 pr-4 font-medium text-gray-900">
                                        {a.assessedOn}
                                    </td>
                                    <td className="py-2 pr-4">{a.stageLabel ?? '–'}</td>
                                    <td className="whitespace-nowrap py-2 pr-4">{size(a)}</td>
                                    <td className="py-2 pr-4">{a.pain ?? '–'}</td>
                                    <td className="py-2 pr-4">{a.measures ?? '–'}</td>
                                    <td className="py-2 pr-4">{a.assessedByName ?? '–'}</td>
                                    <td className="py-2 pr-4 text-right">
                                        <button
                                            type="button"
                                            onClick={() => removeAssessment(a)}
                                            className="text-xs font-semibold text-red-600 hover:underline"
                                        >
                                            Entfernen
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="mt-3 text-sm text-gray-500">Noch kein Verlaufseintrag.</p>
            )}

            <form
                onSubmit={submitAssessment}
                className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
            >
                <div>
                    <InputLabel htmlFor={`ao-${wound.id}`} value="Datum" />
                    <TextInput
                        id={`ao-${wound.id}`}
                        type="date"
                        className="mt-1 block w-full text-sm"
                        value={assessForm.data.assessed_on}
                        onChange={(e) => assessForm.setData('assessed_on', e.target.value)}
                    />
                    <InputError className="mt-1" message={assessForm.errors.assessed_on} />
                </div>
                <div>
                    <InputLabel htmlFor={`st-${wound.id}`} value="Stadium" />
                    <select
                        id={`st-${wound.id}`}
                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={assessForm.data.stage}
                        onChange={(e) => assessForm.setData('stage', e.target.value)}
                    >
                        <option value="">– keine Angabe –</option>
                        {stages.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid grid-cols-3 gap-1">
                    <div>
                        <InputLabel htmlFor={`l-${wound.id}`} value="L (mm)" />
                        <TextInput
                            id={`l-${wound.id}`}
                            type="number"
                            className="mt-1 block w-full text-sm"
                            value={assessForm.data.length_mm}
                            onChange={(e) => assessForm.setData('length_mm', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor={`b-${wound.id}`} value="B (mm)" />
                        <TextInput
                            id={`b-${wound.id}`}
                            type="number"
                            className="mt-1 block w-full text-sm"
                            value={assessForm.data.width_mm}
                            onChange={(e) => assessForm.setData('width_mm', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor={`t-${wound.id}`} value="T (mm)" />
                        <TextInput
                            id={`t-${wound.id}`}
                            type="number"
                            className="mt-1 block w-full text-sm"
                            value={assessForm.data.depth_mm}
                            onChange={(e) => assessForm.setData('depth_mm', e.target.value)}
                        />
                    </div>
                </div>
                <div>
                    <InputLabel htmlFor={`p-${wound.id}`} value="Schmerz (0–10)" />
                    <TextInput
                        id={`p-${wound.id}`}
                        type="number"
                        className="mt-1 block w-full text-sm"
                        value={assessForm.data.pain}
                        onChange={(e) => assessForm.setData('pain', e.target.value)}
                    />
                </div>
                <div className="lg:col-span-2">
                    <InputLabel htmlFor={`wd-${wound.id}`} value="Wundbeschreibung" />
                    <TextInput
                        id={`wd-${wound.id}`}
                        className="mt-1 block w-full text-sm"
                        value={assessForm.data.wound_description}
                        onChange={(e) => assessForm.setData('wound_description', e.target.value)}
                    />
                </div>
                <div className="lg:col-span-2">
                    <InputLabel htmlFor={`m-${wound.id}`} value="Maßnahmen / Verband" />
                    <TextInput
                        id={`m-${wound.id}`}
                        className="mt-1 block w-full text-sm"
                        value={assessForm.data.measures}
                        onChange={(e) => assessForm.setData('measures', e.target.value)}
                    />
                </div>
                <div className="lg:col-span-4 flex justify-end">
                    <PrimaryButton disabled={assessForm.processing}>Verlauf erfassen</PrimaryButton>
                </div>
            </form>
        </div>
    );
}

export default function Index({ resident, wounds, types, statuses, stages }: Props) {
    const addForm = useForm<{
        body_site: string;
        type: string;
        acquired_in_house: boolean;
        opened_on: string;
        note: string;
    }>({
        body_site: '',
        type: types[0]?.value ?? 'dekubitus',
        acquired_in_house: false,
        opened_on: '',
        note: '',
    });

    const submitWound: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('residents.wounds.store', resident.id), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Wunddokumentation
                </h2>
            }
        >
            <Head title="Wunddokumentation" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Bewohner-Übersicht
                    </Link>

                    <div className="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <p className="text-sm text-gray-500">
                            {resident.locationName ?? 'Unbekannter Wohnbereich'}
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-gray-900">
                            {resident.fullName}
                        </h3>
                    </div>

                    <div className="space-y-4 overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 className="text-lg font-semibold text-gray-900">Wunden</h3>
                        {wounds.length === 0 ? (
                            <p className="text-sm text-gray-600">Keine Wunden dokumentiert.</p>
                        ) : (
                            wounds.map((wound) => (
                                <WoundCard
                                    key={wound.id}
                                    resident={resident}
                                    wound={wound}
                                    statuses={statuses}
                                    stages={stages}
                                />
                            ))
                        )}
                    </div>

                    <form
                        onSubmit={submitWound}
                        className="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg"
                    >
                        <h3 className="text-lg font-semibold text-gray-900">Wunde anlegen</h3>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="body_site" value="Lokalisation" />
                                <TextInput
                                    id="body_site"
                                    className="mt-1 block w-full"
                                    placeholder="z.B. Steiß, Ferse links"
                                    value={addForm.data.body_site}
                                    onChange={(e) => addForm.setData('body_site', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.body_site} />
                            </div>
                            <div>
                                <InputLabel htmlFor="type" value="Wundart" />
                                <select
                                    id="type"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={addForm.data.type}
                                    onChange={(e) => addForm.setData('type', e.target.value)}
                                >
                                    {types.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="opened_on" value="Festgestellt am" />
                                <TextInput
                                    id="opened_on"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={addForm.data.opened_on}
                                    onChange={(e) => addForm.setData('opened_on', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.opened_on} />
                            </div>
                            <div className="flex items-end">
                                <label className="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        checked={addForm.data.acquired_in_house}
                                        onChange={(e) =>
                                            addForm.setData('acquired_in_house', e.target.checked)
                                        }
                                    />
                                    In der Einrichtung entstanden
                                </label>
                            </div>
                        </div>
                        <div className="mt-4">
                            <InputLabel htmlFor="note" value="Notiz (optional)" />
                            <TextInput
                                id="note"
                                className="mt-1 block w-full"
                                value={addForm.data.note}
                                onChange={(e) => addForm.setData('note', e.target.value)}
                            />
                        </div>
                        <div className="mt-4 flex justify-end">
                            <PrimaryButton disabled={addForm.processing}>
                                Wunde anlegen
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

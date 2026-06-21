import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = {
    id: string;
    fullName: string;
    locationName: string | null;
};

type VitalSign = {
    id: string;
    measuredAt: string;
    systolic: number | null;
    diastolic: number | null;
    pulse: number | null;
    respiratoryRate: number | null;
    oxygenSaturation: number | null;
    bloodSugar: number | null;
    temperature: string | null;
    weight: string | null;
    note: string | null;
    recordedByName: string | null;
};

type Props = {
    resident: Resident;
    vitalSigns: VitalSign[];
};

type MeasurementField = {
    key:
        | 'systolic'
        | 'diastolic'
        | 'pulse'
        | 'respiratoryRate'
        | 'oxygenSaturation'
        | 'bloodSugar'
        | 'temperature'
        | 'weight';
    name: string;
    label: string;
    unit: string;
    step?: string;
};

const MEASUREMENTS: MeasurementField[] = [
    { key: 'systolic', name: 'systolic', label: 'RR systolisch', unit: 'mmHg' },
    { key: 'diastolic', name: 'diastolic', label: 'RR diastolisch', unit: 'mmHg' },
    { key: 'pulse', name: 'pulse', label: 'Puls', unit: '/min' },
    { key: 'respiratoryRate', name: 'respiratory_rate', label: 'Atemfrequenz', unit: '/min' },
    { key: 'oxygenSaturation', name: 'oxygen_saturation', label: 'SpO₂', unit: '%' },
    { key: 'bloodSugar', name: 'blood_sugar', label: 'Blutzucker', unit: 'mg/dl' },
    { key: 'temperature', name: 'temperature', label: 'Temperatur', unit: '°C', step: '0.1' },
    { key: 'weight', name: 'weight', label: 'Gewicht', unit: 'kg', step: '0.1' },
];

type FormShape = {
    measured_at: string;
    systolic: string;
    diastolic: string;
    pulse: string;
    respiratory_rate: string;
    oxygen_saturation: string;
    blood_sugar: string;
    temperature: string;
    weight: string;
    note: string;
};

export default function Index({ resident, vitalSigns }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<FormShape>({
        measured_at: '',
        systolic: '',
        diastolic: '',
        pulse: '',
        respiratory_rate: '',
        oxygen_saturation: '',
        blood_sugar: '',
        temperature: '',
        weight: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('residents.vitals.store', resident.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const remove = (vital: VitalSign) => {
        if (!window.confirm('Diesen Messwert wirklich löschen?')) {
            return;
        }
        router.delete(route('residents.vitals.destroy', [resident.id, vital.id]), {
            preserveScroll: true,
        });
    };

    const bloodPressure = (v: VitalSign): string =>
        v.systolic !== null && v.diastolic !== null ? `${v.systolic}/${v.diastolic}` : '–';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Vitalwerte</h2>
            }
        >
            <Head title="Vitalwerte" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Bewohner-Übersicht
                    </Link>

                    <div className="overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <p className="text-sm text-gray-500">
                            {resident.locationName ?? 'Unbekannter Wohnbereich'}
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-gray-900">
                            {resident.fullName}
                        </h3>
                    </div>

                    <form
                        onSubmit={submit}
                        className="overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6"
                    >
                        <h3 className="text-lg font-semibold text-gray-900">Messwert erfassen</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Zeitpunkt und mindestens einen Wert angeben.
                        </p>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="sm:col-span-2">
                                <InputLabel htmlFor="measured_at" value="Gemessen am" />
                                <TextInput
                                    id="measured_at"
                                    type="datetime-local"
                                    className="mt-1 block w-full"
                                    value={data.measured_at}
                                    onChange={(e) => setData('measured_at', e.target.value)}
                                />
                                <InputError className="mt-2" message={errors.measured_at} />
                            </div>

                            {MEASUREMENTS.map((field) => (
                                <div key={field.key}>
                                    <InputLabel
                                        htmlFor={field.name}
                                        value={`${field.label} (${field.unit})`}
                                    />
                                    <TextInput
                                        id={field.name}
                                        type="number"
                                        step={field.step ?? '1'}
                                        inputMode="decimal"
                                        className="mt-1 block w-full"
                                        value={data[field.name as keyof FormShape]}
                                        onChange={(e) =>
                                            setData(field.name as keyof FormShape, e.target.value)
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors[field.name as keyof FormShape]}
                                    />
                                </div>
                            ))}
                        </div>

                        <div className="mt-4">
                            <InputLabel htmlFor="note" value="Notiz (optional)" />
                            <textarea
                                id="note"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                rows={2}
                                value={data.note}
                                onChange={(e) => setData('note', e.target.value)}
                            />
                            <InputError className="mt-2" message={errors.note} />
                        </div>

                        <div className="mt-4 flex justify-end">
                            <PrimaryButton disabled={processing}>Speichern</PrimaryButton>
                        </div>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">Verlauf</h3>
                        </div>
                        <div className="p-4 sm:p-6">
                            {vitalSigns.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Noch keine Vitalwerte erfasst.
                                </p>
                            ) : (
                                <>
                                    <div className="hidden overflow-x-auto md:block">
                                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead>
                                                <tr className="text-left text-gray-500">
                                                    <th className="py-2 pr-4 font-medium">
                                                        Zeitpunkt
                                                    </th>
                                                    <th className="py-2 pr-4 font-medium">RR</th>
                                                    <th className="py-2 pr-4 font-medium">Puls</th>
                                                    <th className="py-2 pr-4 font-medium">Temp</th>
                                                    <th className="py-2 pr-4 font-medium">SpO₂</th>
                                                    <th className="py-2 pr-4 font-medium">BZ</th>
                                                    <th className="py-2 pr-4 font-medium">AF</th>
                                                    <th className="py-2 pr-4 font-medium">
                                                        Gewicht
                                                    </th>
                                                    <th className="py-2 pr-4 font-medium">
                                                        Erfasst von
                                                    </th>
                                                    <th className="py-2 pr-4 font-medium" />
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100 text-gray-700">
                                                {vitalSigns.map((v) => (
                                                    <tr key={v.id}>
                                                        <td className="whitespace-nowrap py-2 pr-4 font-medium text-gray-900">
                                                            {v.measuredAt}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {bloodPressure(v)}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.pulse ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.temperature ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.oxygenSaturation ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.bloodSugar ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.respiratoryRate ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.weight ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            {v.recordedByName ?? '–'}
                                                        </td>
                                                        <td className="py-2 pr-4 text-right">
                                                            <DangerButton
                                                                type="button"
                                                                onClick={() => remove(v)}
                                                            >
                                                                Löschen
                                                            </DangerButton>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <ul className="divide-y divide-gray-100 md:hidden">
                                        {vitalSigns.map((v) => (
                                            <li key={v.id} className="space-y-3 py-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <p className="font-medium text-gray-900">
                                                        {v.measuredAt}
                                                    </p>
                                                    <DangerButton
                                                        type="button"
                                                        onClick={() => remove(v)}
                                                    >
                                                        Löschen
                                                    </DangerButton>
                                                </div>
                                                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm text-gray-700">
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            RR
                                                        </dt>
                                                        <dd>{bloodPressure(v)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Puls
                                                        </dt>
                                                        <dd>{v.pulse ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Temp
                                                        </dt>
                                                        <dd>{v.temperature ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            SpO₂
                                                        </dt>
                                                        <dd>{v.oxygenSaturation ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            BZ
                                                        </dt>
                                                        <dd>{v.bloodSugar ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            AF
                                                        </dt>
                                                        <dd>{v.respiratoryRate ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Gewicht
                                                        </dt>
                                                        <dd>{v.weight ?? '–'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                            Erfasst von
                                                        </dt>
                                                        <dd>{v.recordedByName ?? '–'}</dd>
                                                    </div>
                                                </dl>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

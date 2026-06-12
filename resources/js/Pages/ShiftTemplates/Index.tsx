import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// Schnellauswahl typischer Schichtfarben; per Color-Picker bleibt jede Farbe moeglich.
const SHIFT_COLOR_PRESETS = [
    '#F59E0B', // Frühdienst
    '#3B82F6', // Spätdienst
    '#6366F1', // Nachtdienst
    '#10B981', // Grün
    '#EF4444', // Rot
    '#9B1C3B', // Akzent
    '#6B7280', // Grau
];

const DEFAULT_SHIFT_COLOR = '#9B1C3B';

type LocationItem = {
    id: string;
    name: string;
};

type DefaultStaffingRule = {
    id: string;
    requiredTotalStaff: number;
    requiredSpecialists: number;
};

type ShiftTemplateItem = {
    id: string;
    locationId: string;
    locationName: string | null;
    name: string;
    code: string;
    startsAt: string;
    endsAt: string;
    durationMinutes: number;
    color: string | null;
    active: boolean;
    defaultStaffingRule: DefaultStaffingRule | null;
};

type Props = {
    locations: LocationItem[];
    shiftTemplates: ShiftTemplateItem[];
};

function ShiftTemplateCard({
    shiftTemplate,
    takenColors,
}: {
    shiftTemplate: ShiftTemplateItem;
    takenColors: string[];
}) {
    const shiftForm = useForm({
        name: shiftTemplate.name,
        starts_at: shiftTemplate.startsAt,
        ends_at: shiftTemplate.endsAt,
        duration_minutes: String(shiftTemplate.durationMinutes),
        color: shiftTemplate.color ?? '',
        active: shiftTemplate.active,
    });

    // Farben, die andere Schichten im selben Wohnbereich bereits belegen.
    const currentColor = (shiftForm.data.color || '').toLowerCase();
    const colorTaken = currentColor !== '' && takenColors.includes(currentColor);

    const staffingForm = useForm({
        required_total_staff: String(
            shiftTemplate.defaultStaffingRule?.requiredTotalStaff ?? '',
        ),
        required_specialists: String(
            shiftTemplate.defaultStaffingRule?.requiredSpecialists ?? '',
        ),
    });

    const submitShift: FormEventHandler = (event) => {
        event.preventDefault();

        shiftForm.patch(route('shift-templates.update', shiftTemplate.id), {
            preserveScroll: true,
        });
    };

    const submitStaffingRule: FormEventHandler = (event) => {
        event.preventDefault();

        staffingForm.patch(
            route('shift-templates.staffing-rule.update', shiftTemplate.id),
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div className="border-b border-gray-200 p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-sm text-gray-500">
                            {shiftTemplate.locationName ?? 'Unbekannter Wohnbereich'}
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-gray-900">
                            {shiftTemplate.name}
                        </h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Code {shiftTemplate.code} · {shiftTemplate.startsAt} bis{' '}
                            {shiftTemplate.endsAt} · {shiftTemplate.durationMinutes}{' '}
                            Minuten
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className="inline-flex h-6 w-6 rounded-full border border-gray-300"
                            style={{
                                backgroundColor: shiftTemplate.color ?? '#FFFFFF',
                            }}
                        />
                        <span
                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                                shiftTemplate.active
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-gray-100 text-gray-700'
                            }`}
                        >
                            {shiftTemplate.active ? 'Aktiv' : 'Inaktiv'}
                        </span>
                    </div>
                </div>

                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="text-gray-500">Mindestbesetzung gesamt</dt>
                        <dd className="font-medium text-gray-900">
                            {shiftTemplate.defaultStaffingRule?.requiredTotalStaff ??
                                'Keine Regel'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-gray-500">Mindestanzahl Fachkräfte</dt>
                        <dd className="font-medium text-gray-900">
                            {shiftTemplate.defaultStaffingRule?.requiredSpecialists ??
                                'Keine Regel'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-gray-500">Farbe</dt>
                        <dd className="mt-1 font-medium text-gray-900">
                            {shiftTemplate.color ? (
                                <span
                                    className="inline-block h-5 w-10 rounded-md border border-gray-300"
                                    style={{ backgroundColor: shiftTemplate.color }}
                                    title="Schichtfarbe"
                                />
                            ) : (
                                '–'
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-gray-500">Wohnbereich</dt>
                        <dd className="font-medium text-gray-900">
                            {shiftTemplate.locationName ?? 'Unbekannt'}
                        </dd>
                    </div>
                </dl>
            </div>

            <div className="grid gap-6 p-6 lg:grid-cols-2">
                <form onSubmit={submitShift} className="space-y-4">
                    <h4 className="text-base font-semibold text-gray-900">
                        Schichtdaten
                    </h4>

                    <div>
                        <InputLabel htmlFor={`name_${shiftTemplate.id}`} value="Name" />
                        <TextInput
                            id={`name_${shiftTemplate.id}`}
                            value={shiftForm.data.name}
                            onChange={(event) =>
                                shiftForm.setData('name', event.target.value)
                            }
                            className="mt-1 block w-full"
                        />
                        <InputError
                            message={shiftForm.errors.name}
                            className="mt-2"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel
                                htmlFor={`starts_at_${shiftTemplate.id}`}
                                value="Von"
                            />
                            <TextInput
                                id={`starts_at_${shiftTemplate.id}`}
                                type="time"
                                value={shiftForm.data.starts_at}
                                onChange={(event) =>
                                    shiftForm.setData(
                                        'starts_at',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 block w-full"
                            />
                            <InputError
                                message={shiftForm.errors.starts_at}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor={`ends_at_${shiftTemplate.id}`}
                                value="Bis"
                            />
                            <TextInput
                                id={`ends_at_${shiftTemplate.id}`}
                                type="time"
                                value={shiftForm.data.ends_at}
                                onChange={(event) =>
                                    shiftForm.setData('ends_at', event.target.value)
                                }
                                className="mt-1 block w-full"
                            />
                            <InputError
                                message={shiftForm.errors.ends_at}
                                className="mt-2"
                            />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel
                                htmlFor={`duration_minutes_${shiftTemplate.id}`}
                                value="Dauer Minuten"
                            />
                            <TextInput
                                id={`duration_minutes_${shiftTemplate.id}`}
                                type="number"
                                min="1"
                                max="1440"
                                value={shiftForm.data.duration_minutes}
                                onChange={(event) =>
                                    shiftForm.setData(
                                        'duration_minutes',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 block w-full"
                            />
                            <InputError
                                message={shiftForm.errors.duration_minutes}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor={`color_${shiftTemplate.id}`}
                                value="Farbe"
                            />
                            <div className="mt-1 flex items-center gap-3">
                                <input
                                    id={`color_${shiftTemplate.id}`}
                                    type="color"
                                    value={shiftForm.data.color || DEFAULT_SHIFT_COLOR}
                                    onChange={(event) =>
                                        shiftForm.setData('color', event.target.value)
                                    }
                                    className="h-10 w-14 cursor-pointer rounded-md border border-gray-300 bg-white p-1"
                                    aria-label="Farbe wählen"
                                />
                                <div className="flex flex-wrap gap-1.5">
                                    {SHIFT_COLOR_PRESETS.map((preset) => {
                                        const presetLower = preset.toLowerCase();
                                        const selected = currentColor === presetLower;
                                        const taken =
                                            !selected && takenColors.includes(presetLower);

                                        return (
                                            <button
                                                key={preset}
                                                type="button"
                                                disabled={taken}
                                                onClick={() =>
                                                    shiftForm.setData('color', preset)
                                                }
                                                className={`h-6 w-6 rounded-full border ${
                                                    selected
                                                        ? 'border-gray-900 ring-2 ring-gray-400 ring-offset-1'
                                                        : 'border-gray-300'
                                                } ${
                                                    taken
                                                        ? 'cursor-not-allowed opacity-30'
                                                        : ''
                                                }`}
                                                style={{ backgroundColor: preset }}
                                                title={
                                                    taken
                                                        ? `${preset} – im Wohnbereich bereits vergeben`
                                                        : preset
                                                }
                                                aria-label={`Farbe ${preset}`}
                                            />
                                        );
                                    })}
                                </div>
                            </div>
                            {colorTaken && (
                                <p className="mt-2 text-sm text-red-700">
                                    Diese Farbe ist in diesem Wohnbereich bereits vergeben.
                                </p>
                            )}
                            <InputError
                                message={shiftForm.errors.color}
                                className="mt-2"
                            />
                        </div>
                    </div>

                    <label className="flex items-center gap-3">
                        <input
                            type="checkbox"
                            checked={shiftForm.data.active}
                            onChange={(event) =>
                                shiftForm.setData('active', event.target.checked)
                            }
                            className="rounded border-gray-300 text-[#9B1C3B] shadow-sm focus:ring-[#9B1C3B]"
                        />
                        <span className="text-sm font-medium text-gray-700">Aktiv</span>
                    </label>

                    <div className="flex justify-end">
                        <PrimaryButton disabled={shiftForm.processing || colorTaken}>
                            Schicht speichern
                        </PrimaryButton>
                    </div>
                </form>

                <form onSubmit={submitStaffingRule} className="space-y-4">
                    <h4 className="text-base font-semibold text-gray-900">
                        Standard-Mindestbesetzung
                    </h4>

                    <div>
                        <InputLabel
                            htmlFor={`required_total_staff_${shiftTemplate.id}`}
                            value="Mindestbesetzung gesamt"
                        />
                        <TextInput
                            id={`required_total_staff_${shiftTemplate.id}`}
                            type="number"
                            min="1"
                            max="50"
                            value={staffingForm.data.required_total_staff}
                            onChange={(event) =>
                                staffingForm.setData(
                                    'required_total_staff',
                                    event.target.value,
                                )
                            }
                            className="mt-1 block w-full"
                        />
                        <InputError
                            message={staffingForm.errors.required_total_staff}
                            className="mt-2"
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor={`required_specialists_${shiftTemplate.id}`}
                            value="Mindestanzahl Fachkräfte"
                        />
                        <TextInput
                            id={`required_specialists_${shiftTemplate.id}`}
                            type="number"
                            min="0"
                            max="50"
                            value={staffingForm.data.required_specialists}
                            onChange={(event) =>
                                staffingForm.setData(
                                    'required_specialists',
                                    event.target.value,
                                )
                            }
                            className="mt-1 block w-full"
                        />
                        <InputError
                            message={staffingForm.errors.required_specialists}
                            className="mt-2"
                        />
                    </div>

                    <div className="flex justify-end">
                        <PrimaryButton disabled={staffingForm.processing}>
                            Mindestbesetzung speichern
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function ShiftTemplatesIndex({
    locations,
    shiftTemplates,
}: Props) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Schichten
                </h2>
            }
        >
            <Head title="Schichten" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Schichtvorlagen
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Hier verwaltest du Schichtzeiten und Mindestbesetzung je Wohnbereich.
                            </p>
                            <p className="mt-3 text-sm text-gray-500">
                                Wohnbereiche: {locations.length}
                            </p>
                        </div>
                    </div>

                    {shiftTemplates.length === 0 ? (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="p-6 text-sm text-gray-600">
                                Noch keine Schichtvorlagen vorhanden. Führe zuerst php artisan pflegedex:create-default-shifts aus.
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {shiftTemplates.map((shiftTemplate) => (
                                <ShiftTemplateCard
                                    key={shiftTemplate.id}
                                    shiftTemplate={shiftTemplate}
                                    takenColors={shiftTemplates
                                        .filter(
                                            (other) =>
                                                other.id !== shiftTemplate.id &&
                                                other.locationId === shiftTemplate.locationId &&
                                                other.color,
                                        )
                                        .map((other) => (other.color as string).toLowerCase())}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

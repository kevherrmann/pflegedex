import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const CATEGORY_LABELS: Record<string, string> = {
    early: 'Frühdienst',
    late: 'Spätdienst',
    night: 'Nachtdienst',
};

const CATEGORY_OPTIONS = [
    { value: 'early', label: 'Frühdienst' },
    { value: 'late', label: 'Spätdienst' },
    { value: 'night', label: 'Nachtdienst' },
];

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

type CategoryStaffingItem = {
    category: string;
    label: string;
    requiredTotalStaff: number | null;
    targetTotalStaff: number | null;
    requiredSpecialists: number | null;
};

type ShiftTemplateItem = {
    id: string;
    locationId: string;
    locationName: string | null;
    name: string;
    code: string;
    category: string;
    startsAt: string;
    endsAt: string;
    durationMinutes: number;
    color: string | null;
    active: boolean;
};

type Props = {
    locations: LocationItem[];
    categoryStaffing: CategoryStaffingItem[];
    shiftTemplates: ShiftTemplateItem[];
};

function CategoryStaffingForm({ staffing }: { staffing: CategoryStaffingItem }) {
    const form = useForm({
        category: staffing.category,
        required_total_staff: staffing.requiredTotalStaff?.toString() ?? '',
        target_total_staff: staffing.targetTotalStaff?.toString() ?? '',
        required_specialists: staffing.requiredSpecialists?.toString() ?? '0',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.patch(route('shift-templates.category-staffing.update'), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="rounded-lg border border-gray-200 p-4">
            <h4 className="text-base font-semibold text-gray-900">{staffing.label}</h4>

            <div className="mt-3 space-y-3">
                <div>
                    <InputLabel
                        htmlFor={`req_${staffing.category}`}
                        value="Mindestbesetzung (pro Tag)"
                    />
                    <TextInput
                        id={`req_${staffing.category}`}
                        type="number"
                        min="1"
                        max="50"
                        value={form.data.required_total_staff}
                        onChange={(event) =>
                            form.setData('required_total_staff', event.target.value)
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={form.errors.required_total_staff} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={`target_${staffing.category}`}
                        value="Idealbesetzung (optional)"
                    />
                    <TextInput
                        id={`target_${staffing.category}`}
                        type="number"
                        min="1"
                        max="50"
                        placeholder="leer = wie Mindest"
                        value={form.data.target_total_staff}
                        onChange={(event) => form.setData('target_total_staff', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={form.errors.target_total_staff} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={`spec_${staffing.category}`}
                        value="Fachkräfte (min., pro Tag)"
                    />
                    <TextInput
                        id={`spec_${staffing.category}`}
                        type="number"
                        min="0"
                        max="50"
                        value={form.data.required_specialists}
                        onChange={(event) =>
                            form.setData('required_specialists', event.target.value)
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={form.errors.required_specialists} className="mt-2" />
                </div>

                <div className="flex justify-end">
                    <PrimaryButton disabled={form.processing}>Speichern</PrimaryButton>
                </div>
            </div>
        </form>
    );
}

function ShiftTemplateCard({
    shiftTemplate,
    takenColors,
    canDelete,
}: {
    shiftTemplate: ShiftTemplateItem;
    takenColors: string[];
    canDelete: boolean;
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

    const submitShift: FormEventHandler = (event) => {
        event.preventDefault();

        shiftForm.patch(route('shift-templates.update', shiftTemplate.id), {
            preserveScroll: true,
        });
    };

    const deleteShiftTemplate = () => {
        if (!window.confirm('Diese Schicht wirklich löschen?')) {
            return;
        }

        router.delete(route('shift-templates.destroy', shiftTemplate.id), {
            preserveScroll: true,
        });
    };

    return (
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div className="border-b border-gray-200 p-4 sm:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-sm text-gray-500">
                            {shiftTemplate.locationName ?? 'Unbekannter Wohnbereich'}
                        </p>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <h3 className="text-lg font-semibold text-gray-900">
                                {shiftTemplate.name}
                            </h3>
                            <span className="rounded-full bg-[#F7E8ED] px-2.5 py-1 text-xs font-semibold text-[#7F1730]">
                                {CATEGORY_LABELS[shiftTemplate.category] ?? shiftTemplate.category}
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-gray-600">
                            {shiftTemplate.startsAt} bis {shiftTemplate.endsAt} ·{' '}
                            {shiftTemplate.durationMinutes} Minuten
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
                        {canDelete && (
                            <button
                                type="button"
                                onClick={deleteShiftTemplate}
                                className="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50"
                            >
                                Löschen
                            </button>
                        )}
                    </div>
                </div>
            </div>

            <form onSubmit={submitShift} className="space-y-4 p-4 sm:p-6">
                <div>
                    <InputLabel htmlFor={`name_${shiftTemplate.id}`} value="Name" />
                    <TextInput
                        id={`name_${shiftTemplate.id}`}
                        value={shiftForm.data.name}
                        onChange={(event) => shiftForm.setData('name', event.target.value)}
                        className="mt-1 block w-full"
                    />
                    <InputError message={shiftForm.errors.name} className="mt-2" />
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel htmlFor={`starts_at_${shiftTemplate.id}`} value="Von" />
                        <TextInput
                            id={`starts_at_${shiftTemplate.id}`}
                            type="time"
                            value={shiftForm.data.starts_at}
                            onChange={(event) => shiftForm.setData('starts_at', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={shiftForm.errors.starts_at} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor={`ends_at_${shiftTemplate.id}`} value="Bis" />
                        <TextInput
                            id={`ends_at_${shiftTemplate.id}`}
                            type="time"
                            value={shiftForm.data.ends_at}
                            onChange={(event) => shiftForm.setData('ends_at', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={shiftForm.errors.ends_at} className="mt-2" />
                    </div>

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
                                shiftForm.setData('duration_minutes', event.target.value)
                            }
                            className="mt-1 block w-full"
                        />
                        <InputError message={shiftForm.errors.duration_minutes} className="mt-2" />
                    </div>
                </div>

                <div>
                    <InputLabel htmlFor={`color_${shiftTemplate.id}`} value="Farbe" />
                    <div className="mt-1 flex items-center gap-3">
                        <input
                            id={`color_${shiftTemplate.id}`}
                            type="color"
                            value={shiftForm.data.color || DEFAULT_SHIFT_COLOR}
                            onChange={(event) => shiftForm.setData('color', event.target.value)}
                            className="h-10 w-14 cursor-pointer rounded-md border border-gray-300 bg-white p-1"
                            aria-label="Farbe wählen"
                        />
                        <div className="flex flex-wrap gap-1.5">
                            {SHIFT_COLOR_PRESETS.map((preset) => {
                                const presetLower = preset.toLowerCase();
                                const selected = currentColor === presetLower;
                                const taken = !selected && takenColors.includes(presetLower);

                                return (
                                    <button
                                        key={preset}
                                        type="button"
                                        disabled={taken}
                                        onClick={() => shiftForm.setData('color', preset)}
                                        className={`h-6 w-6 rounded-full border ${
                                            selected
                                                ? 'border-gray-900 ring-2 ring-gray-400 ring-offset-1'
                                                : 'border-gray-300'
                                        } ${taken ? 'cursor-not-allowed opacity-30' : ''}`}
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
                    <InputError message={shiftForm.errors.color} className="mt-2" />
                </div>

                <label className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        checked={shiftForm.data.active}
                        onChange={(event) => shiftForm.setData('active', event.target.checked)}
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
        </div>
    );
}

function CreateShiftForm({ takenColors }: { takenColors: string[] }) {
    const form = useForm({
        category: 'early',
        name: '',
        starts_at: '',
        ends_at: '',
        color: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('shift-templates.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const currentColor = (form.data.color || '').toLowerCase();
    const colorTaken = currentColor !== '' && takenColors.includes(currentColor);

    return (
        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div className="border-b border-gray-200 p-4 sm:p-6">
                <h3 className="text-lg font-semibold text-gray-900">Neue Schicht anlegen</h3>
                <p className="mt-1 text-sm text-gray-600">
                    Pflicht sind je eine Früh-, Spät- und Nachtschicht. Du kannst pro Kategorie
                    weitere Schichten mit eigenen Zeiten anlegen (z. B. Früh 1 mit 6 h und Früh 2
                    mit 8 h). Die Besetzung wird pro Kategorie festgelegt (siehe „Besetzung je
                    Kategorie") und über alle Schichten der Kategorie zusammengezählt.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-4 p-4 sm:p-6">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="new_category" value="Kategorie" />
                        <select
                            id="new_category"
                            value={form.data.category}
                            onChange={(event) => form.setData('category', event.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                        >
                            {CATEGORY_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.category} className="mt-2" />
                    </div>
                    <div>
                        <InputLabel htmlFor="new_name" value="Name (z. B. Früh 1)" />
                        <TextInput
                            id="new_name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={form.errors.name} className="mt-2" />
                    </div>
                    <div>
                        <InputLabel htmlFor="new_starts_at" value="Beginn" />
                        <TextInput
                            id="new_starts_at"
                            type="time"
                            value={form.data.starts_at}
                            onChange={(event) => form.setData('starts_at', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={form.errors.starts_at} className="mt-2" />
                    </div>
                    <div>
                        <InputLabel htmlFor="new_ends_at" value="Ende" />
                        <TextInput
                            id="new_ends_at"
                            type="time"
                            value={form.data.ends_at}
                            onChange={(event) => form.setData('ends_at', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={form.errors.ends_at} className="mt-2" />
                    </div>
                    <div>
                        <InputLabel htmlFor="new_color" value="Farbe (optional, z. B. #F59E0B)" />
                        <TextInput
                            id="new_color"
                            value={form.data.color}
                            onChange={(event) => form.setData('color', event.target.value)}
                            className="mt-1 block w-full"
                        />
                        {colorTaken && (
                            <p className="mt-1 text-xs text-amber-700">
                                Diese Farbe ist im Wohnbereich bereits vergeben.
                            </p>
                        )}
                        <InputError message={form.errors.color} className="mt-2" />
                    </div>
                </div>

                <div className="flex justify-end">
                    <PrimaryButton disabled={form.processing}>Schicht anlegen</PrimaryButton>
                </div>
            </form>
        </div>
    );
}

export default function ShiftTemplatesIndex({
    locations,
    categoryStaffing,
    shiftTemplates,
}: Props) {
    const activeCountByCategory = shiftTemplates.reduce<Record<string, number>>(
        (counts, template) => {
            if (template.active) {
                counts[template.category] = (counts[template.category] ?? 0) + 1;
            }

            return counts;
        },
        {},
    );

    const pageErrors = usePage().props.errors as Record<string, string>;

    const takenColors = shiftTemplates
        .filter((template) => template.color)
        .map((template) => (template.color as string).toLowerCase());

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Schichten</h2>
            }
        >
            <Head title="Schichten" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {pageErrors.shift_template && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                            {pageErrors.shift_template}
                        </div>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">Schichtvorlagen</h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Hier verwaltest du Schichtzeiten und die Besetzung je Kategorie. Die
                                Besetzung gilt pro Kategorie (Früh/Spät/Nacht) und wird über alle
                                Schichten einer Kategorie pro Tag zusammengezählt.
                            </p>
                            <p className="mt-3 text-sm text-gray-500">
                                Wohnbereiche: {locations.length}
                            </p>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Besetzung je Kategorie
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Mindest-/Idealbesetzung und Fachkräfte gelten für die ganze
                                Kategorie — egal auf wie viele Schichten (Früh 1, Früh 2 …) sie
                                verteilt sind.
                            </p>
                        </div>
                        <div className="grid gap-4 p-4 sm:p-6 lg:grid-cols-3">
                            {categoryStaffing.map((staffing) => (
                                <CategoryStaffingForm key={staffing.category} staffing={staffing} />
                            ))}
                        </div>
                    </div>

                    <CreateShiftForm takenColors={takenColors} />

                    {shiftTemplates.length === 0 ? (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="p-4 text-sm text-gray-600 sm:p-6">
                                Noch keine Schichtvorlagen vorhanden. Führe zuerst php artisan
                                pflegedex:create-default-shifts aus.
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {shiftTemplates.map((shiftTemplate) => (
                                <ShiftTemplateCard
                                    key={shiftTemplate.id}
                                    shiftTemplate={shiftTemplate}
                                    canDelete={
                                        !shiftTemplate.active ||
                                        (activeCountByCategory[shiftTemplate.category] ?? 0) > 1
                                    }
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

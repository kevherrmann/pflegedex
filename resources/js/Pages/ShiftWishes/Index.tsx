import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo } from 'react';

type ShiftWishItem = {
    id: string;
    employeeName: string | null;
    locationName: string | null;
    date: string;
    kind: 'wish_free' | 'wish_shift';
    kindLabel: string;
    shiftTemplateName: string | null;
    note: string | null;
    createdByName: string | null;
    createdAt: string | null;
};

type LocationOption = { id: string; name: string };
type StaffOption = {
    id: string;
    name: string;
    locationId: string | null;
    qualificationLabel: string | null;
};
type ShiftTemplateOption = { id: string; name: string; locationId: string };
type KindOption = { value: string; label: string };

type Props = {
    shiftWishes: ShiftWishItem[];
    locations: LocationOption[];
    staff: StaffOption[];
    shiftTemplates: ShiftTemplateOption[];
    kinds: KindOption[];
};

function kindBadgeClass(kind: ShiftWishItem['kind']): string {
    if (kind === 'wish_shift') {
        return 'bg-blue-100 text-blue-800';
    }

    return 'bg-amber-100 text-amber-800';
}

export default function ShiftWishesIndex({
    shiftWishes,
    locations,
    staff,
    shiftTemplates,
    kinds,
}: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        user_id: string;
        date: string;
        kind: string;
        shift_template_id: string;
        note: string;
    }>({
        user_id: '',
        date: '',
        kind: kinds[0]?.value ?? 'wish_free',
        shift_template_id: '',
        note: '',
    });

    const selectedEmployee = useMemo(
        () => staff.find((member) => member.id === data.user_id) ?? null,
        [staff, data.user_id],
    );

    const templatesForEmployee = useMemo(
        () =>
            shiftTemplates.filter(
                (template) => template.locationId === selectedEmployee?.locationId,
            ),
        [shiftTemplates, selectedEmployee],
    );

    const staffByLocation = useMemo(
        () =>
            locations
                .map((location) => ({
                    location,
                    members: staff.filter((member) => member.locationId === location.id),
                }))
                .filter((group) => group.members.length > 0),
        [locations, staff],
    );

    const staffWithoutLocation = useMemo(
        () => staff.filter((member) => member.locationId === null),
        [staff],
    );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('shift-wishes.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const deleteWish = (wish: ShiftWishItem) => {
        if (!window.confirm('Diesen Wunsch wirklich löschen?')) {
            return;
        }

        router.delete(route('shift-wishes.destroy', wish.id), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Wunschdienste & Wunschfrei
                </h2>
            }
        >
            <Head title="Wunschdienste & Wunschfrei" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Neuer Wunsch
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Wunschfrei und Wunschdienste sind weiche Planungsziele.
                                Die automatische Planung berücksichtigt sie, wenn die
                                Besetzung es zulässt.
                            </p>
                        </div>

                        <form onSubmit={submit} className="space-y-6 p-6">
                            <div className="grid gap-4 md:grid-cols-3">
                                <div>
                                    <InputLabel htmlFor="user_id" value="Mitarbeiter" />
                                    <select
                                        id="user_id"
                                        value={data.user_id}
                                        onChange={(event) => {
                                            const userId = event.target.value;

                                            setData((current) => ({
                                                ...current,
                                                user_id: userId,
                                                // Schichtauswahl gehoert zum Wohnbereich des Mitarbeiters.
                                                shift_template_id: '',
                                            }));
                                        }}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    >
                                        <option value="">Bitte wählen</option>
                                        {staffByLocation.map((group) => (
                                            <optgroup
                                                key={group.location.id}
                                                label={group.location.name}
                                            >
                                                {group.members.map((member) => (
                                                    <option key={member.id} value={member.id}>
                                                        {member.name}
                                                        {member.qualificationLabel
                                                            ? ` · ${member.qualificationLabel}`
                                                            : ''}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        ))}
                                        {staffWithoutLocation.length > 0 && (
                                            <optgroup label="Ohne Wohnbereich">
                                                {staffWithoutLocation.map((member) => (
                                                    <option key={member.id} value={member.id}>
                                                        {member.name}
                                                        {member.qualificationLabel
                                                            ? ` · ${member.qualificationLabel}`
                                                            : ''}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        )}
                                    </select>
                                    <InputError message={errors.user_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="date" value="Datum" />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        value={data.date}
                                        onChange={(event) => setData('date', event.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.date} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="kind" value="Art" />
                                    <select
                                        id="kind"
                                        value={data.kind}
                                        onChange={(event) => {
                                            const kind = event.target.value;

                                            setData((current) => ({
                                                ...current,
                                                kind,
                                                // Schichtauswahl gilt nur fuer Wunschdienste.
                                                shift_template_id:
                                                    kind === 'wish_shift'
                                                        ? current.shift_template_id
                                                        : '',
                                            }));
                                        }}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    >
                                        {kinds.map((kind) => (
                                            <option key={kind.value} value={kind.value}>
                                                {kind.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.kind} className="mt-2" />
                                </div>
                            </div>

                            {data.kind === 'wish_shift' && (
                                <div className="rounded-md border border-gray-200 bg-gray-50 p-4">
                                    <InputLabel
                                        htmlFor="shift_template_id"
                                        value="Gewünschte Schicht"
                                    />
                                    {!data.user_id ? (
                                        <p className="mt-2 text-sm text-gray-600">
                                            Bitte zuerst einen Mitarbeiter wählen.
                                        </p>
                                    ) : (
                                        <select
                                            id="shift_template_id"
                                            value={data.shift_template_id}
                                            onChange={(event) =>
                                                setData('shift_template_id', event.target.value)
                                            }
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        >
                                            <option value="">Beliebige Schicht</option>
                                            {templatesForEmployee.map((template) => (
                                                <option key={template.id} value={template.id}>
                                                    {template.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    <InputError
                                        message={errors.shift_template_id}
                                        className="mt-2"
                                    />
                                </div>
                            )}

                            <div>
                                <InputLabel htmlFor="note" value="Notiz (optional)" />
                                <textarea
                                    id="note"
                                    value={data.note}
                                    onChange={(event) => setData('note', event.target.value)}
                                    rows={4}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                />
                                <InputError message={errors.note} className="mt-2" />
                            </div>

                            <div className="flex justify-end">
                                <PrimaryButton disabled={processing}>
                                    Wunsch anlegen
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Vorhandene Wünsche
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Die neuesten Einträge stehen oben.
                            </p>
                        </div>

                        <div className="p-6">
                            {shiftWishes.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Es sind noch keine Wünsche angelegt.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Datum
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Mitarbeiter
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Wohnbereich
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Art
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Schicht
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Notiz
                                                </th>
                                                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Angelegt
                                                </th>
                                                <th className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Aktion
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {shiftWishes.map((wish) => (
                                                <tr key={wish.id}>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                                                        {wish.date}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {wish.employeeName ?? 'Unbekannt'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {wish.locationName ?? 'Unbekannt'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        <span
                                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${kindBadgeClass(wish.kind)}`}
                                                        >
                                                            {wish.kindLabel}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                        {wish.kind === 'wish_shift'
                                                            ? (wish.shiftTemplateName ??
                                                              'Beliebige Schicht')
                                                            : '-'}
                                                    </td>
                                                    <td className="max-w-md px-3 py-4 text-sm text-gray-700">
                                                        {wish.note ?? '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                        {wish.createdByName ?? 'Unbekannt'}
                                                        {wish.createdAt
                                                            ? ` · ${wish.createdAt}`
                                                            : ''}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-right text-sm">
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteWish(wish)}
                                                            className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                                        >
                                                            Löschen
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

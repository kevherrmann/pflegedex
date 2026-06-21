import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = { id: string; fullName: string; locationName: string | null };
type Option = { value: string; label: string };
type Staff = { id: string; name: string };

type Administration = {
    id: string;
    slot: string;
    slotLabel: string;
    status: string;
    statusLabel: string;
    administeredByName: string | null;
    witnessName: string | null;
    administeredAt: string;
    note: string | null;
};

type Medication = {
    id: string;
    name: string;
    form: string;
    formLabel: string;
    strength: string | null;
    scheme: {
        morning: string | null;
        noon: string | null;
        evening: string | null;
        night: string | null;
    };
    prn: boolean;
    prnInstruction: string | null;
    isBtm: boolean;
    prescriber: string | null;
    startsOn: string | null;
    endsOn: string | null;
    note: string | null;
    administrations: Administration[];
};

type Props = {
    resident: Resident;
    medications: Medication[];
    selectedDate: string;
    forms: Option[];
    slots: Option[];
    statuses: Option[];
    staff: Staff[];
};

function schemeLabel(m: Medication): string {
    const s = m.scheme;
    return [s.morning ?? '0', s.noon ?? '0', s.evening ?? '0', s.night ?? '0'].join(' - ');
}

function MedicationCard({
    resident,
    medication,
    selectedDate,
    slots,
    statuses,
    staff,
}: {
    resident: Resident;
    medication: Medication;
    selectedDate: string;
    slots: Option[];
    statuses: Option[];
    staff: Staff[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        administered_on: string;
        slot: string;
        status: string;
        witness_by: string;
        note: string;
    }>({
        administered_on: selectedDate,
        slot: slots[0]?.value ?? 'morning',
        status: statuses[0]?.value ?? 'administered',
        witness_by: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('residents.medications.administer', [resident.id, medication.id]), {
            preserveScroll: true,
            onSuccess: () => reset('note', 'witness_by'),
        });
    };

    const removeAdministration = (administration: Administration) => {
        if (!window.confirm('Diese Quittierung wirklich entfernen?')) {
            return;
        }
        router.delete(
            route('residents.medications.administrations.destroy', [
                resident.id,
                administration.id,
            ]),
            { preserveScroll: true },
        );
    };

    const deactivate = () => {
        if (!window.confirm('Medikament absetzen? Der Verabreichungsnachweis bleibt erhalten.')) {
            return;
        }
        router.delete(route('residents.medications.destroy', [resident.id, medication.id]), {
            preserveScroll: true,
        });
    };

    return (
        <div className="rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex items-center gap-2">
                        <h4 className="font-semibold text-gray-900">{medication.name}</h4>
                        {medication.isBtm ? (
                            <span className="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">
                                BTM
                            </span>
                        ) : null}
                        {medication.prn ? (
                            <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                Bei Bedarf
                            </span>
                        ) : null}
                    </div>
                    <p className="text-sm text-gray-600">
                        {medication.formLabel}
                        {medication.strength ? ` · ${medication.strength}` : ''} · Schema:{' '}
                        {schemeLabel(medication)}
                    </p>
                    {medication.prnInstruction ? (
                        <p className="text-sm text-gray-500">Bedarf: {medication.prnInstruction}</p>
                    ) : null}
                    {medication.prescriber ? (
                        <p className="text-sm text-gray-500">Arzt: {medication.prescriber}</p>
                    ) : null}
                </div>
                <button
                    type="button"
                    onClick={deactivate}
                    className="text-sm font-semibold text-gray-500 hover:text-red-700 hover:underline"
                >
                    Absetzen
                </button>
            </div>

            {medication.administrations.length > 0 ? (
                <ul className="mt-3 space-y-1">
                    {medication.administrations.map((a) => (
                        <li
                            key={a.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded bg-gray-50 px-3 py-1.5 text-sm"
                        >
                            <span>
                                <span className="font-medium text-gray-900">
                                    {a.slotLabel}: {a.statusLabel}
                                </span>
                                <span className="text-gray-500">
                                    {' '}
                                    · {a.administeredAt} · {a.administeredByName ?? 'unbekannt'}
                                    {a.witnessName ? ` · Zeuge: ${a.witnessName}` : ''}
                                    {a.note ? ` · ${a.note}` : ''}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => removeAdministration(a)}
                                className="text-xs font-semibold text-red-600 hover:underline"
                            >
                                Entfernen
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-3 text-sm text-gray-500">Für diesen Tag noch nicht quittiert.</p>
            )}

            <form
                onSubmit={submit}
                className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
            >
                <div>
                    <InputLabel htmlFor={`slot-${medication.id}`} value="Zeitpunkt" />
                    <select
                        id={`slot-${medication.id}`}
                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={data.slot}
                        onChange={(e) => setData('slot', e.target.value)}
                    >
                        {slots.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <InputLabel htmlFor={`status-${medication.id}`} value="Status" />
                    <select
                        id={`status-${medication.id}`}
                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <InputLabel
                        htmlFor={`witness-${medication.id}`}
                        value={medication.isBtm ? 'Zweitkraft (BTM-Pflicht)' : 'Zweitkraft'}
                    />
                    <select
                        id={`witness-${medication.id}`}
                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value={data.witness_by}
                        onChange={(e) => setData('witness_by', e.target.value)}
                    >
                        <option value="">– keine –</option>
                        {staff.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    <InputError className="mt-1" message={errors.witness_by} />
                </div>
                <div>
                    <InputLabel htmlFor={`note-${medication.id}`} value="Notiz" />
                    <TextInput
                        id={`note-${medication.id}`}
                        className="mt-1 block w-full text-sm"
                        value={data.note}
                        onChange={(e) => setData('note', e.target.value)}
                    />
                </div>
                <PrimaryButton disabled={processing}>Quittieren</PrimaryButton>
            </form>
        </div>
    );
}

export default function Index({
    resident,
    medications,
    selectedDate,
    forms,
    slots,
    statuses,
    staff,
}: Props) {
    const addForm = useForm<{
        name: string;
        form: string;
        strength: string;
        dose_morning: string;
        dose_noon: string;
        dose_evening: string;
        dose_night: string;
        prn: boolean;
        prn_instruction: string;
        is_btm: boolean;
        prescriber: string;
        starts_on: string;
        ends_on: string;
        note: string;
    }>({
        name: '',
        form: forms[0]?.value ?? 'tablette',
        strength: '',
        dose_morning: '',
        dose_noon: '',
        dose_evening: '',
        dose_night: '',
        prn: false,
        prn_instruction: '',
        is_btm: false,
        prescriber: '',
        starts_on: '',
        ends_on: '',
        note: '',
    });

    const submitMedication: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('residents.medications.store', resident.id), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const changeDate = (date: string) => {
        router.get(
            route('residents.medications.index', resident.id),
            { date },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Medikation</h2>
            }
        >
            <Head title="Medikation" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Bewohner-Übersicht
                    </Link>

                    <div className="flex flex-wrap items-center justify-between gap-4 overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <div>
                            <p className="text-sm text-gray-500">
                                {resident.locationName ?? 'Unbekannter Wohnbereich'}
                            </p>
                            <h3 className="mt-1 text-lg font-semibold text-gray-900">
                                {resident.fullName}
                            </h3>
                        </div>
                        <div className="w-full sm:w-auto">
                            <InputLabel htmlFor="date" value="Tag" />
                            <TextInput
                                id="date"
                                type="date"
                                className="mt-1 block w-full"
                                value={selectedDate}
                                onChange={(e) => changeDate(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-4 overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <h3 className="text-lg font-semibold text-gray-900">
                            Medikationsplan ({selectedDate})
                        </h3>
                        {medications.length === 0 ? (
                            <p className="text-sm text-gray-600">
                                Noch keine Medikamente erfasst. Lege unten welche an.
                            </p>
                        ) : (
                            medications.map((medication) => (
                                <MedicationCard
                                    key={medication.id}
                                    resident={resident}
                                    medication={medication}
                                    selectedDate={selectedDate}
                                    slots={slots}
                                    statuses={statuses}
                                    staff={staff}
                                />
                            ))
                        )}
                    </div>

                    <form
                        onSubmit={submitMedication}
                        className="overflow-hidden bg-white p-4 shadow-sm sm:rounded-lg sm:p-6"
                    >
                        <h3 className="text-lg font-semibold text-gray-900">
                            Medikament hinzufügen
                        </h3>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="name" value="Bezeichnung" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={addForm.data.name}
                                    onChange={(e) => addForm.setData('name', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.name} />
                            </div>
                            <div>
                                <InputLabel htmlFor="form" value="Darreichungsform" />
                                <select
                                    id="form"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={addForm.data.form}
                                    onChange={(e) => addForm.setData('form', e.target.value)}
                                >
                                    {forms.map((f) => (
                                        <option key={f.value} value={f.value}>
                                            {f.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="strength" value="Stärke (z.B. 5 mg)" />
                                <TextInput
                                    id="strength"
                                    className="mt-1 block w-full"
                                    value={addForm.data.strength}
                                    onChange={(e) => addForm.setData('strength', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="prescriber" value="Verordnender Arzt" />
                                <TextInput
                                    id="prescriber"
                                    className="mt-1 block w-full"
                                    value={addForm.data.prescriber}
                                    onChange={(e) => addForm.setData('prescriber', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="mt-4">
                            <InputLabel value="Einnahmeschema (Morgens – Mittags – Abends – Nachts)" />
                            <div className="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <TextInput
                                    placeholder="morgens"
                                    value={addForm.data.dose_morning}
                                    onChange={(e) =>
                                        addForm.setData('dose_morning', e.target.value)
                                    }
                                />
                                <TextInput
                                    placeholder="mittags"
                                    value={addForm.data.dose_noon}
                                    onChange={(e) => addForm.setData('dose_noon', e.target.value)}
                                />
                                <TextInput
                                    placeholder="abends"
                                    value={addForm.data.dose_evening}
                                    onChange={(e) =>
                                        addForm.setData('dose_evening', e.target.value)
                                    }
                                />
                                <TextInput
                                    placeholder="nachts"
                                    value={addForm.data.dose_night}
                                    onChange={(e) => addForm.setData('dose_night', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="mt-4 flex flex-wrap gap-6">
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    checked={addForm.data.prn}
                                    onChange={(e) => addForm.setData('prn', e.target.checked)}
                                />
                                Bedarfsmedikation
                            </label>
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-red-600 focus:ring-red-500"
                                    checked={addForm.data.is_btm}
                                    onChange={(e) => addForm.setData('is_btm', e.target.checked)}
                                />
                                Betäubungsmittel (BTM)
                            </label>
                        </div>

                        {addForm.data.prn ? (
                            <div className="mt-4">
                                <InputLabel htmlFor="prn_instruction" value="Bedarfs-Hinweis" />
                                <TextInput
                                    id="prn_instruction"
                                    className="mt-1 block w-full"
                                    placeholder="z.B. bei Schmerzen, max. 3x täglich"
                                    value={addForm.data.prn_instruction}
                                    onChange={(e) =>
                                        addForm.setData('prn_instruction', e.target.value)
                                    }
                                />
                            </div>
                        ) : null}

                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="starts_on" value="Beginn (optional)" />
                                <TextInput
                                    id="starts_on"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={addForm.data.starts_on}
                                    onChange={(e) => addForm.setData('starts_on', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="ends_on" value="Ende (optional)" />
                                <TextInput
                                    id="ends_on"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={addForm.data.ends_on}
                                    onChange={(e) => addForm.setData('ends_on', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.ends_on} />
                            </div>
                        </div>

                        <div className="mt-4 flex justify-end">
                            <PrimaryButton disabled={addForm.processing}>
                                Medikament anlegen
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

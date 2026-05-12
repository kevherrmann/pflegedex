import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Location = { id: string; name: string };
type SalutationOption = { value: string; label: string };
type Resident = {
    id: string;
    salutation: string;
    locationId: string;
    firstName: string;
    lastName: string;
    fullName: string;
    birthDate: string | null;
    roomNumber: string | null;
    careLevel: number | null;
};

type ResidentsEditProps = { resident: Resident; locations: Location[]; salutations: SalutationOption[] };

export default function Edit({ resident, locations, salutations }: ResidentsEditProps) {
    const { data, setData, patch, processing, errors } = useForm({
        location_id: String(resident.locationId),
        salutation: resident.salutation,
        first_name: resident.firstName,
        last_name: resident.lastName,
        birth_date: resident.birthDate ?? '',
        room_number: resident.roomNumber ?? '',
        care_level: resident.careLevel ? String(resident.careLevel) : '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('residents.update', resident.id));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-[#333333]">Bewohner bearbeiten</h2>}>
            <Head title="Bewohner bearbeiten" />
            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <p className="mb-6 text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">{resident.fullName}</p>
                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <InputLabel htmlFor="location_id" value="Wohnbereich" />
                                <select id="location_id" name="location_id" value={data.location_id} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]" onChange={(e) => setData('location_id', e.target.value)} required>
                                    {locations.map((location) => (
                                        <option key={location.id} value={location.id}>{location.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.location_id} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="salutation" value="Anrede" />
                                <select
                                    id="salutation"
                                    name="salutation"
                                    value={data.salutation}
                                    onChange={(e) => setData('salutation', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    required
                                >
                                    <option value="">Bitte auswählen …</option>
                                    {salutations.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.salutation} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="first_name" value="Vorname" />
                                <TextInput id="first_name" name="first_name" value={data.first_name} className="mt-1 block w-full" onChange={(e) => setData('first_name', e.target.value)} required />
                                <InputError message={errors.first_name} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="last_name" value="Nachname" />
                                <TextInput id="last_name" name="last_name" value={data.last_name} className="mt-1 block w-full" onChange={(e) => setData('last_name', e.target.value)} required />
                                <InputError message={errors.last_name} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="birth_date" value="Geburtsdatum" />
                                <TextInput id="birth_date" type="date" name="birth_date" value={data.birth_date} className="mt-1 block w-full" onChange={(e) => setData('birth_date', e.target.value)} />
                                <InputError message={errors.birth_date} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="room_number" value="Zimmer" />
                                <TextInput id="room_number" name="room_number" value={data.room_number} className="mt-1 block w-full" onChange={(e) => setData('room_number', e.target.value)} />
                                <InputError message={errors.room_number} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="care_level" value="Pflegegrad" />
                                <select id="care_level" name="care_level" value={data.care_level} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]" onChange={(e) => setData('care_level', e.target.value)}>
                                    <option value="">Noch nicht gesetzt</option>
                                    {[1, 2, 3, 4, 5].map((level) => <option key={level} value={level}>{level}</option>)}
                                </select>
                                <InputError message={errors.care_level} className="mt-2" />
                            </div>
                        </div>
                        <div className="mt-8 flex justify-end gap-3">
                            <Link href={route('residents.sis.show', resident.id)} className="rounded-md px-4 py-2 text-sm font-semibold text-[#9B1C3B] hover:underline">SIS öffnen</Link>
                            <Link href={route('residents.care-plan.show', resident.id)} className="rounded-md px-4 py-2 text-sm font-semibold text-[#9B1C3B] hover:underline">Maßnahmenplan öffnen</Link>
                            <Link href={route('residents.index', { location_id: data.location_id })} className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline">Abbrechen</Link>
                            <PrimaryButton disabled={processing}>Bewohner aktualisieren</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

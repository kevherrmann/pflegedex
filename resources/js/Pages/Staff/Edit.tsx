import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type LocationOption = { id: number; name: string };
type StaffUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    locationIds: number[];
    locations: LocationOption[];
};

type StaffEditProps = {
    staffUser: StaffUser;
    locations: LocationOption[];
    roles: string[];
};

export default function Edit({ staffUser, locations, roles }: StaffEditProps) {
    const { data, setData, patch, processing, errors } = useForm<{
        name: string;
        email: string;
        password: string;
        role: string;
        location_ids: number[];
    }>({
        name: staffUser.name,
        email: staffUser.email,
        password: '',
        role: staffUser.role,
        location_ids: staffUser.locationIds,
    });

    const toggleLocation = (locationId: number, checked: boolean) => {
        setData(
            'location_ids',
            checked
                ? [...data.location_ids, locationId]
                : data.location_ids.filter((id) => id !== locationId),
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('staff.update', staffUser.id));
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-[#333333]">Mitarbeiter bearbeiten</h2>}
        >
            <Head title="Mitarbeiter bearbeiten" />
            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="space-y-5">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput id="name" name="name" value={data.name} className="mt-1 block w-full" isFocused={true} onChange={(event) => setData('name', event.target.value)} required />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="email" value="E-Mail" />
                                <TextInput id="email" type="email" name="email" value={data.email} className="mt-1 block w-full" onChange={(event) => setData('email', event.target.value)} required />
                                <InputError message={errors.email} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="password" value="Neues Passwort optional" />
                                <TextInput id="password" type="password" name="password" value={data.password} className="mt-1 block w-full" onChange={(event) => setData('password', event.target.value)} />
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="role" value="Rolle" />
                                <select id="role" value={data.role} onChange={(event) => setData('role', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]">
                                    {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                                </select>
                                <InputError message={errors.role} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel value="Wohnbereiche" />
                                <div className="mt-2 space-y-2">
                                    {locations.map((location) => (
                                        <label key={location.id} className="flex items-center gap-2 text-sm text-[#333333]">
                                            <input
                                                type="checkbox"
                                                checked={data.location_ids.includes(location.id)}
                                                onChange={(event) => toggleLocation(location.id, event.target.checked)}
                                                className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                            />
                                            {location.name}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.location_ids} className="mt-2" />
                            </div>
                        </div>

                        <div className="mt-8 flex justify-end gap-3">
                            <Link href={route('staff.index')} className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline">Abbrechen</Link>
                            <PrimaryButton disabled={processing}>Mitarbeiter aktualisieren</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

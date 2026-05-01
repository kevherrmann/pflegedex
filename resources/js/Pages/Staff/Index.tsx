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

type StaffIndexProps = {
    staffUsers: StaffUser[];
    locations: LocationOption[];
    roles: string[];
};

export default function Index({ staffUsers, locations, roles }: StaffIndexProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        email: string;
        password: string;
        role: string;
        location_ids: number[];
    }>({
        name: '',
        email: '',
        password: '',
        role: 'Pflegekraft',
        location_ids: locations.length === 1 ? [locations[0].id] : [],
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
        post(route('staff.store'), {
            onSuccess: () => reset('name', 'email', 'password'),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        PDL-Verwaltung
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Mitarbeiter
                    </h2>
                </div>
            }
        >
            <Head title="Mitarbeiter" />

            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[1fr_420px] lg:px-8">
                    <section className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Team
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold text-[#333333]">
                                Pflege, Hauswirtschaft und Technik
                            </h1>
                            <p className="mt-3 text-[#54595F]">
                                PDLs legen hier Pflegekräfte, Putzkräfte und Hausmeister an und ordnen sie Wohnbereichen zu.
                            </p>
                        </div>

                        {staffUsers.length > 0 ? (
                            <div className="divide-y divide-[#E5E7EB]">
                                {staffUsers.map((user) => (
                                    <article key={user.id} className="flex flex-col gap-3 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h3 className="text-lg font-semibold text-[#333333]">
                                                {user.name}
                                            </h3>
                                            <p className="mt-1 text-sm text-[#54595F]">
                                                {user.email} · {user.role}
                                            </p>
                                            <p className="mt-1 text-sm text-[#54595F]">
                                                {user.locations.map((location) => location.name).join(', ')}
                                            </p>
                                        </div>
                                        <Link
                                            href={route('staff.edit', user.id)}
                                            className="text-sm font-semibold text-[#9B1C3B] hover:underline"
                                        >
                                            Bearbeiten
                                        </Link>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine Mitarbeiter vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Lege rechts das erste Mitarbeiterkonto an.
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="h-fit rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-xl font-semibold text-[#333333]">
                            Mitarbeiter anlegen
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-[#54595F]">
                            Das Konto erhält nur die gewählte operative Rolle und Zugriff auf die ausgewählten Wohnbereiche.
                        </p>

                        <form onSubmit={submit} className="mt-6 space-y-5">
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
                                <InputLabel htmlFor="password" value="Startpasswort" />
                                <TextInput id="password" type="password" name="password" value={data.password} className="mt-1 block w-full" onChange={(event) => setData('password', event.target.value)} required />
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

                            <PrimaryButton disabled={processing || locations.length === 0}>
                                Mitarbeiter speichern
                            </PrimaryButton>
                        </form>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

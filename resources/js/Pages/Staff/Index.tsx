import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import EmployeeProfileFields from '@/Components/Staff/EmployeeProfileFields';

type LocationOption = { id: string; name: string };
type StaffUser = {
    id: string;
    name: string;
    email: string;
    role: string;
    locationIds: string[];
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
        location_ids: string[];
        is_nursing_specialist: boolean;
        qualification_level: string;
        weekly_hours: string;
        regular_work_days_per_week: string;
        annual_vacation_days: string;
        vacation_days_carried_over: string;
        overtime_minutes_balance: string;
        can_work_early: boolean;
        can_work_late: boolean;
        can_work_night: boolean;
        active: boolean;
    }>({
        name: '',
        email: '',
        password: '',
        role: 'Pflegekraft',
        location_ids: locations.length === 1 ? [locations[0].id] : [],
        is_nursing_specialist: false,
        qualification_level: 'aide',
        weekly_hours: '39',
        regular_work_days_per_week: '',
        annual_vacation_days: '30',
        vacation_days_carried_over: '0',
        overtime_minutes_balance: '0',
        can_work_early: true,
        can_work_late: true,
        can_work_night: false,
        active: true,
    });

    const toggleLocation = (locationId: string, checked: boolean) => {
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
            onSuccess: () =>
                reset(
                    'name',
                    'email',
                    'password',
                    'is_nursing_specialist',
                    'qualification_level',
                    'weekly_hours',
                    'regular_work_days_per_week',
                    'annual_vacation_days',
                    'vacation_days_carried_over',
                    'overtime_minutes_balance',
                    'can_work_early',
                    'can_work_late',
                    'can_work_night',
                    'active',
                ),
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
                                <select id="role" value={data.role} onChange={(event) => {
                                    const role = event.target.value;

                                    setData((currentData) => {
                                        // WBL ist immer Pflegefachkraft.
                                        if (role === 'WBL') {
                                            return {
                                                ...currentData,
                                                role,
                                                qualification_level: 'specialist',
                                                is_nursing_specialist: true,
                                            };
                                        }

                                        return {
                                            ...currentData,
                                            role,
                                            is_nursing_specialist:
                                                role === 'Pflegekraft'
                                                    ? currentData.qualification_level === 'specialist'
                                                    : false,
                                        };
                                    });
                                }} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]">
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
                            <EmployeeProfileFields data={data} setData={setData} errors={errors} />
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

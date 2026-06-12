import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import EmployeeProfileFields from '@/Components/Staff/EmployeeProfileFields';

type LocationOption = { id: string; name: string };
type EmployeeProfile = {
    employmentArea: string;
    employmentAreaLabel: string;
    isNursingSpecialist: boolean;
    qualificationLevel: string | null;
    qualificationLevelLabel: string | null;
    weeklyHours: string;
    regularWorkDaysPerWeek: number | null;
    annualVacationDays: number;
    vacationDaysCarriedOver: number;
    overtimeMinutesBalance: number;
    canWorkEarly: boolean;
    canWorkLate: boolean;
    canWorkNight: boolean;
    active: boolean;
};
type StaffUser = {
    id: string;
    name: string;
    email: string;
    role: string;
    locationIds: string[];
    locations: LocationOption[];
    employeeProfile: EmployeeProfile | null;
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
        name: staffUser.name,
        email: staffUser.email,
        password: '',
        role: staffUser.role,
        location_ids: staffUser.locationIds,
        is_nursing_specialist:
            staffUser.employeeProfile?.isNursingSpecialist ?? false,
        qualification_level:
            staffUser.employeeProfile?.qualificationLevel ??
            (staffUser.employeeProfile?.isNursingSpecialist ? 'specialist' : 'aide'),
        weekly_hours: staffUser.employeeProfile?.weeklyHours ?? '39',
        regular_work_days_per_week:
            staffUser.employeeProfile?.regularWorkDaysPerWeek?.toString() ?? '',
        annual_vacation_days:
            staffUser.employeeProfile?.annualVacationDays?.toString() ?? '30',
        vacation_days_carried_over:
            staffUser.employeeProfile?.vacationDaysCarriedOver?.toString() ?? '0',
        overtime_minutes_balance:
            staffUser.employeeProfile?.overtimeMinutesBalance?.toString() ?? '0',
        can_work_early: staffUser.employeeProfile?.canWorkEarly ?? true,
        can_work_late: staffUser.employeeProfile?.canWorkLate ?? true,
        can_work_night: staffUser.employeeProfile?.canWorkNight ?? false,
        active: staffUser.employeeProfile?.active ?? true,
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
                                                    ? currentData.is_nursing_specialist
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
                        </div>
                        <EmployeeProfileFields data={data} setData={setData} errors={errors} />
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

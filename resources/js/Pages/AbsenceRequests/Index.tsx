import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type AbsenceRequestItem = {
    id: string;
    type: string;
    typeLabel: string;
    startsOn: string;
    endsOn: string;
    daysCount: string;
    status: string;
    statusLabel: string;
    note: string | null;
    rejectionReason: string | null;
    overrideReason: string | null;
    hitsBlackout: boolean;
    createdAt: string | null;
};

type AbsenceTypeOption = {
    value: string;
    label: string;
};

type VacationBalance = {
    annualVacationDays: string;
    vacationDaysCarriedOver: string;
    totalVacationDays: string;
    approvedVacationDays: string;
    requestedVacationDays: string;
    remainingVacationDays: string;
    availableVacationDays: string;
};

type Props = {
    absenceRequests: AbsenceRequestItem[];
    canRequestAbsence: boolean;
    absenceTypes: AbsenceTypeOption[];
    vacationBalance: VacationBalance;
};

function statusClass(status: string): string {
    if (status === 'approved') {
        return 'bg-green-100 text-green-800';
    }

    if (status === 'rejected') {
        return 'bg-red-100 text-red-800';
    }

    if (status === 'cancelled') {
        return 'bg-gray-100 text-gray-700';
    }

    return 'bg-amber-100 text-amber-800';
}

export default function AbsenceRequestsIndex({
    absenceRequests,
    canRequestAbsence,
    absenceTypes,
    vacationBalance,
}: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        type: 'vacation',
        starts_on: '',
        ends_on: '',
        days_count: '',
        note: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('absence-requests.store'), {
            preserveScroll: true,
            onSuccess: () => reset('starts_on', 'ends_on', 'days_count', 'note'),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Urlaub & Abwesenheiten
                </h2>
            }
        >
            <Head title="Urlaub & Abwesenheiten" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Urlaubskonto
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Dein aktueller Überblick über genehmigten, beantragten und verfügbaren Urlaub.
                            </p>
                        </div>

                        <div className="grid gap-4 p-6 md:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Jahresurlaub</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.annualVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">Tage</p>
                            </div>

                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Vortrag aus Vorjahr</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.vacationDaysCarriedOver}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">Tage</p>
                            </div>

                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Gesamturlaub</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.totalVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">Tage</p>
                            </div>

                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Resturlaub</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.remainingVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">
                                    nach genehmigtem Urlaub
                                </p>
                            </div>

                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Genehmigt</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.approvedVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">Tage</p>
                            </div>

                            <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">Beantragt</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {vacationBalance.requestedVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">noch offen</p>
                            </div>

                            <div className="rounded-2xl border border-[#9B1C3B]/20 bg-[#9B1C3B]/5 p-4 md:col-span-2">
                                <p className="text-sm text-[#9B1C3B]">
                                    Verfügbar nach offenen Anträgen
                                </p>
                                <p className="mt-1 text-3xl font-semibold text-[#9B1C3B]">
                                    {vacationBalance.availableVacationDays}
                                </p>
                                <p className="mt-1 text-xs text-[#9B1C3B]/80">
                                    Resturlaub minus noch offene Urlaubsanträge
                                </p>
                            </div>
                        </div>
                    </div>
                    {canRequestAbsence && (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="border-b border-gray-200 p-6">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Neuen Antrag stellen
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    Stelle hier Urlaub oder Überstundenfrei zur Genehmigung ein.
                                </p>
                            </div>

                            <form onSubmit={submit} className="space-y-6 p-6">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <InputLabel htmlFor="type" value="Art" />

                                        <select
                                            id="type"
                                            value={data.type}
                                            onChange={(event) =>
                                                setData('type', event.target.value)
                                            }
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        >
                                            {absenceTypes.map((type) => (
                                                <option
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>

                                        <InputError
                                            message={errors.type}
                                            className="mt-2"
                                        />
                                    </div>

                                    <div>
                                        <InputLabel
                                            htmlFor="days_count"
                                            value="Anzahl Tage optional"
                                        />

                                        <TextInput
                                            id="days_count"
                                            type="number"
                                            min="0.5"
                                            max="366"
                                            step="0.5"
                                            value={data.days_count}
                                            onChange={(event) =>
                                                setData('days_count', event.target.value)
                                            }
                                            className="mt-1 block w-full"
                                        />

                                        <InputError
                                            message={errors.days_count}
                                            className="mt-2"
                                        />
                                    </div>

                                    <div>
                                        <InputLabel
                                            htmlFor="starts_on"
                                            value="Von"
                                        />

                                        <TextInput
                                            id="starts_on"
                                            type="date"
                                            value={data.starts_on}
                                            onChange={(event) =>
                                                setData('starts_on', event.target.value)
                                            }
                                            className="mt-1 block w-full"
                                        />

                                        <InputError
                                            message={errors.starts_on}
                                            className="mt-2"
                                        />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="ends_on" value="Bis" />

                                        <TextInput
                                            id="ends_on"
                                            type="date"
                                            value={data.ends_on}
                                            onChange={(event) =>
                                                setData('ends_on', event.target.value)
                                            }
                                            className="mt-1 block w-full"
                                        />

                                        <InputError
                                            message={errors.ends_on}
                                            className="mt-2"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel htmlFor="note" value="Notiz optional" />

                                    <textarea
                                        id="note"
                                        value={data.note}
                                        onChange={(event) =>
                                            setData('note', event.target.value)
                                        }
                                        rows={4}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                        placeholder="Zum Beispiel: Sommerurlaub, Brückentag, privater Termin ..."
                                    />

                                    <InputError message={errors.note} className="mt-2" />
                                </div>

                                <div className="flex justify-end">
                                    <PrimaryButton disabled={processing}>
                                        Antrag stellen
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Meine Anträge
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Hier siehst du deine beantragten, genehmigten und abgelehnten Abwesenheiten.
                            </p>
                        </div>

                        <div className="p-6">
                            {absenceRequests.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Du hast noch keine Anträge gestellt.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead>
                                            <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                <th className="py-3 pr-4">Art</th>
                                                <th className="py-3 pr-4">Zeitraum</th>
                                                <th className="py-3 pr-4">Tage</th>
                                                <th className="py-3 pr-4">Status</th>
                                                <th className="py-3 pr-4">Notiz</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {absenceRequests.map((request) => (
                                                <tr key={request.id}>
                                                    <td className="py-3 pr-4 font-medium text-gray-900">
                                                        {request.typeLabel}
                                                    </td>
                                                    <td className="py-3 pr-4 text-gray-700">
                                                        {request.startsOn} bis {request.endsOn}
                                                    </td>
                                                    <td className="py-3 pr-4 text-gray-700">
                                                        {request.daysCount}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <span
                                                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(
                                                                    request.status,
                                                                )}`}
                                                            >
                                                                {request.statusLabel}
                                                            </span>
                                                            {request.status === 'requested' && request.hitsBlackout && (
                                                                <span
                                                                    className="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800"
                                                                    title="Fällt in eine Urlaubssperre. Genehmigung nur als Ausnahme durch die PDL."
                                                                >
                                                                    Urlaubssperre
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="py-3 pr-4 text-gray-700">
                                                        {request.rejectionReason ? (
                                                            <span className="text-red-700">
                                                                {request.rejectionReason}
                                                            </span>
                                                        ) : request.overrideReason ? (
                                                            <span className="text-amber-700">
                                                                Ausnahme: {request.overrideReason}
                                                            </span>
                                                        ) : (
                                                            request.note ?? '—'
                                                        )}
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
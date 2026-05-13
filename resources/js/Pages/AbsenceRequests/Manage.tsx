import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type AbsenceRequestItem = {
    id: string;
    employeeName: string | null;
    employeeEmail: string | null;
    employmentAreaLabel: string | null;
    locationName: string | null;
    type: string;
    typeLabel: string;
    startsOn: string;
    endsOn: string;
    daysCount: string;
    status: string;
    statusLabel: string;
    note: string | null;
    rejectionReason: string | null;
    requestedByName: string | null;
    decidedByName: string | null;
    decidedAt: string | null;
    createdAt: string | null;
};

type Props = {
    absenceRequests: AbsenceRequestItem[];
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

export default function ManageAbsenceRequests({ absenceRequests }: Props) {
    const [rejectingRequestId, setRejectingRequestId] = useState<string | null>(null);

    const {
        data,
        setData,
        patch,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm({
        rejection_reason: '',
    });

    const approve = (absenceRequestId: string) => {
        router.patch(
            route('absence-requests.approve', absenceRequestId),
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const submitReject = (event: FormEvent, absenceRequestId: string) => {
        event.preventDefault();

        patch(route('absence-requests.reject', absenceRequestId), {
            preserveScroll: true,
            onSuccess: () => {
                reset('rejection_reason');
                clearErrors();
                setRejectingRequestId(null);
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Urlaubsanträge verwalten
                </h2>
            }
        >
            <Head title="Urlaubsanträge verwalten" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Abwesenheitsanträge
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Offene Anträge stehen oben. Genehmigte und abgelehnte Anträge bleiben nachvollziehbar sichtbar.
                            </p>
                        </div>

                        <div className="p-6">
                            {absenceRequests.length === 0 ? (
                                <p className="text-sm text-gray-600">
                                    Es liegen noch keine Anträge vor.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {absenceRequests.map((absenceRequest) => (
                                        <div
                                            key={absenceRequest.id}
                                            className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                                        >
                                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h4 className="text-base font-semibold text-gray-900">
                                                            {absenceRequest.employeeName ?? 'Unbekannter Mitarbeiter'}
                                                        </h4>

                                                        <span
                                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(
                                                                absenceRequest.status,
                                                            )}`}
                                                        >
                                                            {absenceRequest.statusLabel}
                                                        </span>
                                                    </div>

                                                    <div className="text-sm text-gray-600">
                                                        {absenceRequest.employeeEmail && (
                                                            <span>{absenceRequest.employeeEmail}</span>
                                                        )}

                                                        {absenceRequest.employmentAreaLabel && (
                                                            <span> · {absenceRequest.employmentAreaLabel}</span>
                                                        )}

                                                        {absenceRequest.locationName && (
                                                            <span> · {absenceRequest.locationName}</span>
                                                        )}
                                                    </div>

                                                    <div className="text-sm text-gray-800">
                                                        <span className="font-medium">
                                                            {absenceRequest.typeLabel}
                                                        </span>{' '}
                                                        vom {absenceRequest.startsOn} bis {absenceRequest.endsOn}
                                                        {' '}({absenceRequest.daysCount} Tage)
                                                    </div>

                                                    {absenceRequest.note && (
                                                        <p className="text-sm text-gray-700">
                                                            <span className="font-medium">Notiz:</span>{' '}
                                                            {absenceRequest.note}
                                                        </p>
                                                    )}

                                                    {absenceRequest.rejectionReason && (
                                                        <p className="text-sm text-red-700">
                                                            <span className="font-medium">Ablehnungsgrund:</span>{' '}
                                                            {absenceRequest.rejectionReason}
                                                        </p>
                                                    )}

                                                    {absenceRequest.decidedByName && (
                                                        <p className="text-xs text-gray-500">
                                                            Entschieden von {absenceRequest.decidedByName}
                                                            {absenceRequest.decidedAt
                                                                ? ` am ${absenceRequest.decidedAt}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>

                                                {absenceRequest.status === 'requested' && (
                                                    <div className="flex flex-col gap-2 sm:flex-row lg:flex-col xl:flex-row">
                                                        <PrimaryButton
                                                            type="button"
                                                            onClick={() => approve(absenceRequest.id)}
                                                        >
                                                            Genehmigen
                                                        </PrimaryButton>

                                                        <SecondaryButton
                                                            type="button"
                                                            onClick={() => {
                                                                clearErrors();
                                                                reset('rejection_reason');
                                                                setRejectingRequestId(
                                                                    rejectingRequestId === absenceRequest.id
                                                                        ? null
                                                                        : absenceRequest.id,
                                                                );
                                                            }}
                                                        >
                                                            Ablehnen
                                                        </SecondaryButton>
                                                    </div>
                                                )}
                                            </div>

                                            {rejectingRequestId === absenceRequest.id && (
                                                <form
                                                    onSubmit={(event) =>
                                                        submitReject(event, absenceRequest.id)
                                                    }
                                                    className="mt-4 space-y-3 rounded-xl bg-gray-50 p-4"
                                                >
                                                    <label
                                                        htmlFor={`rejection_reason_${absenceRequest.id}`}
                                                        className="block text-sm font-medium text-gray-700"
                                                    >
                                                        Ablehnungsgrund
                                                    </label>

                                                    <textarea
                                                        id={`rejection_reason_${absenceRequest.id}`}
                                                        value={data.rejection_reason}
                                                        onChange={(event) =>
                                                            setData(
                                                                'rejection_reason',
                                                                event.target.value,
                                                            )
                                                        }
                                                        rows={3}
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                                        placeholder="Zum Beispiel: Mindestbesetzung wäre gefährdet."
                                                    />

                                                    <InputError
                                                        message={errors.rejection_reason}
                                                    />

                                                    <div className="flex justify-end gap-2">
                                                        <SecondaryButton
                                                            type="button"
                                                            onClick={() => {
                                                                clearErrors();
                                                                reset('rejection_reason');
                                                                setRejectingRequestId(null);
                                                            }}
                                                        >
                                                            Abbrechen
                                                        </SecondaryButton>

                                                        <PrimaryButton disabled={processing}>
                                                            Ablehnung speichern
                                                        </PrimaryButton>
                                                    </div>
                                                </form>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

type TemplateOption = {
    id: string;
    name: string;
    code: string;
};

type Candidate = {
    userId: string;
    name: string;
    isSpecialist: boolean;
    coversSpecialistNeed: boolean;
    plannedMinutes: number;
    targetMinutes: number;
    remainingMinutes: number;
    remainingLabel: string;
    feasibleTemplates: TemplateOption[];
};

type OpenSlot = {
    date: string;
    weekday: string;
    isWeekend: boolean;
    category: string;
    categoryLabel: string;
    requiredTotal: number;
    currentTotal: number;
    missingTotal: number;
    requiredSpecialists: number;
    currentSpecialists: number;
    missingSpecialists: number;
    templates: TemplateOption[];
    candidates: Candidate[];
};

type EmployeeOption = {
    id: string;
    name: string;
    isSpecialist: boolean;
};

type RosterInfo = {
    id: string;
    locationName: string | null;
    year: number;
    month: number;
    monthLabel: string;
    status: string;
    statusLabel: string;
    isEditable: boolean;
};

type Props = {
    roster: RosterInfo;
    openSlots: OpenSlot[];
    employees: EmployeeOption[];
    today: string;
};

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}.${month}.${year}`;
}

function SickReportForm({ employees, today }: { employees: EmployeeOption[]; today: string }) {
    const form = useForm({
        user_id: '',
        starts_on: today,
        ends_on: today,
        note: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('absence-requests.report-sick'), {
            preserveScroll: true,
            onSuccess: () => form.reset('note'),
        });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="sm:col-span-2 lg:col-span-1">
                <InputLabel htmlFor="sick_user" value="Mitarbeiter" />
                <select
                    id="sick_user"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    value={form.data.user_id}
                    onChange={(event) => form.setData('user_id', event.target.value)}
                >
                    <option value="">Bitte wählen …</option>
                    {employees.map((employee) => (
                        <option key={employee.id} value={employee.id}>
                            {employee.name}
                            {employee.isSpecialist ? ' (Fachkraft)' : ''}
                        </option>
                    ))}
                </select>
                <InputError className="mt-1" message={form.errors.user_id} />
            </div>

            <div>
                <InputLabel htmlFor="sick_starts_on" value="Von" />
                <TextInput
                    id="sick_starts_on"
                    type="date"
                    className="mt-1 block w-full"
                    value={form.data.starts_on}
                    onChange={(event) => form.setData('starts_on', event.target.value)}
                />
                <InputError className="mt-1" message={form.errors.starts_on} />
            </div>

            <div>
                <InputLabel htmlFor="sick_ends_on" value="Bis" />
                <TextInput
                    id="sick_ends_on"
                    type="date"
                    className="mt-1 block w-full"
                    value={form.data.ends_on}
                    onChange={(event) => form.setData('ends_on', event.target.value)}
                />
                <InputError className="mt-1" message={form.errors.ends_on} />
            </div>

            <div className="flex items-end">
                <DangerButton
                    type="submit"
                    disabled={form.processing}
                    className="w-full justify-center"
                >
                    Krank melden
                </DangerButton>
            </div>

            <div className="sm:col-span-2 lg:col-span-4">
                <InputLabel htmlFor="sick_note" value="Notiz (optional)" />
                <TextInput
                    id="sick_note"
                    type="text"
                    className="mt-1 block w-full"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    placeholder="z. B. telefonisch gemeldet, AU folgt"
                />
                <InputError className="mt-1" message={form.errors.note} />
            </div>
        </form>
    );
}

function SlotCard({
    slot,
    rosterId,
    editable,
}: {
    slot: OpenSlot;
    rosterId: string;
    editable: boolean;
}) {
    const [userId, setUserId] = useState('');
    const [processing, setProcessing] = useState(false);

    const selectedCandidate = useMemo(
        () => slot.candidates.find((candidate) => candidate.userId === userId) ?? null,
        [slot.candidates, userId],
    );

    const [templateId, setTemplateId] = useState('');

    const effectiveTemplateId = useMemo(() => {
        if (!selectedCandidate) {
            return '';
        }

        if (templateId && selectedCandidate.feasibleTemplates.some((t) => t.id === templateId)) {
            return templateId;
        }

        return selectedCandidate.feasibleTemplates[0]?.id ?? '';
    }, [selectedCandidate, templateId]);

    const assign = () => {
        if (!selectedCandidate || !effectiveTemplateId) {
            return;
        }

        router.post(
            route('rosters.shifts.store', rosterId),
            {
                user_id: selectedCandidate.userId,
                shift_template_id: effectiveTemplateId,
                date: slot.date,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setUserId('');
                    setTemplateId('');
                },
            },
        );
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="font-semibold text-gray-900">{formatDate(slot.date)}</span>
                    <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                            slot.isWeekend
                                ? 'bg-amber-100 text-amber-800'
                                : 'bg-gray-100 text-gray-700'
                        }`}
                    >
                        {slot.weekday}
                    </span>
                    <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                        {slot.categoryLabel}
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-800">
                        {slot.currentTotal}/{slot.requiredTotal} besetzt
                    </span>
                    {slot.missingSpecialists > 0 && (
                        <span className="rounded-full bg-purple-100 px-2 py-0.5 font-medium text-purple-800">
                            {slot.missingSpecialists} Fachkraft fehlt
                        </span>
                    )}
                </div>
            </div>

            {!editable ? (
                <p className="mt-3 text-sm text-gray-500">
                    Dieser Dienstplan ist nicht bearbeitbar – eine Vertretung kann nicht eingetragen
                    werden.
                </p>
            ) : slot.candidates.length === 0 ? (
                <p className="mt-3 text-sm text-gray-500">
                    Kein regelkonform einsetzbarer Mitarbeiter verfügbar (Ruhezeit, Folgetage,
                    Wochenstunden o. Ä.).
                </p>
            ) : (
                <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                        <InputLabel
                            htmlFor={`cand_${slot.date}_${slot.category}`}
                            value="Vertretung wählen"
                        />
                        <select
                            id={`cand_${slot.date}_${slot.category}`}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            value={userId}
                            onChange={(event) => {
                                setUserId(event.target.value);
                                setTemplateId('');
                            }}
                        >
                            <option value="">Bitte wählen …</option>
                            {slot.candidates.map((candidate) => (
                                <option key={candidate.userId} value={candidate.userId}>
                                    {candidate.name}
                                    {candidate.isSpecialist ? ' · Fachkraft' : ''}
                                    {' · '}
                                    {candidate.targetMinutes > 0
                                        ? `${candidate.remainingLabel} frei`
                                        : 'kein Soll'}
                                </option>
                            ))}
                        </select>
                    </div>

                    {selectedCandidate && selectedCandidate.feasibleTemplates.length > 1 && (
                        <div className="flex-1">
                            <InputLabel
                                htmlFor={`tpl_${slot.date}_${slot.category}`}
                                value="Schicht"
                            />
                            <select
                                id={`tpl_${slot.date}_${slot.category}`}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={effectiveTemplateId}
                                onChange={(event) => setTemplateId(event.target.value)}
                            >
                                {selectedCandidate.feasibleTemplates.map((template) => (
                                    <option key={template.id} value={template.id}>
                                        {template.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <PrimaryButton
                        type="button"
                        onClick={assign}
                        disabled={!selectedCandidate || processing}
                        className="justify-center"
                    >
                        Einsetzen
                    </PrimaryButton>
                </div>
            )}
        </div>
    );
}

export default function Replacements({ roster, openSlots, employees, today }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Vertretung & Krankmeldung
                </h2>
            }
        >
            <Head title="Vertretung & Krankmeldung" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <Link
                        href={route('rosters.show', roster.id)}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        ← Zurück zum Dienstplan
                    </Link>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                {roster.monthLabel}
                                {roster.locationName ? ` · ${roster.locationName}` : ''}
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Status: {roster.statusLabel}. Offene Schichten ab heute (
                                {formatDate(today)}). Bei einer Krankmeldung werden die Dienste der
                                Person freigeräumt – die Vertretung wird hier bewusst manuell
                                besetzt.
                            </p>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-base font-semibold text-gray-900">
                                Mitarbeiter krankmelden
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Die Person wird sofort als abwesend erfasst und aus den betroffenen
                                Diensten entfernt. Vergangene Tage bleiben unverändert.
                            </p>
                        </div>
                        <div className="p-4 sm:p-6">
                            {roster.isEditable ? (
                                <SickReportForm employees={employees} today={today} />
                            ) : (
                                <p className="text-sm text-gray-500">
                                    Dieser Dienstplan ist nicht bearbeitbar.
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="flex items-center justify-between border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-base font-semibold text-gray-900">
                                Offene Schichten
                            </h3>
                            <span className="rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
                                {openSlots.length}
                            </span>
                        </div>
                        <div className="p-4 sm:p-6">
                            {openSlots.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500">
                                    🎉 Keine offenen Schichten – ab heute ist alles besetzt.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {openSlots.map((slot) => (
                                        <SlotCard
                                            key={`${slot.date}-${slot.category}`}
                                            slot={slot}
                                            rosterId={roster.id}
                                            editable={roster.isEditable}
                                        />
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

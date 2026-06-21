import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Resident = {
    id: string;
    fullName: string;
    locationName: string | null;
};

type Option = { value: string; label: string };

type Completion = {
    id: string;
    status: string;
    statusLabel: string;
    note: string | null;
    performedByName: string | null;
    performedAt: string;
};

type Task = {
    id: string;
    title: string;
    category: string;
    categoryLabel: string;
    schedule: string | null;
    description: string | null;
    completions: Completion[];
};

type Props = {
    resident: Resident;
    tasks: Task[];
    selectedDate: string;
    categories: Option[];
    statuses: Option[];
};

function TaskRow({
    resident,
    task,
    selectedDate,
    statuses,
}: {
    resident: Resident;
    task: Task;
    selectedDate: string;
    statuses: Option[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        performed_on: string;
        status: string;
        note: string;
    }>({
        performed_on: selectedDate,
        status: statuses[0]?.value ?? 'done',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('residents.care-tasks.complete', [resident.id, task.id]), {
            preserveScroll: true,
            onSuccess: () => reset('note'),
        });
    };

    const removeCompletion = (completion: Completion) => {
        if (!window.confirm('Diese Quittierung wirklich entfernen?')) {
            return;
        }
        router.delete(
            route('residents.care-tasks.completions.destroy', [resident.id, completion.id]),
            {
                preserveScroll: true,
            },
        );
    };

    const deactivate = () => {
        if (
            !window.confirm('Maßnahme deaktivieren? Bereits erbrachte Nachweise bleiben erhalten.')
        ) {
            return;
        }
        router.delete(route('residents.care-tasks.destroy', [resident.id, task.id]), {
            preserveScroll: true,
        });
    };

    return (
        <div className="rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <span className="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                        {task.categoryLabel}
                    </span>
                    <h4 className="mt-1 font-semibold text-gray-900">{task.title}</h4>
                    {task.schedule ? (
                        <p className="text-sm text-gray-500">Turnus: {task.schedule}</p>
                    ) : null}
                    {task.description ? (
                        <p className="mt-1 text-sm text-gray-600">{task.description}</p>
                    ) : null}
                </div>
                <button
                    type="button"
                    onClick={deactivate}
                    className="text-sm font-semibold text-gray-500 hover:text-red-700 hover:underline"
                >
                    Deaktivieren
                </button>
            </div>

            {task.completions.length > 0 ? (
                <ul className="mt-3 space-y-1">
                    {task.completions.map((c) => (
                        <li
                            key={c.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded bg-gray-50 px-3 py-1.5 text-sm"
                        >
                            <span>
                                <span className="font-medium text-gray-900">{c.statusLabel}</span>
                                <span className="text-gray-500">
                                    {' '}
                                    · {c.performedAt} · {c.performedByName ?? 'unbekannt'}
                                    {c.note ? ` · ${c.note}` : ''}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => removeCompletion(c)}
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

            <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2">
                <div>
                    <InputLabel htmlFor={`status-${task.id}`} value="Status" />
                    <select
                        id={`status-${task.id}`}
                        className="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
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
                <div className="flex-1">
                    <InputLabel htmlFor={`note-${task.id}`} value="Notiz (optional)" />
                    <TextInput
                        id={`note-${task.id}`}
                        className="mt-1 block w-full text-sm"
                        value={data.note}
                        onChange={(e) => setData('note', e.target.value)}
                    />
                    <InputError className="mt-1" message={errors.note} />
                </div>
                <PrimaryButton disabled={processing}>Quittieren</PrimaryButton>
            </form>
        </div>
    );
}

export default function Index({ resident, tasks, selectedDate, categories, statuses }: Props) {
    const addForm = useForm<{
        title: string;
        category: string;
        schedule: string;
        description: string;
    }>({
        title: '',
        category: categories[0]?.value ?? 'grundpflege',
        schedule: '',
        description: '',
    });

    const submitTask: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('residents.care-tasks.store', resident.id), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const changeDate = (date: string) => {
        router.get(
            route('residents.care-tasks.index', resident.id),
            { date },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Durchführungsnachweis
                </h2>
            }
        >
            <Head title="Durchführungsnachweis" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <Link
                        href={route('residents.index')}
                        className="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Zurück zur Bewohner-Übersicht
                    </Link>

                    <div className="flex flex-wrap items-center justify-between gap-4 overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <div>
                            <p className="text-sm text-gray-500">
                                {resident.locationName ?? 'Unbekannter Wohnbereich'}
                            </p>
                            <h3 className="mt-1 text-lg font-semibold text-gray-900">
                                {resident.fullName}
                            </h3>
                        </div>
                        <div>
                            <InputLabel htmlFor="date" value="Tag" />
                            <TextInput
                                id="date"
                                type="date"
                                className="mt-1 block"
                                value={selectedDate}
                                onChange={(e) => changeDate(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-4 overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 className="text-lg font-semibold text-gray-900">
                            Geplante Maßnahmen ({selectedDate})
                        </h3>
                        {tasks.length === 0 ? (
                            <p className="text-sm text-gray-600">
                                Noch keine Maßnahmen geplant. Lege unten welche an.
                            </p>
                        ) : (
                            tasks.map((task) => (
                                <TaskRow
                                    key={task.id}
                                    resident={resident}
                                    task={task}
                                    selectedDate={selectedDate}
                                    statuses={statuses}
                                />
                            ))
                        )}
                    </div>

                    <form
                        onSubmit={submitTask}
                        className="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg"
                    >
                        <h3 className="text-lg font-semibold text-gray-900">Maßnahme hinzufügen</h3>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="title" value="Bezeichnung" />
                                <TextInput
                                    id="title"
                                    className="mt-1 block w-full"
                                    value={addForm.data.title}
                                    onChange={(e) => addForm.setData('title', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.title} />
                            </div>
                            <div>
                                <InputLabel htmlFor="category" value="Kategorie" />
                                <select
                                    id="category"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={addForm.data.category}
                                    onChange={(e) => addForm.setData('category', e.target.value)}
                                >
                                    {categories.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError className="mt-2" message={addForm.errors.category} />
                            </div>
                            <div>
                                <InputLabel htmlFor="schedule" value="Turnus (optional)" />
                                <TextInput
                                    id="schedule"
                                    className="mt-1 block w-full"
                                    placeholder="z.B. täglich morgens"
                                    value={addForm.data.schedule}
                                    onChange={(e) => addForm.setData('schedule', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.schedule} />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel htmlFor="description" value="Beschreibung (optional)" />
                                <textarea
                                    id="description"
                                    rows={2}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    value={addForm.data.description}
                                    onChange={(e) => addForm.setData('description', e.target.value)}
                                />
                                <InputError className="mt-2" message={addForm.errors.description} />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <SecondaryButton type="button" onClick={() => addForm.reset()}>
                                Zurücksetzen
                            </SecondaryButton>
                            <PrimaryButton disabled={addForm.processing}>
                                Maßnahme anlegen
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

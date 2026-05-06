import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type AuditEntry = {
    id: string;
    event: string;
    modelLabel: string;
    modelKey: string | null;
    auditableId: string;
    userName: string | null;
    userEmail: string | null;
    createdAt: string | null;
    changedFields: Array<{ field: string; old: string | null; new: string | null }>;
    ipAddress: string | null;
    url: string | null;
};

type Pagination = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
};

type Filters = {
    userId: string | null;
    model: string | null;
    event: string | null;
    from: string | null;
    to: string | null;
};

type FilterOptions = {
    users: Array<{ id: string; name: string; email: string }>;
    models: Array<{ key: string; label: string }>;
    events: Array<{ key: string; label: string }>;
};

type Props = {
    audits: AuditEntry[];
    pagination: Pagination;
    filters: Filters;
    filterOptions: FilterOptions;
};

const eventColor = (event: string): string => {
    switch (event) {
        case 'created':
            return 'bg-green-100 text-green-800';
        case 'updated':
            return 'bg-amber-100 text-amber-800';
        case 'deleted':
            return 'bg-red-100 text-red-800';
        case 'restored':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const eventLabel = (event: string): string => {
    switch (event) {
        case 'created':
            return 'Angelegt';
        case 'updated':
            return 'Geändert';
        case 'deleted':
            return 'Gelöscht';
        case 'restored':
            return 'Wiederhergestellt';
        default:
            return event;
    }
};

export default function Index({ audits, pagination, filters, filterOptions }: Props) {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const toggle = (id: string) => {
        const next = new Set(expanded);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        setExpanded(next);
    };

    const [form, setForm] = useState({
        user_id: filters.userId ?? '',
        model: filters.model ?? '',
        event: filters.event ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        if (form.user_id) params.user_id = form.user_id;
        if (form.model) params.model = form.model;
        if (form.event) params.event = form.event;
        if (form.from) params.from = form.from;
        if (form.to) params.to = form.to;
        router.get(route('audit.index'), params, { preserveState: true, preserveScroll: true });
    };

    const reset = () => {
        setForm({ user_id: '', model: '', event: '', from: '', to: '' });
        router.get(route('audit.index'));
    };

    const goToPage = (page: number) => {
        const params: Record<string, string | number> = { page };
        if (filters.userId) params.user_id = filters.userId;
        if (filters.model) params.model = filters.model;
        if (filters.event) params.event = filters.event;
        if (filters.from) params.from = filters.from;
        if (filters.to) params.to = filters.to;
        router.get(route('audit.index'), params, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-[#333333]">Audit-Log</h2>}
        >
            <Head title="Audit-Log" />
            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* Filter-Karte */}
                    <form onSubmit={submit} className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="grid gap-4 md:grid-cols-5">
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-widest text-[#7F1730]" htmlFor="filter-user">
                                    Benutzer
                                </label>
                                <select
                                    id="filter-user"
                                    value={form.user_id}
                                    onChange={(e) => setForm({ ...form, user_id: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    <option value="">Alle</option>
                                    {filterOptions.users.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-widest text-[#7F1730]" htmlFor="filter-model">
                                    Modell-Typ
                                </label>
                                <select
                                    id="filter-model"
                                    value={form.model}
                                    onChange={(e) => setForm({ ...form, model: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    <option value="">Alle</option>
                                    {filterOptions.models.map((m) => (
                                        <option key={m.key} value={m.key}>
                                            {m.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-widest text-[#7F1730]" htmlFor="filter-event">
                                    Ereignis
                                </label>
                                <select
                                    id="filter-event"
                                    value={form.event}
                                    onChange={(e) => setForm({ ...form, event: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                >
                                    <option value="">Alle</option>
                                    {filterOptions.events.map((e) => (
                                        <option key={e.key} value={e.key}>
                                            {e.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-widest text-[#7F1730]" htmlFor="filter-from">
                                    Von
                                </label>
                                <input
                                    id="filter-from"
                                    type="date"
                                    value={form.from}
                                    onChange={(e) => setForm({ ...form, from: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-widest text-[#7F1730]" htmlFor="filter-to">
                                    Bis
                                </label>
                                <input
                                    id="filter-to"
                                    type="date"
                                    value={form.to}
                                    onChange={(e) => setForm({ ...form, to: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={reset}
                                className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline"
                            >
                                Filter zurücksetzen
                            </button>
                            <button
                                type="submit"
                                className="rounded-md bg-[#9B1C3B] px-4 py-2 text-sm font-semibold uppercase tracking-widest text-white hover:bg-[#7A1430]"
                            >
                                Filter anwenden
                            </button>
                        </div>
                    </form>

                    {/* Liste */}
                    <div className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-gray-200 p-4 text-sm text-gray-600">
                            {pagination.total} Einträge gesamt — Seite {pagination.currentPage} von {pagination.lastPage}
                        </div>
                        {audits.length === 0 ? (
                            <div className="p-8 text-center text-gray-500">Keine Einträge für diese Filter.</div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {audits.map((a) => {
                                    const isOpen = expanded.has(a.id);
                                    return (
                                        <li key={a.id} className="p-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${eventColor(a.event)}`}>
                                                        {eventLabel(a.event)}
                                                    </span>
                                                    <span className="font-medium text-gray-800">{a.modelLabel}</span>
                                                    <span className="text-xs text-gray-500">{a.auditableId.slice(0, 8)}…</span>
                                                </div>
                                                <div className="text-right text-xs text-gray-500">
                                                    <div>{a.createdAt}</div>
                                                    <div>{a.userName ?? <span className="italic">System</span>}</div>
                                                </div>
                                            </div>
                                            {a.changedFields.length > 0 && (
                                                <div className="mt-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggle(a.id)}
                                                        className="text-xs font-semibold text-[#9B1C3B] hover:underline"
                                                    >
                                                        {isOpen ? 'Details ausblenden' : `${a.changedFields.length} Felder anzeigen`}
                                                    </button>
                                                    {isOpen && (
                                                        <table className="mt-2 w-full text-xs">
                                                            <thead className="text-left text-gray-500">
                                                                <tr>
                                                                    <th className="py-1 pr-3">Feld</th>
                                                                    <th className="py-1 pr-3">Alt</th>
                                                                    <th className="py-1">Neu</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {a.changedFields.map((c) => (
                                                                    <tr key={c.field} className="border-t border-gray-100 align-top">
                                                                        <td className="py-1 pr-3 font-medium text-gray-700">{c.field}</td>
                                                                        <td className="py-1 pr-3 text-red-700">
                                                                            {c.old ?? <span className="italic text-gray-400">—</span>}
                                                                        </td>
                                                                        <td className="py-1 text-green-700">
                                                                            {c.new ?? <span className="italic text-gray-400">—</span>}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        {/* Pagination */}
                        {pagination.lastPage > 1 && (
                            <div className="flex items-center justify-between border-t border-gray-200 p-4 text-sm">
                                <button
                                    type="button"
                                    disabled={pagination.currentPage <= 1}
                                    onClick={() => goToPage(pagination.currentPage - 1)}
                                    className="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-50"
                                >
                                    Zurück
                                </button>
                                <span className="text-gray-600">
                                    Seite {pagination.currentPage} / {pagination.lastPage}
                                </span>
                                <button
                                    type="button"
                                    disabled={pagination.currentPage >= pagination.lastPage}
                                    onClick={() => goToPage(pagination.currentPage + 1)}
                                    className="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-50"
                                >
                                    Weiter
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

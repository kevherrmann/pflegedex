import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type WishItem = {
    id: string;
    date: string;
    kind: 'wish_free' | 'wish_shift';
    kindLabel: string;
    employeeName: string | null;
    locationName: string | null;
    shiftTemplateName: string | null;
    note: string | null;
    createdAt: string | null;
};

type Props = {
    myWishes: WishItem[];
    teamWishes: WishItem[] | null;
    canCreateOwn: boolean;
    isManager: boolean;
};

function formatDate(iso: string): string {
    const parsed = new Date(`${iso}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat('de-DE', {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(parsed);
}

export default function ShiftWishesIndex({ myWishes, teamWishes, canCreateOwn, isManager }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        date: string;
        note: string;
    }>({
        date: '',
        note: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('shift-wishes.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const deleteWish = (wish: WishItem) => {
        if (!window.confirm('Diesen Wunschfrei-Eintrag wirklich löschen?')) {
            return;
        }

        router.delete(route('shift-wishes.destroy', wish.id), {
            preserveScroll: true,
        });
    };

    const containerMax = isManager ? 'max-w-5xl' : 'max-w-2xl';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">Wunschfrei</h2>
            }
        >
            <Head title="Wunschfrei" />

            <div className="py-6 sm:py-8 lg:py-12">
                <div className={`mx-auto ${containerMax} space-y-6 px-4 sm:px-6 lg:px-8`}>
                    {canCreateOwn && (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="border-b border-gray-200 p-4 sm:p-6">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Wunschfrei-Tag eintragen
                                </h3>
                                <p className="mt-1 text-sm leading-6 text-gray-600">
                                    Trage Tage ein, an denen du frei haben möchtest. Das ist{' '}
                                    <span className="font-medium">kein Urlaubsantrag</span> – es
                                    verbraucht keinen Urlaub und muss nicht genehmigt werden. Die
                                    Dienstplanung versucht, deine Wunschfrei-Tage zu
                                    berücksichtigen, solange die Besetzung es zulässt. Für echten
                                    Urlaub nutze bitte den Menüpunkt „Urlaub".
                                </p>
                            </div>

                            <form onSubmit={submit} className="space-y-4 p-4 sm:p-6">
                                <div>
                                    <InputLabel
                                        htmlFor="date"
                                        value="Tag, an dem du frei möchtest"
                                    />
                                    <TextInput
                                        id="date"
                                        type="date"
                                        value={data.date}
                                        onChange={(event) => setData('date', event.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.date} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="note" value="Notiz (optional)" />
                                    <textarea
                                        id="note"
                                        value={data.note}
                                        onChange={(event) => setData('note', event.target.value)}
                                        rows={3}
                                        placeholder="z. B. Arzttermin, Familienfeier"
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    />
                                    <InputError message={errors.note} className="mt-2" />
                                </div>

                                <div className="flex justify-end">
                                    <PrimaryButton disabled={processing}>
                                        Wunschfrei eintragen
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    )}

                    {canCreateOwn && (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="border-b border-gray-200 p-4 sm:p-6">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Meine Wunschfrei-Tage
                                </h3>
                            </div>

                            <div className="p-4 sm:p-6">
                                {myWishes.length === 0 ? (
                                    <p className="text-sm text-gray-600">
                                        Du hast noch keine Wunschfrei-Tage eingetragen.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {myWishes.map((wish) => (
                                            <li
                                                key={wish.id}
                                                className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                            >
                                                <div className="min-w-0">
                                                    <p className="font-medium text-gray-900">
                                                        {formatDate(wish.date)}
                                                        {wish.kind === 'wish_shift' && (
                                                            <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
                                                                {wish.kindLabel}
                                                                {wish.shiftTemplateName
                                                                    ? `: ${wish.shiftTemplateName}`
                                                                    : ''}
                                                            </span>
                                                        )}
                                                    </p>
                                                    {wish.note && (
                                                        <p className="mt-0.5 text-sm text-gray-600">
                                                            {wish.note}
                                                        </p>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => deleteWish(wish)}
                                                    className="shrink-0 rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                                >
                                                    Löschen
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    )}

                    {isManager && teamWishes && (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="border-b border-gray-200 p-4 sm:p-6">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Wunschfrei im Team
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    Übersicht aller Wunschfrei-Tage deiner Wohnbereiche. Die
                                    automatische Planung berücksichtigt sie als weiches Ziel.
                                </p>
                            </div>

                            <div className="p-4 sm:p-6">
                                {teamWishes.length === 0 ? (
                                    <p className="text-sm text-gray-600">
                                        Es sind noch keine Wunschfrei-Tage eingetragen.
                                    </p>
                                ) : (
                                    <>
                                        <div className="hidden overflow-x-auto md:block">
                                            <table className="min-w-full divide-y divide-gray-200">
                                                <thead>
                                                    <tr>
                                                        <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                            Datum
                                                        </th>
                                                        <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                            Mitarbeiter
                                                        </th>
                                                        <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                            Wohnbereich
                                                        </th>
                                                        <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                            Notiz
                                                        </th>
                                                        <th className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                            Aktion
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-200">
                                                    {teamWishes.map((wish) => (
                                                        <tr key={wish.id}>
                                                            <td className="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900">
                                                                {formatDate(wish.date)}
                                                                {wish.kind === 'wish_shift' && (
                                                                    <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
                                                                        {wish.kindLabel}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                                {wish.employeeName ?? 'Unbekannt'}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-700">
                                                                {wish.locationName ?? 'Unbekannt'}
                                                            </td>
                                                            <td className="max-w-md px-3 py-4 text-sm text-gray-700">
                                                                {wish.note ?? '–'}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-4 text-right text-sm">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => deleteWish(wish)}
                                                                    className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                                                >
                                                                    Löschen
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>

                                        <ul className="divide-y divide-gray-200 md:hidden">
                                            {teamWishes.map((wish) => (
                                                <li key={wish.id} className="space-y-2 py-4">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <p className="font-medium text-gray-900">
                                                            {formatDate(wish.date)}
                                                        </p>
                                                        {wish.kind === 'wish_shift' && (
                                                            <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
                                                                {wish.kindLabel}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                                        <div>
                                                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                                Mitarbeiter
                                                            </dt>
                                                            <dd className="text-gray-700">
                                                                {wish.employeeName ?? 'Unbekannt'}
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                                Wohnbereich
                                                            </dt>
                                                            <dd className="text-gray-700">
                                                                {wish.locationName ?? 'Unbekannt'}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                    {wish.note && (
                                                        <p className="text-sm text-gray-700">
                                                            {wish.note}
                                                        </p>
                                                    )}
                                                    <div className="pt-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteWish(wish)}
                                                            className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                                        >
                                                            Löschen
                                                        </button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

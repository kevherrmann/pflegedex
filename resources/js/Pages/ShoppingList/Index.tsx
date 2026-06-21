import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type ShoppingItem = {
    id: string;
    name: string;
    quantity: number;
    creatorName: string | null;
    createdAt: string | null;
};

type Props = {
    items: ShoppingItem[];
};

export default function Index({ items }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        quantity: '1',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('shopping-list.store'), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'quantity'),
        });
    };

    const removeItem = (item: ShoppingItem) => {
        router.delete(route('shopping-list.destroy', item.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Organisation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Einkaufsliste
                    </h2>
                </div>
            }
        >
            <Head title="Einkaufsliste" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {/* Neuer Eintrag */}
                    <section className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                        <h3 className="text-lg font-semibold text-[#333333]">Was wird benötigt?</h3>
                        <p className="mt-1 text-sm text-[#54595F]">
                            Dein Name wird automatisch beim Eintrag vermerkt.
                        </p>

                        <form
                            onSubmit={submit}
                            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start"
                        >
                            <div className="flex-1">
                                <InputLabel htmlFor="name" value="Artikel" className="sr-only" />
                                <TextInput
                                    id="name"
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    placeholder="z. B. Einmalhandschuhe (Gr. M)"
                                    className="block w-full"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>
                            <div className="w-full sm:w-28">
                                <InputLabel htmlFor="quantity" value="Anzahl" className="sr-only" />
                                <TextInput
                                    id="quantity"
                                    type="number"
                                    min="1"
                                    max="9999"
                                    value={data.quantity}
                                    onChange={(event) => setData('quantity', event.target.value)}
                                    className="block w-full"
                                    aria-label="Anzahl"
                                    required
                                />
                                <InputError message={errors.quantity} className="mt-2" />
                            </div>
                            <PrimaryButton
                                disabled={processing}
                                className="h-[42px] justify-center sm:px-6"
                            >
                                Hinzufügen
                            </PrimaryButton>
                        </form>
                    </section>

                    {/* Liste */}
                    <section className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="flex items-baseline justify-between border-b border-[#E5E7EB] px-5 py-4">
                            <h3 className="text-base font-semibold text-[#333333]">
                                Aktuelle Liste
                            </h3>
                            <span className="text-sm text-[#54595F]">
                                {items.length} {items.length === 1 ? 'Eintrag' : 'Einträge'}
                            </span>
                        </div>

                        {items.length === 0 ? (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Die Liste ist leer
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Trage oben den ersten Artikel ein.
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-[#E5E7EB]">
                                {items.map((item) => (
                                    <li key={item.id} className="flex items-center gap-3 px-5 py-4">
                                        <span className="flex h-9 min-w-9 shrink-0 items-center justify-center rounded-full bg-[#F7E8ED] px-2 text-sm font-bold text-[#7F1730]">
                                            {item.quantity}×
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-semibold text-[#333333]">
                                                {item.name}
                                            </p>
                                            <p className="mt-0.5 truncate text-xs text-[#54595F]">
                                                {item.creatorName ?? 'Unbekannt'}
                                                {item.createdAt ? ` · ${item.createdAt}` : ''}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => removeItem(item)}
                                            aria-label={`„${item.name}" löschen`}
                                            title="Gekauft / löschen"
                                            className="shrink-0 rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                        >
                                            <svg
                                                className="h-5 w-5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth={1.8}
                                                stroke="currentColor"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                />
                                            </svg>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

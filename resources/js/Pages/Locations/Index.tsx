import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Location = {
    id: number;
    name: string;
    shortName: string | null;
    description: string | null;
    userHasAccess: boolean;
};

type LocationsIndexProps = {
    locations: Location[];
};

export default function Index({ locations }: LocationsIndexProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        short_name: '',
        description: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('locations.store'), {
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Struktur
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Wohnbereiche
                    </h2>
                </div>
            }
        >
            <Head title="Wohnbereiche" />

            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[1fr_420px] lg:px-8">
                    <section className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Verwaltung
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold text-[#333333]">
                                Angelegte Wohnbereiche
                            </h1>
                            <p className="mt-3 text-[#54595F]">
                                Hier kannst du Wohnbereiche anlegen. Neu angelegte Wohnbereiche werden direkt deinem Konto zugeordnet, damit du Bewohner darin erfassen kannst.
                            </p>
                        </div>

                        {locations.length > 0 ? (
                            <div className="divide-y divide-[#E5E7EB]">
                                {locations.map((location) => (
                                    <article
                                        key={location.id}
                                        className="flex flex-col gap-3 px-6 py-5 sm:flex-row sm:items-start sm:justify-between"
                                    >
                                        <div>
                                            <h3 className="text-lg font-semibold text-[#333333]">
                                                {location.name}
                                            </h3>
                                            <p className="mt-1 text-sm text-[#54595F]">
                                                Kürzel:{' '}
                                                {location.shortName ?? '—'}
                                            </p>
                                            {location.description && (
                                                <p className="mt-2 text-sm leading-6 text-[#54595F]">
                                                    {location.description}
                                                </p>
                                            )}
                                        </div>
                                        <span
                                            className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold ${
                                                location.userHasAccess
                                                    ? 'bg-[#F7E8ED] text-[#7F1730]'
                                                    : 'bg-gray-100 text-gray-600'
                                            }`}
                                        >
                                            {location.userHasAccess
                                                ? 'Dir zugeordnet'
                                                : 'Nicht zugeordnet'}
                                        </span>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine Wohnbereiche vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Lege rechts den ersten Wohnbereich an.
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="h-fit rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                        <h3 className="text-xl font-semibold text-[#333333]">
                            Wohnbereich anlegen
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-[#54595F]">
                            Nutze sprechende Namen wie „Wohnbereich A“, „EG Demenz“ oder „Kurzzeitpflege“.
                        </p>

                        <form onSubmit={submit} className="mt-6 space-y-5">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput
                                    id="name"
                                    name="name"
                                    value={data.name}
                                    className="mt-1 block w-full"
                                    isFocused={true}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    required
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="short_name" value="Kürzel" />
                                <TextInput
                                    id="short_name"
                                    name="short_name"
                                    value={data.short_name}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('short_name', event.target.value)
                                    }
                                />
                                <InputError
                                    message={errors.short_name}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="description"
                                    value="Beschreibung"
                                />
                                <textarea
                                    id="description"
                                    name="description"
                                    value={data.description}
                                    className="mt-1 block min-h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    onChange={(event) =>
                                        setData('description', event.target.value)
                                    }
                                />
                                <InputError
                                    message={errors.description}
                                    className="mt-2"
                                />
                            </div>

                            <PrimaryButton disabled={processing}>
                                Wohnbereich speichern
                            </PrimaryButton>
                        </form>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

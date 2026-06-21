import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Location = { id: string; name: string; shortName: string | null; description: string | null };
type LocationsEditProps = { location: Location };

export default function Edit({ location }: LocationsEditProps) {
    const { data, setData, patch, processing, errors } = useForm({
        name: location.name,
        short_name: location.shortName ?? '',
        description: location.description ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('locations.update', location.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                    Wohnbereich bearbeiten
                </h2>
            }
        >
            <Head title="Wohnbereich bearbeiten" />
            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <form
                        onSubmit={submit}
                        className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8"
                    >
                        <div className="space-y-5">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput
                                    id="name"
                                    name="name"
                                    value={data.name}
                                    className="mt-1 block w-full"
                                    isFocused={true}
                                    onChange={(e) => setData('name', e.target.value)}
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
                                    onChange={(e) => setData('short_name', e.target.value)}
                                />
                                <InputError message={errors.short_name} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="description" value="Beschreibung" />
                                <textarea
                                    id="description"
                                    name="description"
                                    value={data.description}
                                    className="mt-1 block min-h-28 w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    onChange={(e) => setData('description', e.target.value)}
                                />
                                <InputError message={errors.description} className="mt-2" />
                            </div>
                        </div>
                        <div className="mt-8 flex justify-end gap-3">
                            <Link
                                href={route('locations.index')}
                                className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline"
                            >
                                Abbrechen
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Wohnbereich aktualisieren
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

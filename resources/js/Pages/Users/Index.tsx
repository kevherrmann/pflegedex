import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type PdlUser = {
    id: string;
    name: string;
    email: string;
};

type UsersIndexProps = {
    pdlUsers: PdlUser[];
};

export default function Index({ pdlUsers }: UsersIndexProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('users.pdl.store'), {
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Administration
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        PDL-Konten
                    </h2>
                </div>
            }
        >
            <Head title="PDL-Konten" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[1fr_420px] lg:px-8">
                    <section className="rounded-2xl bg-white shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="border-b border-[#E5E7EB] px-6 py-5">
                            <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                                Rollenverteilung
                            </p>
                            <h1 className="mt-2 text-2xl font-semibold text-[#333333] sm:text-3xl">
                                Pflegedienstleitungen
                            </h1>
                            <p className="mt-3 text-[#54595F]">
                                Admins legen ausschließlich PDL-Konten an. PDLs verwalten danach
                                Wohnbereiche und Bewohner.
                            </p>
                        </div>

                        {pdlUsers.length > 0 ? (
                            <div className="divide-y divide-[#E5E7EB]">
                                {pdlUsers.map((user) => (
                                    <article
                                        key={user.id}
                                        className="flex flex-col gap-3 px-6 py-5 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <h3 className="text-lg font-semibold text-[#333333]">
                                                {user.name}
                                            </h3>
                                            <p className="mt-1 text-sm text-[#54595F]">
                                                {user.email}
                                            </p>
                                        </div>
                                        <Link
                                            href={route('users.edit', user.id)}
                                            className="text-sm font-semibold text-[#9B1C3B] hover:underline"
                                        >
                                            Bearbeiten
                                        </Link>
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center">
                                <p className="text-lg font-semibold text-[#333333]">
                                    Noch keine PDL-Konten vorhanden
                                </p>
                                <p className="mt-2 text-[#54595F]">
                                    Lege rechts das erste PDL-Konto an.
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="h-fit rounded-2xl bg-white p-4 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6">
                        <h3 className="text-xl font-semibold text-[#333333]">PDL-Konto anlegen</h3>
                        <p className="mt-2 text-sm leading-6 text-[#54595F]">
                            Dieses Konto kann anschließend Wohnbereiche und Bewohner verwalten.
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
                                    onChange={(event) => setData('name', event.target.value)}
                                    required
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="email" value="E-Mail" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="mt-1 block w-full"
                                    onChange={(event) => setData('email', event.target.value)}
                                    required
                                />
                                <InputError message={errors.email} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="password" value="Startpasswort" />
                                <TextInput
                                    id="password"
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    className="mt-1 block w-full"
                                    onChange={(event) => setData('password', event.target.value)}
                                    required
                                />
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            <PrimaryButton disabled={processing}>PDL speichern</PrimaryButton>
                        </form>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

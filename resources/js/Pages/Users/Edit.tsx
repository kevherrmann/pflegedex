import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type PdlUser = { id: string; name: string; email: string };

type UsersEditProps = { pdlUser: PdlUser };

export default function Edit({ pdlUser }: UsersEditProps) {
    const { data, setData, patch, processing, errors } = useForm({
        name: pdlUser.name,
        email: pdlUser.email,
        password: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('users.update', pdlUser.id));
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-[#333333]">PDL-Konto bearbeiten</h2>}
        >
            <Head title="PDL-Konto bearbeiten" />
            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <div className="space-y-5">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput id="name" name="name" value={data.name} className="mt-1 block w-full" isFocused={true} onChange={(e) => setData('name', e.target.value)} required />
                                <InputError message={errors.name} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="email" value="E-Mail" />
                                <TextInput id="email" type="email" name="email" value={data.email} className="mt-1 block w-full" onChange={(e) => setData('email', e.target.value)} required />
                                <InputError message={errors.email} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="password" value="Neues Passwort optional" />
                                <TextInput id="password" type="password" name="password" value={data.password} className="mt-1 block w-full" onChange={(e) => setData('password', e.target.value)} />
                                <InputError message={errors.password} className="mt-2" />
                            </div>
                        </div>
                        <div className="mt-8 flex justify-end gap-3">
                            <Link href={route('users.index')} className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] hover:underline">Abbrechen</Link>
                            <PrimaryButton disabled={processing}>PDL aktualisieren</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

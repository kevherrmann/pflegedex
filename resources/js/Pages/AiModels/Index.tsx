import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type AiModelItem = {
    id: string;
    label: string;
    provider: string;
    providerLabel: string;
    model: string;
    baseUrl: string | null;
    hasApiKey: boolean;
    isActive: boolean;
    isDefault: boolean;
};

type ProviderOption = { value: string; label: string };

type Health = {
    available: boolean;
    modelPresent: boolean;
    model: string;
    reason: string | null;
};

type Props = {
    models: AiModelItem[];
    providers: ProviderOption[];
    health: Health;
};

function ModelCard({ model }: { model: AiModelItem }) {
    const activate = () => {
        router.patch(route('ai-models.activate', model.id), {}, { preserveScroll: true });
    };

    const test = () => {
        router.post(route('ai-models.test', model.id), {}, { preserveScroll: true });
    };

    const remove = () => {
        if (!window.confirm('Dieses Modell wirklich löschen?')) {
            return;
        }
        router.delete(route('ai-models.destroy', model.id), { preserveScroll: true });
    };

    return (
        <div
            className={
                'rounded-2xl bg-white p-4 shadow-sm ring-1 sm:p-5 ' +
                (model.isActive ? 'ring-2 ring-[#9B1C3B]' : 'ring-gray-200')
            }
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-semibold text-gray-900">{model.label}</h3>
                        {model.isActive && (
                            <span className="rounded-full bg-[#9B1C3B] px-2.5 py-0.5 text-xs font-semibold text-white">
                                Aktiv
                            </span>
                        )}
                        {model.isDefault && (
                            <span className="rounded-full bg-[#F7E8ED] px-2.5 py-0.5 text-xs font-semibold text-[#7F1730]">
                                Standard
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-gray-600">{model.providerLabel}</p>
                    <dl className="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Modell
                            </dt>
                            <dd className="font-mono text-gray-800">{model.model}</dd>
                        </div>
                        {model.provider === 'openai' && (
                            <>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        API-URL
                                    </dt>
                                    <dd className="break-all text-gray-700">
                                        {model.baseUrl ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        API-Key
                                    </dt>
                                    <dd className="text-gray-700">
                                        {model.hasApiKey ? '•••••••• (gesetzt)' : 'nicht gesetzt'}
                                    </dd>
                                </div>
                            </>
                        )}
                    </dl>
                </div>

                <div className="flex flex-wrap gap-2">
                    {!model.isActive && (
                        <button
                            type="button"
                            onClick={activate}
                            className="rounded-md border border-transparent bg-[#9B1C3B] px-3 py-2 text-sm font-semibold text-white transition hover:bg-[#7F1730]"
                        >
                            Aktivieren
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={test}
                        className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Verbindung testen
                    </button>
                    {!model.isDefault && (
                        <button
                            type="button"
                            onClick={remove}
                            className="rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                        >
                            Löschen
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function AiModelsIndex({ models, providers, health }: Props) {
    const pageErrors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash ?? {}) as { status?: string };

    const form = useForm({
        label: '',
        provider: 'openai',
        model: '',
        base_url: 'https://api.deepseek.com',
        api_key: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('ai-models.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const isExternal = form.data.provider === 'openai';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">KI-Modelle</h2>
            }
        >
            <Head title="KI-Modelle" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {flash.status === 'ai-model-test-ok' && (
                        <div className="rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                            Verbindung erfolgreich – das Modell ist erreichbar.
                        </div>
                    )}
                    {pageErrors.ai_model_test && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                            {pageErrors.ai_model_test}
                        </div>
                    )}
                    {pageErrors.ai_model && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                            {pageErrors.ai_model}
                        </div>
                    )}

                    <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:p-6">
                        <h3 className="text-lg font-semibold text-gray-900">Aktives Modell</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Generator und SIS/Maßnahmenplan nutzen das aktive Modell.{' '}
                            <span className="font-medium">Gemma (lokal)</span> ist der Standard und
                            bleibt immer vorhanden. Für schwächere Hardware kannst du ein externes,
                            OpenAI-kompatibles Modell (z. B. DeepSeek) per API-Key anbinden und
                            aktivieren.
                        </p>
                        <p className="mt-3 text-sm">
                            <span
                                className={
                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' +
                                    (health.available && health.modelPresent
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-amber-100 text-amber-800')
                                }
                            >
                                {health.available && health.modelPresent
                                    ? `Bereit: ${health.model}`
                                    : `Nicht bereit: ${health.model}`}
                            </span>
                            {health.reason && (
                                <span className="ml-2 text-gray-500">{health.reason}</span>
                            )}
                        </p>
                    </div>

                    <div className="space-y-3">
                        {models.map((model) => (
                            <ModelCard key={model.id} model={model} />
                        ))}
                    </div>

                    <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="border-b border-gray-200 p-4 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Modell hinzufügen
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Für ein externes Modell brauchst du die API-URL und einen API-Key.
                                Der Key wird verschlüsselt gespeichert und nie wieder angezeigt.
                            </p>
                        </div>

                        <form onSubmit={submit} className="space-y-4 p-4 sm:p-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="provider" value="Anbieter" />
                                    <select
                                        id="provider"
                                        value={form.data.provider}
                                        onChange={(event) =>
                                            form.setData('provider', event.target.value)
                                        }
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    >
                                        {providers.map((provider) => (
                                            <option key={provider.value} value={provider.value}>
                                                {provider.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={form.errors.provider} className="mt-2" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="label" value="Bezeichnung" />
                                    <TextInput
                                        id="label"
                                        value={form.data.label}
                                        onChange={(event) =>
                                            form.setData('label', event.target.value)
                                        }
                                        placeholder="z. B. DeepSeek"
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={form.errors.label} className="mt-2" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="model" value="Modell-ID" />
                                    <TextInput
                                        id="model"
                                        value={form.data.model}
                                        onChange={(event) =>
                                            form.setData('model', event.target.value)
                                        }
                                        placeholder={
                                            isExternal ? 'z. B. deepseek-chat' : 'z. B. gemma4:e2b'
                                        }
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={form.errors.model} className="mt-2" />
                                </div>
                                {isExternal && (
                                    <div>
                                        <InputLabel htmlFor="base_url" value="API-URL" />
                                        <TextInput
                                            id="base_url"
                                            value={form.data.base_url}
                                            onChange={(event) =>
                                                form.setData('base_url', event.target.value)
                                            }
                                            placeholder="https://api.deepseek.com"
                                            className="mt-1 block w-full"
                                        />
                                        <InputError
                                            message={form.errors.base_url}
                                            className="mt-2"
                                        />
                                    </div>
                                )}
                            </div>

                            {isExternal && (
                                <div>
                                    <InputLabel htmlFor="api_key" value="API-Key" />
                                    <TextInput
                                        id="api_key"
                                        type="password"
                                        value={form.data.api_key}
                                        onChange={(event) =>
                                            form.setData('api_key', event.target.value)
                                        }
                                        autoComplete="new-password"
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={form.errors.api_key} className="mt-2" />
                                </div>
                            )}

                            <div className="flex justify-end">
                                <PrimaryButton disabled={form.processing}>
                                    Modell hinzufügen
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

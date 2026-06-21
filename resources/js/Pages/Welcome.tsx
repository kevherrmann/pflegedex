import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Branding = {
    name: string;
    primaryColor: string;
    primaryDark: string;
    primarySoft: string;
    neutralText: string;
};

type WelcomeProps = PageProps<{
    branding: Branding;
    canLogin: boolean;
    canRegister: boolean;
    laravelVersion: string;
    phpVersion: string;
}>;

const modules = [
    {
        title: 'Bewohnerdokumentation',
        description:
            'Stammdaten, Wohnbereiche und Pflegeberichte als ruhiger digitaler Arbeitsplatz für Pflegekräfte.',
    },
    {
        title: 'SIS & Risiken',
        description:
            'Strukturierte Informationssammlung mit Themenfeldern und Risikomatrix nach dem Projektbriefing.',
    },
    {
        title: 'KI-Entwürfe lokal',
        description:
            'Berichtsentwürfe aus Stichworten oder Sprache, ohne dass Pflegedaten den Heimserver verlassen.',
    },
];

export default function Welcome({
    auth,
    branding,
    canLogin,
    canRegister,
    laravelVersion,
    phpVersion,
}: WelcomeProps) {
    return (
        <>
            <Head title="Pflegedex" />
            <div className="min-h-screen bg-[#F8F8F8] text-[#333333]">
                <header className="border-b border-[#E5E7EB] bg-white/95 shadow-sm">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-5 lg:px-8">
                        <div className="flex items-center gap-3">
                            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7E8ED]">
                                <div className="grid gap-1">
                                    <span className="block h-2 w-8 rounded-full bg-[#9B1C3B]" />
                                    <span className="block h-2 w-6 rounded-full bg-[#C46A80]" />
                                    <span className="block h-2 w-4 rounded-full bg-[#E0A8B6]" />
                                </div>
                            </div>
                            <div>
                                <p className="text-xl font-semibold tracking-tight text-[#333333]">
                                    {branding.name}
                                </p>
                                <p className="text-xs font-medium uppercase tracking-[0.28em] text-[#6B7280]">
                                    Pflege-App MVP
                                </p>
                            </div>
                        </div>

                        <nav className="flex items-center gap-2 text-sm font-semibold uppercase tracking-[0.16em]">
                            <a className="hidden text-[#9B1C3B] md:inline" href="#module">
                                Module
                            </a>
                            <a
                                className="hidden text-[#54595F] transition hover:text-[#9B1C3B] md:inline"
                                href="#betrieb"
                            >
                                Betrieb
                            </a>
                            {auth.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-md bg-[#9B1C3B] px-5 py-3 text-white shadow-sm transition hover:bg-[#7F1730]"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    {canLogin && (
                                        <Link
                                            href={route('login')}
                                            className="rounded-md px-4 py-3 text-[#54595F] transition hover:text-[#9B1C3B]"
                                        >
                                            Login
                                        </Link>
                                    )}
                                    {canRegister && (
                                        <Link
                                            href={route('register')}
                                            className="rounded-md bg-[#9B1C3B] px-5 py-3 text-white shadow-sm transition hover:bg-[#7F1730]"
                                        >
                                            Zugang anlegen
                                        </Link>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main>
                    <section className="relative overflow-hidden bg-white">
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,#F7E8ED,transparent_35%),linear-gradient(135deg,rgba(155,28,59,0.08),transparent_45%)]" />
                        <div className="relative mx-auto grid max-w-7xl gap-10 px-6 py-16 lg:grid-cols-[1.15fr_0.85fr] lg:px-8 lg:py-24">
                            <div className="flex flex-col justify-center">
                                <p className="mb-4 text-sm font-bold uppercase tracking-[0.28em] text-[#9B1C3B]">
                                    On-Premise Pflegedokumentation
                                </p>
                                <h1 className="max-w-4xl text-4xl font-semibold tracking-tight text-[#333333] md:text-6xl">
                                    Pflegedex bringt SIS, Pflegeberichte und lokale KI in einen
                                    ruhigen Arbeitsplatz.
                                </h1>
                                <p className="mt-6 max-w-2xl text-lg leading-8 text-[#54595F]">
                                    Das MVP startet lokal in Docker und bleibt später per
                                    Konfiguration auf die Intranet-Domain im Heim umziehbar.
                                    Datenschutz, append-only Berichte und manuelle Signatur stehen
                                    von Anfang an im Fokus.
                                </p>
                                <div className="mt-10 grid gap-4 sm:grid-cols-2">
                                    <Link
                                        href={auth.user ? route('dashboard') : route('login')}
                                        className="rounded-md bg-[#9B1C3B] px-6 py-4 text-center text-sm font-bold uppercase tracking-[0.18em] text-white shadow-lg shadow-[#9B1C3B]/20 transition hover:bg-[#7F1730]"
                                    >
                                        App öffnen
                                    </Link>
                                    <a
                                        href="#betrieb"
                                        className="rounded-md border border-[#9B1C3B]/20 bg-white px-6 py-4 text-center text-sm font-bold uppercase tracking-[0.18em] text-[#9B1C3B] shadow-sm transition hover:bg-[#F7E8ED]"
                                    >
                                        Betrieb ansehen
                                    </a>
                                </div>
                            </div>

                            <div className="rounded-3xl bg-[#9B1C3B] p-6 text-white shadow-2xl shadow-[#9B1C3B]/20">
                                <div className="rounded-2xl bg-white/10 p-6 ring-1 ring-white/20">
                                    <p className="text-sm font-semibold uppercase tracking-[0.24em] text-white/80">
                                        Pilot-Dashboard
                                    </p>
                                    <div className="mt-8 grid gap-4">
                                        {[
                                            ['Wohnbereiche', 'getrennte Bereiche'],
                                            ['SIS', '6 Themenfelder + Risiken'],
                                            ['KI', 'Ollama lokal angebunden'],
                                            ['Berichte', 'Entwurf → Prüfung → Signatur'],
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="rounded-2xl bg-white p-5 text-[#333333] shadow-sm"
                                            >
                                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9B1C3B]">
                                                    {label}
                                                </p>
                                                <p className="mt-2 text-lg font-semibold">
                                                    {value}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="module" className="mx-auto max-w-7xl px-6 py-14 lg:px-8">
                        <div className="grid gap-6 lg:grid-cols-3">
                            {modules.map((module) => (
                                <article
                                    key={module.title}
                                    className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]"
                                >
                                    <div className="mb-6 h-2 w-16 rounded-full bg-[#9B1C3B]" />
                                    <h2 className="text-xl font-semibold text-[#333333]">
                                        {module.title}
                                    </h2>
                                    <p className="mt-4 leading-7 text-[#54595F]">
                                        {module.description}
                                    </p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section id="betrieb" className="border-y border-[#E5E7EB] bg-white">
                        <div className="mx-auto grid max-w-7xl gap-8 px-6 py-12 lg:grid-cols-4 lg:px-8">
                            {[
                                ['Lokal zuerst', 'http://localhost:8080'],
                                ['Datenbank', 'PostgreSQL 16'],
                                ['Queue/Cache', 'Redis'],
                                ['KI-Endpunkt', 'host.docker.internal:11434'],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <p className="text-sm font-bold uppercase tracking-[0.2em] text-[#9B1C3B]">
                                        {label}
                                    </p>
                                    <p className="mt-3 text-lg font-semibold text-[#333333]">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                </main>

                <footer className="mx-auto flex max-w-7xl flex-col gap-2 px-6 py-8 text-sm text-[#6B7280] lg:px-8">
                    <p>
                        Laravel {laravelVersion} · PHP {phpVersion}
                    </p>
                    <p>
                        Designbasis: Sander Pflege — Bordeaux, Weißraum, klare Karten und große
                        Aktionsflächen.
                    </p>
                </footer>
            </div>
        </>
    );
}

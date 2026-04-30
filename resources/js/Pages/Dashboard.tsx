import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const cards = [
    ['Wohnbereiche', '1', 'Ein erster Beispiel-Wohnbereich wird beim Seed angelegt.'],
    ['Rollen', '3', 'Admin, PDL und Pflegekraft sind als Grundrollen vorbereitet.'],
    ['Bewohner', '0', 'Patientendokumentation wird in Phase 1 aufgebaut.'],
    ['SIS', '0', 'Strukturierte Informationssammlung mit Risikomatrix.'],
    ['Pflegeberichte', '0', 'Berichtserfassung und Signatur folgen nach dem Fundament.'],
    ['KI-Entwürfe', '0', 'Lokale Ollama-Generierung folgt in Phase 2.'],
];

export default function Dashboard() {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Pflegedex
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Dashboard
                    </h2>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="bg-[#F8F8F8] py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-[#E5E7EB]">
                        <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                            Lokaler Pilotbetrieb
                        </p>
                        <h1 className="mt-3 text-3xl font-semibold text-[#333333]">
                            Willkommen im Pflegedex-Arbeitsbereich
                        </h1>
                        <p className="mt-4 max-w-3xl leading-7 text-[#54595F]">
                            Das technische Fundament steht: Anmeldung, Rollen und Wohnbereiche sind vorbereitet. Die nächsten Schritte füllen diese Grundlage mit Bewohnerdokumentation, SIS und Pflegeberichten.
                        </p>
                    </div>

                    <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        {cards.map(([title, value, description]) => (
                            <article key={title} className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-[#E5E7EB]">
                                <p className="text-sm font-bold uppercase tracking-[0.18em] text-[#9B1C3B]">
                                    {title}
                                </p>
                                <p className="mt-5 text-5xl font-semibold text-[#9B1C3B]">{value}</p>
                                <p className="mt-4 leading-7 text-[#54595F]">{description}</p>
                            </article>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

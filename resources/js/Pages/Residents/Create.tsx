import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Location = {
    id: string;
    name: string;
};

type SalutationOption = {
    value: string;
    label: string;
};

type ResidentCreateProps = {
    location: Location | null;
    locations: Location[];
    salutations: SalutationOption[];
};

export default function Create({ location, locations, salutations }: ResidentCreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        location_id: location?.id ? String(location.id) : '',
        salutation: '',
        first_name: '',
        last_name: '',
        birth_date: '',
        room_number: '',
        care_level: '',
        admitted_on: '',
        health_insurance: '',
        insurance_number: '',
        family_doctor: '',
        family_doctor_phone: '',
        guardian_name: '',
        guardian_phone: '',
        has_living_will: false as boolean,
        has_healthcare_proxy: false as boolean,
        allergies: '',
        diagnoses: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('residents.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                        Bewohnerdokumentation
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-[#333333]">
                        Bewohner anlegen
                    </h2>
                </div>
            }
        >
            <Head title="Bewohner anlegen" />

            <div className="bg-[#F8F8F8] py-6 sm:py-8 lg:py-12">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <section className="mb-8 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8">
                        <p className="text-sm font-bold uppercase tracking-[0.22em] text-[#9B1C3B]">
                            {location?.name ?? 'Noch kein Wohnbereich'}
                        </p>
                        <h1 className="mt-3 text-2xl font-semibold text-[#333333] sm:text-3xl">
                            Neuer Bewohner
                        </h1>
                        <p className="mt-4 leading-7 text-[#54595F]">
                            Lege hier die Stammdaten für deinen Wohnbereich an. Der Bewohner wird
                            automatisch deinem zugeordneten Wohnbereich zugewiesen.
                        </p>
                    </section>

                    {!location && (
                        <div className="mb-6 rounded-2xl border border-[#F3D1DC] bg-[#F7E8ED] p-5 text-[#7F1730]">
                            Bitte ordne deinem Konto zuerst einen Wohnbereich zu, bevor du Bewohner
                            anlegst.
                        </div>
                    )}

                    <form
                        onSubmit={submit}
                        className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-[#E5E7EB] sm:p-6 lg:p-8"
                    >
                        {locations.length > 1 && (
                            <div className="mb-6">
                                <InputLabel htmlFor="location_id" value="Wohnbereich" />
                                <select
                                    id="location_id"
                                    name="location_id"
                                    value={data.location_id}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    onChange={(event) => setData('location_id', event.target.value)}
                                    required
                                >
                                    {locations.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.location_id} className="mt-2" />
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="salutation" value="Anrede" />
                            <select
                                id="salutation"
                                name="salutation"
                                value={data.salutation}
                                onChange={(event) => setData('salutation', event.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                required
                            >
                                <option value="">Bitte auswählen …</option>
                                {salutations.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.salutation} className="mt-2" />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="first_name" value="Vorname" />
                                <TextInput
                                    id="first_name"
                                    name="first_name"
                                    value={data.first_name}
                                    className="mt-1 block w-full"
                                    autoComplete="given-name"
                                    isFocused={true}
                                    onChange={(event) => setData('first_name', event.target.value)}
                                    required
                                />
                                <InputError message={errors.first_name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="last_name" value="Nachname" />
                                <TextInput
                                    id="last_name"
                                    name="last_name"
                                    value={data.last_name}
                                    className="mt-1 block w-full"
                                    autoComplete="family-name"
                                    onChange={(event) => setData('last_name', event.target.value)}
                                    required
                                />
                                <InputError message={errors.last_name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="birth_date" value="Geburtsdatum" />
                                <TextInput
                                    id="birth_date"
                                    type="date"
                                    name="birth_date"
                                    value={data.birth_date}
                                    className="mt-1 block w-full"
                                    onChange={(event) => setData('birth_date', event.target.value)}
                                />
                                <InputError message={errors.birth_date} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="room_number" value="Zimmer" />
                                <TextInput
                                    id="room_number"
                                    name="room_number"
                                    value={data.room_number}
                                    className="mt-1 block w-full"
                                    onChange={(event) => setData('room_number', event.target.value)}
                                />
                                <InputError message={errors.room_number} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="care_level" value="Pflegegrad" />
                                <select
                                    id="care_level"
                                    name="care_level"
                                    value={data.care_level}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    onChange={(event) => setData('care_level', event.target.value)}
                                >
                                    <option value="">Noch nicht gesetzt</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                </select>
                                <InputError message={errors.care_level} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="admitted_on" value="Aufnahmedatum" />
                                <TextInput
                                    id="admitted_on"
                                    type="date"
                                    value={data.admitted_on}
                                    className="mt-1 block w-full"
                                    onChange={(event) => setData('admitted_on', event.target.value)}
                                />
                                <InputError message={errors.admitted_on} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="health_insurance"
                                    value="Pflegekasse / Kostenträger"
                                />
                                <TextInput
                                    id="health_insurance"
                                    value={data.health_insurance}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('health_insurance', event.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="insurance_number" value="Versichertennummer" />
                                <TextInput
                                    id="insurance_number"
                                    value={data.insurance_number}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('insurance_number', event.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="family_doctor" value="Hausarzt" />
                                <TextInput
                                    id="family_doctor"
                                    value={data.family_doctor}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('family_doctor', event.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="family_doctor_phone"
                                    value="Hausarzt – Telefon"
                                />
                                <TextInput
                                    id="family_doctor_phone"
                                    value={data.family_doctor_phone}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('family_doctor_phone', event.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="guardian_name"
                                    value="Betreuer / Bevollmächtigter"
                                />
                                <TextInput
                                    id="guardian_name"
                                    value={data.guardian_name}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('guardian_name', event.target.value)
                                    }
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="guardian_phone" value="Betreuer – Telefon" />
                                <TextInput
                                    id="guardian_phone"
                                    value={data.guardian_phone}
                                    className="mt-1 block w-full"
                                    onChange={(event) =>
                                        setData('guardian_phone', event.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <div className="mt-6 flex flex-wrap gap-6">
                            <label className="flex items-center gap-2 text-sm text-[#333333]">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    checked={data.has_living_will}
                                    onChange={(event) =>
                                        setData('has_living_will', event.target.checked)
                                    }
                                />
                                Patientenverfügung vorhanden
                            </label>
                            <label className="flex items-center gap-2 text-sm text-[#333333]">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    checked={data.has_healthcare_proxy}
                                    onChange={(event) =>
                                        setData('has_healthcare_proxy', event.target.checked)
                                    }
                                />
                                Vorsorgevollmacht vorhanden
                            </label>
                        </div>

                        <div className="mt-6 grid gap-6 sm:grid-cols-2">
                            <div>
                                <InputLabel
                                    htmlFor="allergies"
                                    value="Allergien / Unverträglichkeiten"
                                />
                                <textarea
                                    id="allergies"
                                    rows={2}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    value={data.allergies}
                                    onChange={(event) => setData('allergies', event.target.value)}
                                />
                                <InputError message={errors.allergies} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel
                                    htmlFor="diagnoses"
                                    value="Diagnosen (ICD / Freitext)"
                                />
                                <textarea
                                    id="diagnoses"
                                    rows={2}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                                    value={data.diagnoses}
                                    onChange={(event) => setData('diagnoses', event.target.value)}
                                />
                                <InputError message={errors.diagnoses} className="mt-2" />
                            </div>
                        </div>

                        <div className="mt-8 flex items-center justify-end gap-3">
                            <Link
                                href={route('residents.index')}
                                className="rounded-md px-4 py-2 text-sm font-semibold text-[#54595F] underline-offset-4 hover:text-[#333333] hover:underline"
                            >
                                Abbrechen
                            </Link>
                            <PrimaryButton disabled={processing || !location}>
                                Bewohner speichern
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

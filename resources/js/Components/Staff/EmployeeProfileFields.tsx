import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

// Qualifikationsstufen spiegeln das PHP-Enum App\Enums\QualificationLevel.
const QUALIFICATION_OPTIONS: { value: string; label: string }[] = [
    { value: 'specialist', label: 'Pflegefachkraft' },
    { value: 'assistant', label: 'Pflegeassistent' },
    { value: 'aide', label: 'Pflegehilfskraft' },
];

// Rollen, die im Pflegebereich arbeiten (siehe StaffController::NURSING_ROLES).
const NURSING_ROLES = ['WBL', 'Pflegekraft'];

export type EmployeeProfileFormData = {
    role: string;
    is_nursing_specialist: boolean;
    qualification_level: string;
    weekly_hours: string | number;
    regular_work_days_per_week: string | number | null;
    annual_vacation_days: string | number;
    vacation_days_carried_over: string | number;
    overtime_minutes_balance: string | number;
    can_work_early: boolean;
    can_work_late: boolean;
    can_work_night: boolean;
    avoids_weekends: boolean;
    week_rotation: string;
    fixed_free_weekdays: number[];
    max_consecutive_days_override: string | number | null;
    scheduling_note: string;
    active: boolean;
};

// ISO-Wochentage (1=Mo … 7=So) für die "feste freie Tage"-Auswahl.
const WEEKDAYS: { iso: number; label: string }[] = [
    { iso: 1, label: 'Mo' },
    { iso: 2, label: 'Di' },
    { iso: 3, label: 'Mi' },
    { iso: 4, label: 'Do' },
    { iso: 5, label: 'Fr' },
    { iso: 6, label: 'Sa' },
    { iso: 7, label: 'So' },
];

type EmployeeProfileFieldErrors = Partial<Record<keyof EmployeeProfileFormData, string>>;

type Props<TForm extends EmployeeProfileFormData> = {
    data: TForm;
    setData: <TKey extends keyof TForm>(key: TKey, value: TForm[TKey]) => void;
    errors: EmployeeProfileFieldErrors;
};

function ShiftChip({
    label,
    checked,
    onToggle,
}: {
    label: string;
    checked: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-pressed={checked}
            className={`inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition ${
                checked
                    ? 'border-[#9B1C3B] bg-[#9B1C3B] text-white'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
            }`}
        >
            <span
                className={`inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px] ${
                    checked ? 'bg-white/20' : 'bg-gray-100 text-transparent'
                }`}
            >
                ✓
            </span>
            {label}
        </button>
    );
}

export default function EmployeeProfileFields<TForm extends EmployeeProfileFormData>({
    data,
    setData,
    errors,
}: Props<TForm>) {
    const isNursingRole = NURSING_ROLES.includes(data.role);
    const isWbl = data.role === 'WBL';

    return (
        <div className="space-y-6 rounded-2xl border border-gray-200 bg-white p-5">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Mitarbeiterprofil</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Diese Daten werden für Urlaub, Überstunden und Dienstplanung genutzt.
                </p>
            </div>

            {/* Qualifikation */}
            {isWbl ? (
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p className="text-sm font-medium text-gray-900">
                        Qualifikation: Pflegefachkraft
                    </p>
                    <p className="mt-0.5 text-sm text-gray-500">
                        Eine Wohnbereichsleitung ist immer examinierte Pflegefachkraft.
                    </p>
                </div>
            ) : isNursingRole ? (
                <div>
                    <InputLabel htmlFor="qualification_level" value="Qualifikationsstufe" />
                    <select
                        id="qualification_level"
                        value={data.qualification_level}
                        onChange={(event) => {
                            const level = event.target.value;

                            setData('qualification_level', level as TForm['qualification_level']);
                            // Fachkraft-Flag konsistent mitfuehren (steuert den Generator).
                            setData(
                                'is_nursing_specialist',
                                (level === 'specialist') as TForm['is_nursing_specialist'],
                            );
                        }}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                    >
                        {QUALIFICATION_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">
                        Nur „Pflegefachkraft" zählt im Dienstplan als Fachkraft.
                    </p>
                    <InputError message={errors.qualification_level} className="mt-2" />
                </div>
            ) : (
                <p className="text-sm text-gray-500">
                    Qualifikationsstufe ist nur für Pflegerollen relevant.
                </p>
            )}

            {/* Vertrags- und Kontodaten */}
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <div className="flex min-h-[2.5rem] items-end">
                        <InputLabel htmlFor="weekly_hours" value="Wochenstunden (Std.)" />
                    </div>
                    <TextInput
                        id="weekly_hours"
                        type="number"
                        step="0.25"
                        min="0"
                        max="99.99"
                        value={data.weekly_hours}
                        onChange={(event) =>
                            setData('weekly_hours', event.target.value as TForm['weekly_hours'])
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.weekly_hours} className="mt-2" />
                </div>

                <div>
                    <div className="flex min-h-[2.5rem] items-end">
                        <InputLabel
                            htmlFor="regular_work_days_per_week"
                            value="Regel-Arbeitstage pro Woche"
                        />
                    </div>
                    <TextInput
                        id="regular_work_days_per_week"
                        type="number"
                        min="1"
                        max="7"
                        value={data.regular_work_days_per_week ?? ''}
                        onChange={(event) =>
                            setData(
                                'regular_work_days_per_week',
                                event.target.value as TForm['regular_work_days_per_week'],
                            )
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.regular_work_days_per_week} className="mt-2" />
                </div>

                <div>
                    <div className="flex min-h-[2.5rem] items-end">
                        <InputLabel htmlFor="annual_vacation_days" value="Jahresurlaub (Tage)" />
                    </div>
                    <TextInput
                        id="annual_vacation_days"
                        type="number"
                        min="0"
                        max="366"
                        value={data.annual_vacation_days}
                        onChange={(event) =>
                            setData(
                                'annual_vacation_days',
                                event.target.value as TForm['annual_vacation_days'],
                            )
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.annual_vacation_days} className="mt-2" />
                </div>

                <div>
                    <div className="flex min-h-[2.5rem] items-end">
                        <InputLabel
                            htmlFor="vacation_days_carried_over"
                            value="Urlaubstage aus Vorjahr (Tage)"
                        />
                    </div>
                    <TextInput
                        id="vacation_days_carried_over"
                        type="number"
                        min="0"
                        max="366"
                        value={data.vacation_days_carried_over}
                        onChange={(event) =>
                            setData(
                                'vacation_days_carried_over',
                                event.target.value as TForm['vacation_days_carried_over'],
                            )
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.vacation_days_carried_over} className="mt-2" />
                </div>

                <div className="sm:col-span-2">
                    <InputLabel
                        htmlFor="overtime_minutes_balance"
                        value="Überstunden-Saldo (Min.)"
                    />
                    <TextInput
                        id="overtime_minutes_balance"
                        type="number"
                        value={data.overtime_minutes_balance}
                        onChange={(event) =>
                            setData(
                                'overtime_minutes_balance',
                                event.target.value as TForm['overtime_minutes_balance'],
                            )
                        }
                        className="mt-1 block w-full"
                    />
                    <InputError message={errors.overtime_minutes_balance} className="mt-2" />
                </div>
            </div>

            {/* Schichtfähigkeit */}
            <div>
                <p className="text-sm font-medium text-gray-700">Schichtfähigkeit</p>
                <p className="mt-0.5 text-xs text-gray-500">
                    Welche Dienste darf dieser Mitarbeiter übernehmen?
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                    <ShiftChip
                        label="Frühdienst"
                        checked={data.can_work_early}
                        onToggle={() =>
                            setData(
                                'can_work_early',
                                !data.can_work_early as TForm['can_work_early'],
                            )
                        }
                    />
                    <ShiftChip
                        label="Spätdienst"
                        checked={data.can_work_late}
                        onToggle={() =>
                            setData('can_work_late', !data.can_work_late as TForm['can_work_late'])
                        }
                    />
                    <ShiftChip
                        label="Nachtdienst"
                        checked={data.can_work_night}
                        onToggle={() =>
                            setData(
                                'can_work_night',
                                !data.can_work_night as TForm['can_work_night'],
                            )
                        }
                    />
                </div>
            </div>

            {/* Sonderregelungen */}
            {isNursingRole && (
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p className="text-sm font-semibold text-gray-900">
                        Sonderregelungen (mit der PDL vereinbart)
                    </p>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Diese Vorgaben berücksichtigt der Dienstplan-Generator automatisch.
                    </p>

                    {/* Keine Wochenenddienste */}
                    <label className="mt-4 flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-3">
                        <span>
                            <span className="block text-sm font-medium text-gray-900">
                                Keine Wochenenddienste
                            </span>
                            <span className="block text-xs text-gray-500">
                                Wird nie an Samstag oder Sonntag eingeplant.
                            </span>
                        </span>
                        <input
                            type="checkbox"
                            checked={data.avoids_weekends}
                            onChange={(event) =>
                                setData(
                                    'avoids_weekends',
                                    event.target.checked as TForm['avoids_weekends'],
                                )
                            }
                            className="h-5 w-5 rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                        />
                    </label>

                    {/* Wochen-Rhythmus */}
                    <div className="mt-4">
                        <InputLabel
                            htmlFor="week_rotation"
                            value="Wochen-Rhythmus (1 Woche Dienst / 1 Woche frei)"
                        />
                        <select
                            id="week_rotation"
                            value={data.week_rotation}
                            onChange={(event) =>
                                setData(
                                    'week_rotation',
                                    event.target.value as TForm['week_rotation'],
                                )
                            }
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                        >
                            <option value="">Kein Rhythmus</option>
                            <option value="even">Arbeitet nur in geraden Kalenderwochen</option>
                            <option value="odd">Arbeitet nur in ungeraden Kalenderwochen</option>
                        </select>
                        <InputError message={errors.week_rotation} className="mt-2" />
                    </div>

                    {/* Feste freie Wochentage */}
                    <div className="mt-4">
                        <p className="text-sm font-medium text-gray-700">Feste freie Wochentage</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {WEEKDAYS.map((day) => {
                                const checked = data.fixed_free_weekdays.includes(day.iso);

                                return (
                                    <ShiftChip
                                        key={day.iso}
                                        label={day.label}
                                        checked={checked}
                                        onToggle={() =>
                                            setData(
                                                'fixed_free_weekdays',
                                                (checked
                                                    ? data.fixed_free_weekdays.filter(
                                                          (iso) => iso !== day.iso,
                                                      )
                                                    : [...data.fixed_free_weekdays, day.iso].sort(
                                                          (a, b) => a - b,
                                                      )) as TForm['fixed_free_weekdays'],
                                            )
                                        }
                                    />
                                );
                            })}
                        </div>
                        <InputError message={errors.fixed_free_weekdays} className="mt-2" />
                    </div>

                    {/* Max. Arbeitstage am Stück */}
                    <div className="mt-4">
                        <InputLabel
                            htmlFor="max_consecutive_days_override"
                            value="Max. Arbeitstage am Stück (optional)"
                        />
                        <TextInput
                            id="max_consecutive_days_override"
                            type="number"
                            min="1"
                            max="14"
                            value={data.max_consecutive_days_override ?? ''}
                            onChange={(event) =>
                                setData(
                                    'max_consecutive_days_override',
                                    event.target.value as TForm['max_consecutive_days_override'],
                                )
                            }
                            className="mt-1 block w-full sm:max-w-[12rem]"
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            Leer lassen für die allgemeine Obergrenze.
                        </p>
                        <InputError
                            message={errors.max_consecutive_days_override}
                            className="mt-2"
                        />
                    </div>

                    {/* Notiz */}
                    <div className="mt-4">
                        <InputLabel
                            htmlFor="scheduling_note"
                            value="Weitere Absprache (Notiz, nur informativ)"
                        />
                        <textarea
                            id="scheduling_note"
                            value={data.scheduling_note}
                            onChange={(event) =>
                                setData(
                                    'scheduling_note',
                                    event.target.value as TForm['scheduling_note'],
                                )
                            }
                            rows={2}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#9B1C3B] focus:ring-[#9B1C3B]"
                            placeholder="z. B. Bevorzugt Frühdienste; donnerstags später Beginn …"
                        />
                        <InputError message={errors.scheduling_note} className="mt-2" />
                    </div>
                </div>
            )}

            {/* Aktiv-Status */}
            <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-gray-200 p-4">
                <span>
                    <span className="block text-sm font-medium text-gray-900">
                        Mitarbeiterprofil aktiv
                    </span>
                    <span className="block text-xs text-gray-500">
                        Inaktive Profile werden nicht in den Dienstplan eingeplant.
                    </span>
                </span>
                <input
                    type="checkbox"
                    checked={data.active}
                    onChange={(event) => setData('active', event.target.checked as TForm['active'])}
                    className="h-5 w-5 rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                />
            </label>
        </div>
    );
}

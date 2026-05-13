import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

export type EmployeeProfileFormData = {
    role: string;
    is_nursing_specialist: boolean;
    weekly_hours: string | number;
    regular_work_days_per_week: string | number | null;
    annual_vacation_days: string | number;
    vacation_days_carried_over: string | number;
    overtime_minutes_balance: string | number;
    can_work_early: boolean;
    can_work_late: boolean;
    can_work_night: boolean;
    active: boolean;
};

type EmployeeProfileFieldErrors = Partial<
    Record<keyof EmployeeProfileFormData, string>
>;

type Props<TForm extends EmployeeProfileFormData> = {
    data: TForm;
    setData: <TKey extends keyof TForm>(key: TKey, value: TForm[TKey]) => void;
    errors: EmployeeProfileFieldErrors;
};

export default function EmployeeProfileFields<TForm extends EmployeeProfileFormData>({
    data,
    setData,
    errors,
}: Props<TForm>) {
    const isNursingRole = data.role === 'Pflegekraft';

    return (
        <div className="space-y-6 rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <div>
                <h3 className="text-base font-semibold text-gray-900">
                    Mitarbeiterprofil
                </h3>
                <p className="mt-1 text-sm text-gray-600">
                    Diese Daten werden später für Urlaub, Überstunden und Dienstplanung genutzt.
                </p>
            </div>

            {isNursingRole && (
                <label className="flex items-center gap-3 rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">
                    <input
                        type="checkbox"
                        checked={data.is_nursing_specialist}
                        onChange={(event) =>
                            setData('is_nursing_specialist', event.target.checked as TForm['is_nursing_specialist'])
                        }
                        className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                    />
                    Pflege-Fachkraft
                </label>
            )}

            {!isNursingRole && (
                <p className="rounded-xl bg-white p-3 text-sm text-gray-600 shadow-sm">
                    Fachkraft-Markierung ist nur für Pflegekräfte relevant.
                </p>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <InputLabel htmlFor="weekly_hours" value="Wochenstunden" />
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
                    <InputLabel
                        htmlFor="regular_work_days_per_week"
                        value="Regel-Arbeitstage pro Woche"
                    />
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
                    <InputError
                        message={errors.regular_work_days_per_week}
                        className="mt-2"
                    />
                </div>

                <div>
                    <InputLabel htmlFor="annual_vacation_days" value="Jahresurlaub" />
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
                    <InputError
                        message={errors.annual_vacation_days}
                        className="mt-2"
                    />
                </div>

                <div>
                    <InputLabel
                        htmlFor="vacation_days_carried_over"
                        value="Urlaubstage aus Vorjahr"
                    />
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
                    <InputError
                        message={errors.vacation_days_carried_over}
                        className="mt-2"
                    />
                </div>

                <div>
                    <InputLabel
                        htmlFor="overtime_minutes_balance"
                        value="Überstunden-Saldo in Minuten"
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
                    <InputError
                        message={errors.overtime_minutes_balance}
                        className="mt-2"
                    />
                </div>
            </div>

            <div>
                <p className="mb-3 text-sm font-medium text-gray-700">
                    Schichtfähigkeit
                </p>

                <div className="grid gap-3 md:grid-cols-3">
                    <label className="flex items-center gap-3 rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">
                        <input
                            type="checkbox"
                            checked={data.can_work_early}
                            onChange={(event) =>
                                setData('can_work_early', event.target.checked as TForm['can_work_early'])
                            }
                            className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                        />
                        Frühdienst
                    </label>

                    <label className="flex items-center gap-3 rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">
                        <input
                            type="checkbox"
                            checked={data.can_work_late}
                            onChange={(event) =>
                                setData('can_work_late', event.target.checked as TForm['can_work_late'])
                            }
                            className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                        />
                        Spätdienst
                    </label>

                    <label className="flex items-center gap-3 rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">
                        <input
                            type="checkbox"
                            checked={data.can_work_night}
                            onChange={(event) =>
                                setData('can_work_night', event.target.checked as TForm['can_work_night'])
                            }
                            className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                        />
                        Nachtdienst
                    </label>
                </div>
            </div>

            <label className="flex items-center gap-3 rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">
                <input
                    type="checkbox"
                    checked={data.active}
                    onChange={(event) =>
                        setData('active', event.target.checked as TForm['active'])
                    }
                    className="rounded border-gray-300 text-[#9B1C3B] focus:ring-[#9B1C3B]"
                />
                Mitarbeiterprofil aktiv
            </label>
        </div>
    );
}
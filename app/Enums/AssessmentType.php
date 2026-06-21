<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Validierte Pflege-Assessments. Jeder Typ bringt seinen Item-Katalog,
 * sein Scoring und sein Re-Evaluations-Intervall mit, damit das Modul
 * datengetrieben um weitere Instrumente erweiterbar ist.
 */
enum AssessmentType: string
{
    case Braden = 'braden';
    case Norton = 'norton';
    case Pain = 'pain';
    case Fall = 'fall';
    case Mna = 'mna';
    case Continence = 'continence';

    public function label(): string
    {
        return match ($this) {
            self::Braden => 'Braden-Skala (Dekubitusrisiko)',
            self::Norton => 'Norton-Skala (Dekubitusrisiko)',
            self::Pain => 'Schmerz (NRS 0–10)',
            self::Fall => 'Sturzrisiko',
            self::Mna => 'MNA (Ernährung, Short Form)',
            self::Continence => 'Kontinenzprofil',
        };
    }

    /** Wochen bis zur fälligen Neubewertung. */
    public function reevaluationWeeks(): int
    {
        return match ($this) {
            self::Pain => 1,
            self::Braden, self::Norton, self::Fall => 4,
            self::Mna, self::Continence => 12,
        };
    }

    /**
     * Item-Katalog: je Item Schlüssel, Label und wählbare Optionen (Wert + Label).
     *
     * @return list<array{key: string, label: string, options: list<array{value: int, label: string}>}>
     */
    public function catalog(): array
    {
        return match ($this) {
            self::Braden => [
                self::scaleItem('sensory_perception', 'Sensorisches Empfindungsvermögen', ['Fehlt', 'Stark eingeschränkt', 'Leicht eingeschränkt', 'Vorhanden']),
                self::scaleItem('moisture', 'Feuchtigkeit', ['Ständig feucht', 'Oft feucht', 'Manchmal feucht', 'Selten feucht']),
                self::scaleItem('activity', 'Aktivität', ['Bettlägerig', 'Sitzt auf', 'Geht wenig', 'Geht regelmäßig']),
                self::scaleItem('mobility', 'Mobilität', ['Komplett immobil', 'Stark eingeschränkt', 'Gering eingeschränkt', 'Mobil']),
                self::scaleItem('nutrition', 'Ernährung', ['Sehr schlecht', 'Wahrscheinlich unzureichend', 'Ausreichend', 'Gut']),
                self::scaleItem('friction_shear', 'Reibung und Scherkräfte', ['Problem', 'Potenzielles Problem', 'Kein Problem']),
            ],
            self::Norton => [
                self::scaleItem('physical_condition', 'Körperlicher Zustand', ['Sehr schlecht', 'Schlecht', 'Leidlich', 'Gut']),
                self::scaleItem('mental_condition', 'Geistiger Zustand', ['Stuporös', 'Verwirrt', 'Apathisch', 'Klar']),
                self::scaleItem('activity', 'Aktivität', ['Bettlägerig', 'Rollstuhl', 'Geht mit Hilfe', 'Geht']),
                self::scaleItem('mobility', 'Beweglichkeit', ['Voll eingeschränkt', 'Sehr eingeschränkt', 'Kaum eingeschränkt', 'Voll']),
                self::scaleItem('incontinence', 'Inkontinenz', ['Stuhl und Harn', 'Meist Harn', 'Manchmal', 'Keine']),
            ],
            self::Pain => [
                self::selectItem('nrs', 'Schmerzstärke (Numerische Rating-Skala)', array_map(
                    fn (int $n): array => self::option($n, (string) $n),
                    range(0, 10),
                )),
            ],
            self::Fall => [
                self::yesNoItem('prior_fall', 'Sturz in den letzten 12 Monaten'),
                self::yesNoItem('gait_balance', 'Gang- oder Balancestörung'),
                self::yesNoItem('vision', 'Sehbeeinträchtigung'),
                self::yesNoItem('cognition', 'Kognitive Einschränkung'),
                self::yesNoItem('medication', '≥ 4 Medikamente oder psychotrope Medikation'),
                self::yesNoItem('continence', 'Inkontinenz oder Harndrang'),
                self::yesNoItem('assistive_device', 'Benötigt Gehhilfe'),
            ],
            self::Mna => [
                self::selectItem('food_intake', 'Appetit/Nahrungsaufnahme (letzte 3 Monate)', [
                    self::option(0, 'Starke Abnahme'), self::option(1, 'Leichte Abnahme'), self::option(2, 'Keine Abnahme'),
                ]),
                self::selectItem('weight_loss', 'Gewichtsverlust (letzte 3 Monate)', [
                    self::option(0, 'Mehr als 3 kg'), self::option(1, 'Unbekannt'), self::option(2, '1 bis 3 kg'), self::option(3, 'Kein Gewichtsverlust'),
                ]),
                self::selectItem('mobility', 'Mobilität', [
                    self::option(0, 'Bettlägerig'), self::option(1, 'In der Wohnung mobil'), self::option(2, 'Geht nach draußen'),
                ]),
                self::selectItem('acute_disease', 'Akute Krankheit/psychischer Stress (letzte 3 Monate)', [
                    self::option(0, 'Ja'), self::option(2, 'Nein'),
                ]),
                self::selectItem('neuropsych', 'Neuropsychologische Probleme', [
                    self::option(0, 'Schwere Demenz/Depression'), self::option(1, 'Leichte Demenz'), self::option(2, 'Keine Probleme'),
                ]),
                self::selectItem('bmi', 'Body-Mass-Index', [
                    self::option(0, 'BMI unter 19'), self::option(1, 'BMI 19 bis unter 21'), self::option(2, 'BMI 21 bis unter 23'), self::option(3, 'BMI 23 oder mehr'),
                ]),
            ],
            self::Continence => [
                self::selectItem('continence_profile', 'Kontinenzprofil (Expertenstandard)', [
                    self::option(6, 'Kontinenz'),
                    self::option(5, 'Unabhängig erreichte Kontinenz'),
                    self::option(4, 'Abhängig erreichte Kontinenz'),
                    self::option(3, 'Unabhängig kompensierte Inkontinenz'),
                    self::option(2, 'Abhängig kompensierte Inkontinenz'),
                    self::option(1, 'Nicht kompensierte Inkontinenz'),
                ]),
            ],
        };
    }

    /**
     * Berechnet Gesamtscore + Risiko-/Ergebnisstufe aus den Antworten.
     *
     * @param  array<string, int>  $answers
     * @return array{total: int, risk: string}
     */
    public function score(array $answers): array
    {
        $sum = array_sum(array_map('intval', $answers));

        return match ($this) {
            self::Braden => ['total' => $sum, 'risk' => match (true) {
                $sum <= 9 => 'Sehr hohes Risiko',
                $sum <= 12 => 'Hohes Risiko',
                $sum <= 14 => 'Mittleres Risiko',
                $sum <= 18 => 'Geringes Risiko',
                default => 'Kein Risiko',
            }],
            self::Norton => ['total' => $sum, 'risk' => match (true) {
                $sum <= 9 => 'Sehr hohes Risiko',
                $sum <= 14 => 'Erhöhtes Dekubitusrisiko',
                default => 'Kein erhöhtes Risiko',
            }],
            self::Pain => ['total' => $sum, 'risk' => match (true) {
                $sum === 0 => 'Schmerzfrei',
                $sum <= 3 => 'Leichte Schmerzen',
                $sum <= 6 => 'Mittlere Schmerzen',
                default => 'Starke Schmerzen',
            }],
            self::Fall => ['total' => $sum, 'risk' => match (true) {
                $sum <= 1 => 'Geringes Risiko',
                $sum <= 3 => 'Mittleres Risiko',
                default => 'Hohes Risiko',
            }],
            self::Mna => ['total' => $sum, 'risk' => match (true) {
                $sum <= 7 => 'Mangelernährung',
                $sum <= 11 => 'Risiko für Mangelernährung',
                default => 'Normaler Ernährungszustand',
            }],
            self::Continence => ['total' => $sum, 'risk' => $this->continenceLabel((int) ($answers['continence_profile'] ?? 0))],
        };
    }

    private function continenceLabel(int $value): string
    {
        return match ($value) {
            6 => 'Kontinenz',
            5 => 'Unabhängig erreichte Kontinenz',
            4 => 'Abhängig erreichte Kontinenz',
            3 => 'Unabhängig kompensierte Inkontinenz',
            2 => 'Abhängig kompensierte Inkontinenz',
            1 => 'Nicht kompensierte Inkontinenz',
            default => 'Unbekannt',
        };
    }

    /** @return array{value: int, label: string} */
    private static function option(int $value, string $label): array
    {
        return ['value' => $value, 'label' => $label];
    }

    /**
     * @param  list<array{value: int, label: string}>  $options
     * @return array{key: string, label: string, options: list<array{value: int, label: string}>}
     */
    private static function selectItem(string $key, string $label, array $options): array
    {
        return ['key' => $key, 'label' => $label, 'options' => $options];
    }

    /**
     * Aufsteigende Skala: Werte beginnen bei 1 in Reihenfolge der Labels.
     *
     * @param  list<string>  $labels
     * @return array{key: string, label: string, options: list<array{value: int, label: string}>}
     */
    private static function scaleItem(string $key, string $label, array $labels): array
    {
        $options = [];
        foreach ($labels as $index => $text) {
            $options[] = self::option($index + 1, $text);
        }

        return self::selectItem($key, $label, $options);
    }

    /**
     * @return array{key: string, label: string, options: list<array{value: int, label: string}>}
     */
    private static function yesNoItem(string $key, string $label): array
    {
        return self::selectItem($key, $label, [self::option(0, 'Nein'), self::option(1, 'Ja')]);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}

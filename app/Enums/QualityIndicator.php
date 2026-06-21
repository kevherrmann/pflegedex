<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ergebnisindikatoren der indikatorengestützten Qualitätsdarstellung nach
 * § 113b SGB XI (vollstationäre Pflege), gegliedert in drei Qualitätsbereiche.
 *
 * Vereinfachte interne Erhebung: erfasst je Bewohner und Erhebungshalbjahr das
 * Ergebnis je Indikator. Die offizielle Risikoadjustierung und die Übermittlung
 * an die Datenauswertungsstelle (DAS) sind hier NICHT abgebildet.
 */
enum QualityIndicator: string
{
    // Bereich 1 – Erhalt/Förderung der Selbständigkeit
    case Mobility = 'mobility';
    case Adl = 'adl';
    case SocialContacts = 'social_contacts';

    // Bereich 2 – Schutz vor gesundheitlichen Schädigungen
    case Decubitus = 'decubitus';
    case FallInjury = 'fall_injury';
    case WeightLoss = 'weight_loss';

    // Bereich 3 – Unterstützung besonderer Bedarfslagen
    case IntegrationTalk = 'integration_talk';
    case Restraints = 'restraints';
    case BedRails = 'bed_rails';
    case PainAssessment = 'pain_assessment';

    public function label(): string
    {
        return match ($this) {
            self::Mobility => 'Erhaltene Mobilität',
            self::Adl => 'Erhaltene Selbständigkeit bei alltäglichen Verrichtungen',
            self::SocialContacts => 'Erhaltene Selbständigkeit bei Alltagsgestaltung und sozialen Kontakten',
            self::Decubitus => 'Dekubitusentstehung',
            self::FallInjury => 'Schwerwiegende Sturzfolgen (Frakturen)',
            self::WeightLoss => 'Unbeabsichtigter Gewichtsverlust',
            self::IntegrationTalk => 'Integrationsgespräch nach Heimeinzug',
            self::Restraints => 'Anwendung von Gurten',
            self::BedRails => 'Anwendung von Bettseitenteilen',
            self::PainAssessment => 'Aktualität der Schmerzeinschätzung',
        };
    }

    /** Qualitätsbereich (1–3). */
    public function area(): int
    {
        return match ($this) {
            self::Mobility, self::Adl, self::SocialContacts => 1,
            self::Decubitus, self::FallInjury, self::WeightLoss => 2,
            self::IntegrationTalk, self::Restraints, self::BedRails, self::PainAssessment => 3,
        };
    }

    /**
     * Art des Indikators: 'maintenance' (Erhalt = gut), 'negative' (Ereignis = schlecht),
     * 'positive' (Maßnahme erfolgt = gut).
     */
    public function kind(): string
    {
        return match ($this) {
            self::Mobility, self::Adl, self::SocialContacts => 'maintenance',
            self::Decubitus, self::FallInjury, self::WeightLoss, self::Restraints, self::BedRails => 'negative',
            self::IntegrationTalk, self::PainAssessment => 'positive',
        };
    }

    /**
     * @return list<array{value: string, label: string, quality: string}>
     */
    public function options(): array
    {
        $notAssessable = ['value' => 'not_assessable', 'label' => 'Nicht erhebbar', 'quality' => 'excluded'];

        return match ($this->kind()) {
            'maintenance' => [
                ['value' => 'maintained', 'label' => 'Erhalten / verbessert', 'quality' => 'good'],
                ['value' => 'declined', 'label' => 'Verschlechtert', 'quality' => 'bad'],
                $notAssessable,
            ],
            'negative' => [
                ['value' => 'no', 'label' => 'Nein', 'quality' => 'good'],
                ['value' => 'yes', 'label' => 'Ja', 'quality' => 'bad'],
                $notAssessable,
            ],
            default => [ // positive
                ['value' => 'yes', 'label' => 'Ja', 'quality' => 'good'],
                ['value' => 'no', 'label' => 'Nein', 'quality' => 'bad'],
                $notAssessable,
            ],
        };
    }

    /** Klassifiziert einen Antwortwert: good | bad | excluded | unknown. */
    public function quality(?string $value): string
    {
        foreach ($this->options() as $option) {
            if ($option['value'] === $value) {
                return $option['quality'];
            }
        }

        return 'unknown';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $i): string => $i->value, self::cases());
    }
}

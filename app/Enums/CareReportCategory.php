<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pflegebericht-Kategorien.
 *
 * Werte sind die in DB und UI sichtbaren deutschen Bezeichnungen.
 * Case-Namen sind ASCII (PHP-Identifier-Regeln), Werte deutsch mit Umlauten.
 */
enum CareReportCategory: string
{
    case Grundpflege = 'Grundpflege';
    case Beobachtung = 'Beobachtung';
    case Mobilitaet = 'Mobilität';
    case Medikation = 'Medikation';
    case Uebergabe = 'Übergabe';
    case Sonstiges = 'Sonstiges';

    /**
     * Geordnete Liste aller Werte fuer Tabs/Tabs-Sortierung in der UI.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Kuratierte Standard-Textbausteine je Kategorie.
     *
     * Strukturmodell-Prinzip ("Ein-STEP"): wiederkehrende Routine wird per
     * Klick eingefuegt, frei getippt wird nur noch die Abweichung. Bewusst
     * knapp und fachlich neutral formuliert.
     *
     * @return list<string>
     */
    public function textBlocks(): array
    {
        return match ($this) {
            self::Grundpflege => [
                'Körperpflege vollständig übernommen',
                'Körperpflege teilweise übernommen, Ressourcen gefördert',
                'Intimpflege durchgeführt',
                'Mund- und Zahnpflege durchgeführt',
                'Hautzustand unauffällig',
                'Hautpflege durchgeführt',
                'Inkontinenzmaterial gewechselt',
                'Baden/Duschen durchgeführt',
            ],
            self::Beobachtung => [
                'Bewohner wach, orientiert und ansprechbar',
                'Allgemeinzustand stabil',
                'Keine Auffälligkeiten',
                'Nahrung gut angenommen',
                'Flüssigkeit ausreichend zu sich genommen',
                'Ausscheidung unauffällig',
                'Schlaf ruhig und ungestört',
                'Zeitweise unruhig/desorientiert',
            ],
            self::Mobilitaet => [
                'Transfer mit einer Pflegekraft',
                'Transfer mit zwei Pflegekräften',
                'Mit Rollator mobilisiert',
                'Im Rollstuhl mobilisiert',
                'Lagerung nach Plan durchgeführt',
                'Kontrakturenprophylaxe durchgeführt',
                'Dekubitusprophylaxe durchgeführt',
                'Bettlägerig, regelmäßig umgelagert',
            ],
            self::Medikation => [
                'Medikamente nach Plan verabreicht',
                'Medikamente vollständig eingenommen',
                'Bedarfsmedikation verabreicht',
                'Einnahme verweigert',
                'Augentropfen verabreicht',
                'Insulin nach Schema verabreicht',
            ],
            self::Uebergabe => [
                'Keine besonderen Vorkommnisse',
                'Übergabe an Folgeschicht erfolgt',
                'Arztkontakt erforderlich',
                'Angehörige informiert',
                'Anstehender Termin beachten',
            ],
            self::Sonstiges => [
                'Angehörigenbesuch',
                'Arztvisite durchgeführt',
                'Teilnahme an Betreuungsangebot',
                'Friseur-/Fußpflegetermin',
            ],
        };
    }

    /**
     * Alle Bausteine als Map (Kategorie-Wert => Bausteine) fuer das Frontend.
     *
     * @return array<string, list<string>>
     */
    public static function textBlockMap(): array
    {
        $map = [];

        foreach (self::cases() as $case) {
            $map[$case->value] = $case->textBlocks();
        }

        return $map;
    }
}

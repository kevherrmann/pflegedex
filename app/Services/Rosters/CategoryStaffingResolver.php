<?php

namespace App\Services\Rosters;

use App\Models\ShiftCategoryStaffingRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Liefert die Besetzungsregeln je Kategorie für einen Wohnbereich. Bevorzugt
 * explizite {@see ShiftCategoryStaffingRule}. Für Kategorien ohne explizite
 * Regel wird übergangsweise aus den alten Pro-Schicht-Regeln eine Kategorie-Regel
 * abgeleitet (stärkste Anforderung je Kategorie/Wochentag – KEINE Addition),
 * damit Bestandsdaten und ältere Setups weiter funktionieren.
 */
class CategoryStaffingResolver
{
    /**
     * @return Collection<int, ShiftCategoryStaffingRule>
     */
    public function forLocation(string $locationId): Collection
    {
        $explicit = ShiftCategoryStaffingRule::query()
            ->where('location_id', $locationId)
            ->get();

        $explicitCategories = $explicit->pluck('category')->unique();

        $fallbackRows = DB::table('shift_staffing_rules as r')
            ->join('shift_templates as t', 't.id', '=', 'r.shift_template_id')
            ->where('r.location_id', $locationId)
            ->selectRaw('COALESCE(t.category, t.code) as category')
            ->selectRaw('r.weekday as weekday')
            ->selectRaw('MAX(r.required_total_staff) as required_total_staff')
            ->selectRaw('MAX(r.target_total_staff) as target_total_staff')
            ->selectRaw('MAX(r.required_specialists) as required_specialists')
            ->groupByRaw('r.location_id, COALESCE(t.category, t.code), r.weekday')
            ->get();

        $synthesised = $fallbackRows
            ->reject(fn (object $row): bool => $explicitCategories->contains($row->category))
            ->map(fn (object $row): ShiftCategoryStaffingRule => new ShiftCategoryStaffingRule([
                'location_id' => $locationId,
                'category' => $row->category,
                'weekday' => $row->weekday === null ? null : (int) $row->weekday,
                'required_total_staff' => (int) $row->required_total_staff,
                'target_total_staff' => $row->target_total_staff === null ? null : (int) $row->target_total_staff,
                'required_specialists' => (int) $row->required_specialists,
            ]));

        return $explicit->concat($synthesised)->values();
    }
}

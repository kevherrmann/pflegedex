<?php

use App\Models\ShiftCategoryStaffingRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_category_staffing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('location_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            // Kategorie (early/late/night) – die Besetzung gilt pro Kategorie, nicht pro Einzelschicht.
            $table->string('category');
            // null = Standard für alle Wochentage; konkreter ISO-Wochentag (1-7) überschreibt.
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('required_total_staff');
            $table->unsignedTinyInteger('target_total_staff')->nullable();
            $table->unsignedTinyInteger('required_specialists');
            $table->timestamps();

            $table->unique(['location_id', 'category', 'weekday']);
        });

        // Backfill: bisherige Pro-Schicht-Regeln zu Pro-Kategorie-Regeln zusammenfassen
        // (stärkste Anforderung je Kategorie/Wochentag gewinnt – keine Addition).
        $aggregated = DB::table('shift_staffing_rules as r')
            ->join('shift_templates as t', 't.id', '=', 'r.shift_template_id')
            ->selectRaw('r.location_id as location_id')
            ->selectRaw('COALESCE(t.category, t.code) as category')
            ->selectRaw('r.weekday as weekday')
            ->selectRaw('MAX(r.required_total_staff) as required_total_staff')
            ->selectRaw('MAX(r.target_total_staff) as target_total_staff')
            ->selectRaw('MAX(r.required_specialists) as required_specialists')
            ->groupByRaw('r.location_id, COALESCE(t.category, t.code), r.weekday')
            ->get();

        foreach ($aggregated as $row) {
            ShiftCategoryStaffingRule::query()->updateOrCreate(
                [
                    'location_id' => $row->location_id,
                    'category' => $row->category,
                    'weekday' => $row->weekday,
                ],
                [
                    'required_total_staff' => (int) $row->required_total_staff,
                    'target_total_staff' => $row->target_total_staff === null ? null : (int) $row->target_total_staff,
                    'required_specialists' => (int) $row->required_specialists,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_category_staffing_rules');
    }
};

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CarePlanTopic;
use App\Models\CarePlan;
use App\Models\Resident;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * PDF-Export des Massnahmenplans eines Bewohners.
 *
 * Auth: PDL only.
 */
class CarePlanPdfController extends Controller
{
    public function download(Request $request, Resident $resident): HttpResponse
    {
        $this->authorize($request, $resident);

        /** @var CarePlan $carePlan */
        $carePlan = $resident->carePlan()
            ->with(['topics', 'location'])
            ->firstOrFail();

        $filename = sprintf(
            'Massnahmenplan_%s_%s.pdf',
            $resident->pseudonym,
            now()->format('Y-m-d'),
        );

        $pdf = Pdf::loadView('pdf.care-plan', [
            'resident' => $resident,
            'location' => $carePlan->location,
            'carePlan' => $carePlan,
            'carePlanTopics' => $carePlan->topics,
            'topicCatalog' => CarePlanTopic::cases(),
            'generatedAt' => now()->format('d.m.Y H:i'),
            'generatedBy' => $request->user()?->name,
        ])->setPaper('a4');

        return $pdf->download($filename);
    }

    private function authorize(Request $request, Resident $resident): void
    {
        $user = $request->user();
        abort_unless($user?->hasRole('PDL'), 403);
        abort_unless($user->canAccessLocation($resident->location_id), 403);
    }
}

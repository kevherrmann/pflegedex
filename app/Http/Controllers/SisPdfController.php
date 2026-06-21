<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SisRiskKind;
use App\Enums\SisTopic;
use App\Models\Resident;
use App\Models\Sis;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * PDF-Export der SIS eines Bewohners.
 *
 * Auth: PDL only (gleiche Berechtigung wie Schreibrechte auf SIS).
 */
class SisPdfController extends Controller
{
    public function download(Request $request, Resident $resident): HttpResponse
    {
        $this->authorize($request, $resident);

        /** @var Sis $sis */
        $sis = $resident->sis()
            ->with(['topicEntries', 'risks', 'location'])
            ->firstOrFail();

        $filename = sprintf(
            'SIS_%s_%s.pdf',
            $resident->pseudonym,
            now()->format('Y-m-d'),
        );

        $pdf = Pdf::loadView('pdf.sis', [
            'resident' => $resident,
            'location' => $sis->location,
            'sis' => $sis,
            'topicEntries' => $sis->topicEntries,
            'risks' => $sis->risks,
            'topics' => SisTopic::cases(),
            'riskKinds' => SisRiskKind::cases(),
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

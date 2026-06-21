<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiHealthService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'permissions' => [
                    'viewResidents' => $request->user()?->hasAnyRole(['PDL', 'Pflegekraft']) ?? false,
                    'manageCareReports' => $request->user()?->hasAnyRole(['PDL', 'Pflegekraft']) ?? false,
                    'manageShoppingList' => $request->user()?->hasAnyRole(['PDL', 'Pflegekraft']) ?? false,
                    'manageLocations' => $request->user()?->hasRole('PDL') ?? false,
                    'manageResidents' => $request->user()?->hasRole('PDL') ?? false,
                    'manageStaff' => $request->user()?->hasRole('PDL') ?? false,
                    'managePdlAccounts' => $request->user()?->hasRole('Admin') ?? false,
                    'viewAuditLog' => $request->user()?->hasRole('Admin') ?? false,
                    'manageAiModels' => $request->user()?->hasRole('Admin') ?? false,
                    'canViewAbsenceRequests' => $request->user()?->canRequestAbsence() ?? false,
                    'canManageAbsenceRequests' => $request->user()?->hasRole('PDL') ?? false,
                    'canViewOwnRoster' => (bool) ($request->user()?->employeeProfile?->active ?? false),
                ],
            ],
            'ai' => $this->aiStatus($request),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiStatus(Request $request): array
    {
        // KI-Status nur fuer Authenticated abfragen, sonst hat das keinen
        // praktischen Nutzen und der Health-Probe wuerde unnoetig laufen.
        if ($request->user() === null) {
            return [
                'available' => false,
                'modelPresent' => false,
                'model' => null,
                'reason' => null,
            ];
        }

        return app(AiHealthService::class)->status();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit-Log fuer Pflegedex.
 *
 * Liest aus der audits-Tabelle (owen-it/laravel-auditing) mit optionalen
 * Filtern auf User, Modell-Typ und Datum. Paginiert mit 25 Eintraegen pro
 * Seite.
 *
 * Zugriff: nur Admin (read-only). Alle anderen Rollen 403.
 */
class AuditController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Modelle, fuer die wir Audits in der UI filterbar machen.
     * Mapping: lesbarer Schluessel -> FQCN (wird gegen audits.auditable_type gematcht).
     */
    private const MODEL_FILTERS = [
        'resident' => Resident::class,
        'care_report' => CareReport::class,
        'sis' => Sis::class,
        'location' => Location::class,
        'user' => User::class,
    ];

    private const MODEL_LABELS = [
        'resident' => 'Bewohner',
        'care_report' => 'Pflegebericht',
        'sis' => 'SIS',
        'location' => 'Wohnbereich',
        'user' => 'Benutzerkonto',
    ];

    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);

        $filters = $this->parseFilters($request);

        $query = Audit::query()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['user_id'] !== null) {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['model'] !== null && isset(self::MODEL_FILTERS[$filters['model']])) {
            $query->where('auditable_type', self::MODEL_FILTERS[$filters['model']]);
        }

        if ($filters['from'] !== null) {
            $query->where('created_at', '>=', $filters['from']->copy()->startOfDay());
        }

        if ($filters['to'] !== null) {
            $query->where('created_at', '<=', $filters['to']->copy()->endOfDay());
        }

        if ($filters['event'] !== null && in_array($filters['event'], ['created', 'updated', 'deleted', 'restored'], true)) {
            $query->where('event', $filters['event']);
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Audit/Index', [
            'audits' => $page->getCollection()->map(fn (Audit $a): array => $this->auditPayload($a))->values(),
            'pagination' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'userId' => $filters['user_id'],
                'model' => $filters['model'],
                'event' => $filters['event'],
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
            ],
            'filterOptions' => [
                'users' => User::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $u): array => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                    ])
                    ->values(),
                'models' => collect(self::MODEL_LABELS)
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values(),
                'events' => [
                    ['key' => 'created', 'label' => 'Angelegt'],
                    ['key' => 'updated', 'label' => 'Geändert'],
                    ['key' => 'deleted', 'label' => 'Gelöscht'],
                    ['key' => 'restored', 'label' => 'Wiederhergestellt'],
                ],
            ],
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Admin'), 403);
    }

    /**
     * @return array{user_id: string|null, model: string|null, event: string|null, from: Carbon|null, to: Carbon|null}
     */
    private function parseFilters(Request $request): array
    {
        $userId = $request->string('user_id')->toString();
        $model = $request->string('model')->toString();
        $event = $request->string('event')->toString();
        $from = $this->parseDate($request->string('from')->toString());
        $to = $this->parseDate($request->string('to')->toString());

        return [
            'user_id' => $userId !== '' ? $userId : null,
            'model' => $model !== '' ? $model : null,
            'event' => $event !== '' ? $event : null,
            'from' => $from,
            'to' => $to,
        ];
    }

    private function parseDate(string $input): ?Carbon
    {
        if ($input === '') {
            return null;
        }

        try {
            return Carbon::parse($input);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Audit $audit): array
    {
        $modelKey = array_search($audit->auditable_type, self::MODEL_FILTERS, true);

        return [
            'id' => $audit->id,
            'event' => $audit->event,
            'modelLabel' => $modelKey !== false
                ? self::MODEL_LABELS[$modelKey]
                : class_basename((string) $audit->auditable_type),
            'modelKey' => $modelKey !== false ? $modelKey : null,
            'auditableId' => (string) $audit->auditable_id,
            'userName' => $audit->user instanceof User ? $audit->user->name : null,
            'userEmail' => $audit->user instanceof User ? $audit->user->email : null,
            'createdAt' => $audit->created_at?->format('d.m.Y H:i:s'),
            'changedFields' => $this->changedFields($audit),
            'ipAddress' => $audit->ip_address,
            'url' => $audit->url,
        ];
    }

    /**
     * Liefert eine kompakte Liste der geaenderten Felder mit alt/neu-Wert.
     *
     * @return list<array<string, mixed>>
     */
    private function changedFields(Audit $audit): array
    {
        $oldValues = is_array($audit->old_values) ? $audit->old_values : [];
        $newValues = is_array($audit->new_values) ? $audit->new_values : [];

        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                'field' => $key,
                'old' => $this->stringifyValue($oldValues[$key] ?? null),
                'new' => $this->stringifyValue($newValues[$key] ?? null),
            ];
        }

        return $rows;
    }

    private function stringifyValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Verschluesselte Gesundheits-Freitextwerte (K1) liegen im Audit als Chiffrat vor.
        // Fuer die autorisierte Anzeige (Audit-Log ist rollengeschuetzt) wieder lesbar machen.
        if (is_string($value)) {
            $value = $this->decryptIfEncrypted($value);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Macht einen ggf. verschluesselten Audit-Wert lesbar. Klartext bleibt unveraendert.
     */
    private function decryptIfEncrypted(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}

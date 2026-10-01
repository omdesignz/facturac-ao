<?php

namespace App\Http\Controllers;

use App\Fiscal\Saft\SaftExportSummary;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaftIndexController extends Controller
{
    public function __invoke(
        Request $request,
        SaftExportSummary $summary,
    ): Response|RedirectResponse {
        Gate::authorize('viewAny', FiscalDocument::class);
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    $from = (string) $request->input('from');
                    $to = (string) $value;

                    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from) === 1
                        && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to) === 1
                        && substr($from, 0, 4) !== substr($to, 0, 4)) {
                        $fail('O SAF-T tem de pertencer a um único exercício fiscal.');
                    }
                },
            ],
            'establishment' => [
                'nullable',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id)
                        ->where('legal_entity_id', $legalEntity->id),
                ),
            ],
        ], [
            'to.after_or_equal' => 'A data final não pode ser anterior à inicial.',
        ]);
        $from = CarbonImmutable::parse(
            (string) ($validated['from'] ?? now()->startOfYear()->toDateString()),
        );
        $to = CarbonImmutable::parse(
            (string) ($validated['to'] ?? now()->toDateString()),
        );
        $establishment = isset($validated['establishment'])
            ? $legalEntity->establishments()
                ->where('public_id', $validated['establishment'])
                ->first()
            : null;

        return Inertia::render('Saft/Index', [
            'summary' => $summary->for($legalEntity, $from, $to, $establishment),
            'establishments' => [
                ['value' => '', 'label' => 'Todos os estabelecimentos'],
                ...array_values($legalEntity->establishments()
                    ->orderByDesc('is_head_office')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Establishment $item): array => [
                        'value' => $item->public_id,
                        'label' => "{$item->name} · {$item->code}",
                    ])->all()),
            ],
            'schema' => [
                'version' => (string) config('fiscal.saft.version'),
                'namespace' => (string) config('fiscal.saft.namespace'),
                'scope' => 'Facturação',
            ],
        ]);
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}

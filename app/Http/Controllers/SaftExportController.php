<?php

namespace App\Http\Controllers;

use App\Fiscal\Saft\SaftExporter;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The SAF-T (AO) file for a period.
 *
 * Streamed straight back rather than queued: the AGT asks for this when it
 * asks, usually during an inspection, and a file that arrives by email later
 * is not an answer to the person standing at the counter.
 */
class SaftExportController extends Controller
{
    public function __construct(private SaftExporter $exporter) {}

    public function __invoke(Request $request): Response
    {
        $legalEntity = $this->legalEntity($request);
        Gate::authorize('viewAny', Customer::class);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'A data final não pode ser anterior à inicial.',
        ]);

        $from = CarbonImmutable::parse((string) $validated['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $validated['to'])->endOfDay();

        $xml = $this->exporter->export($legalEntity, $from, $to);

        activity('fiscal')
            ->causedBy($request->user())
            ->performedOn($legalEntity)
            ->withProperties(['from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->log('saft exported');

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'
                .$this->exporter->filename($legalEntity, $from, $to).'"',
        ]);
    }

    private function legalEntity(Request $request): LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);

        $legalEntity = $workspace->legalEntities()->oldest('id')->first();
        abort_unless($legalEntity instanceof LegalEntity, 404);

        return $legalEntity;
    }
}

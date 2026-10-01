<?php

namespace App\Http\Controllers;

use App\Fiscal\Saft\SaftExporter;
use App\Fiscal\Saft\SaftValidator;
use App\Http\Requests\ExportSaftRequest;
use App\Models\Establishment;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use RuntimeException;

class SaftExportController extends Controller
{
    public function __construct(
        private SaftExporter $exporter,
        private SaftValidator $validator,
    ) {}

    public function __invoke(ExportSaftRequest $request): Response|RedirectResponse
    {
        $legalEntity = $request->currentLegalEntity();
        abort_unless($legalEntity instanceof LegalEntity, 404);
        $validated = $request->validated();
        $from = CarbonImmutable::parse((string) $validated['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $validated['to'])->endOfDay();
        $establishment = isset($validated['establishment'])
            ? $legalEntity->establishments()
                ->where('public_id', $validated['establishment'])
                ->first()
            : null;
        abort_if(isset($validated['establishment']) && ! $establishment instanceof Establishment, 404);

        try {
            $xml = $this->exporter->export($legalEntity, $from, $to, $establishment);
            $this->validator->assertValid($xml);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('saft.index', array_filter([
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'establishment' => $establishment?->public_id,
                ]))
                ->with('error', 'O SAF-T não foi descarregado porque há documentos incompatíveis com o esquema. Reveja as verificações do período.');
        }

        activity('fiscal')
            ->causedBy($request->user())
            ->performedOn($legalEntity)
            ->withProperties([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'establishment_public_id' => $establishment?->public_id,
                'sha256' => hash('sha256', $xml),
            ])
            ->log('saft exported');

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'
                .$this->exporter->filename($legalEntity, $from, $to, $establishment).'"',
        ]);
    }
}

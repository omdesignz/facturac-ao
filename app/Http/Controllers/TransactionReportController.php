<?php

namespace App\Http\Controllers;

use App\Analytics\TransactionReport;
use App\Analytics\TransactionWorkbook;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Http\Requests\ExportTransactionsRequest;
use App\Jobs\GenerateTransactionExport;
use App\Models\TransactionExport;
use App\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TransactionReportController extends Controller
{
    public function index(ExportTransactionsRequest $request): Response|RedirectResponse
    {
        $company = $request->currentLegalEntity();
        if ($company === null) {
            return redirect()->route('onboarding')->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        return Inertia::render('Analytics/Export', [
            'filters' => $request->validated(),
            'columns' => TransactionReport::columns(),
            'types' => array_map(fn (FiscalDocumentType $type): array => ['value' => $type->value, 'label' => $type->label()], FiscalDocumentType::issuable()),
            'statuses' => array_values(array_map(fn (FiscalDocumentStatus $status): array => ['value' => $status->value, 'label' => $status->label()], array_filter(FiscalDocumentStatus::cases(), fn (FiscalDocumentStatus $status): bool => $status !== FiscalDocumentStatus::Draft))),
            'establishments' => $company->establishments()->orderBy('name')->get(['public_id', 'name'])->map(fn ($establishment): array => ['value' => $establishment->public_id, 'label' => $establishment->name]),
            'paymentMethods' => PaymentMethod::options(),
            'companyName' => $company->legal_name,
            'preferencesKey' => 'transaction-export:'.$company->public_id.':'.$request->user()->id,
            'maxDocuments' => TransactionReport::MAX_DOCUMENTS,
            'exports' => $this->recentExports($request),
        ]);
    }

    public function preview(ExportTransactionsRequest $request, TransactionReport $report): JsonResponse
    {
        $company = $request->currentLegalEntity();
        abort_if($company === null, 404);

        return response()->json($report->preview($company, $request->validated()));
    }

    public function download(ExportTransactionsRequest $request, TransactionWorkbook $workbook, TransactionReport $report): BinaryFileResponse|JsonResponse
    {
        $company = $request->currentLegalEntity();
        abort_if($company === null, 404);
        $filters = $request->validated();
        $documentIds = $report->documentIds($company, $filters);
        $detailRows = 0;
        foreach (['include_lines' => 'lines', 'include_payments' => 'settlements'] as $flag => $relation) {
            if ($filters[$flag] ?? false) {
                $count = (int) $report->query($company, $filters)->whereIn('id', $documentIds)->select('id')->withCount($relation)->get()->sum($relation.'_count');
                if ($count > TransactionReport::MAX_DETAIL_ROWS) {
                    throw ValidationException::withMessages(['from' => 'O relatório excede 20 000 linhas de detalhe. Escolha um período menor.']);
                }
                $detailRows += $count;
            }
        }
        if (count($documentIds) > 200 || $detailRows > 1000) {
            $export = TransactionExport::create([
                'workspace_id' => $company->workspace_id,
                'legal_entity_id' => $company->id,
                'user_id' => $request->user()->id,
                'filters' => $filters,
                'document_ids' => $documentIds,
                'expires_at' => now()->addDay(),
            ]);
            try {
                GenerateTransactionExport::dispatch($export->id);
            } catch (Throwable $exception) {
                (new GenerateTransactionExport($export->id))->failed($exception);
                throw $exception;
            }

            return response()->json($export->refresh()->summary(), 202)->header('Cache-Control', 'private, no-store');
        }
        $path = tempnam(sys_get_temp_dir(), 'facturac-export-');
        abort_if($path === false, 500);
        $book = null;
        try {
            $book = $workbook->build($company, $filters, $documentIds);
            (new Xlsx($book))->save($path);
            activity('report')->causedBy($request->user())->performedOn($company)
                ->withProperties(['from' => $filters['from'], 'to' => $filters['to'], 'columns' => $filters['columns'], 'sha256' => hash_file('sha256', $path)])
                ->log('transactions exported to Excel');
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            $book?->disconnectWorksheets();
        }

        return response()->download($path, 'transaccoes-'.$filters['from'].'-'.$filters['to'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function history(ExportTransactionsRequest $request): JsonResponse
    {
        return response()->json($this->recentExports($request))->header('Cache-Control', 'private, no-store');
    }

    public function file(ExportTransactionsRequest $request, TransactionExport $transactionExport): StreamedResponse
    {
        $company = $request->currentLegalEntity();
        abort_unless($company !== null && $transactionExport->workspace_id === $company->workspace_id
            && $transactionExport->legal_entity_id === $company->id
            && $transactionExport->user_id === $request->user()->id, 404);
        abort_if($transactionExport->expires_at->isPast(), 410, 'Este relatório expirou. Gere uma nova exportação.');
        abort_unless($transactionExport->status === 'ready', 409, 'O relatório ainda não está disponível.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($transactionExport->storagePath()), 410);

        return $disk->download($transactionExport->storagePath(), 'transaccoes-'.$transactionExport->filters['from'].'-'.$transactionExport->filters['to'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function recentExports(ExportTransactionsRequest $request): array
    {
        $company = $request->currentLegalEntity();
        abort_if($company === null, 404);

        return array_values(TransactionExport::query()->where('workspace_id', $company->workspace_id)
            ->where('legal_entity_id', $company->id)->where('user_id', $request->user()->id)
            ->where('expires_at', '>', now())->latest('id')->limit(30)->get()
            ->map(fn (TransactionExport $export): array => $export->summary())->all());
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search: documents by number, customer or NIF, and customers by
 * name, NIF or email, only ever inside the company the user is working in.
 */
class GlobalSearchController extends Controller
{
    private const int DOCUMENT_LIMIT = 6;

    private const int CUSTOMER_LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $legalEntity = $this->legalEntity($request);
        $user = $request->user();

        if (! $legalEntity instanceof LegalEntity || $user === null) {
            return response()->json(['documents' => [], 'customers' => []]);
        }

        $pattern = '%'.addcslashes(trim($validated['q']), '%_\\').'%';

        return response()->json([
            'documents' => $user->can('viewAny', FiscalDocument::class)
                ? $this->documents($legalEntity, $pattern)
                : [],
            'customers' => $user->can('viewAny', Customer::class)
                ? $this->customers($legalEntity, $pattern)
                : [],
        ]);
    }

    /**
     * @return list<array{
     *     public_id: string,
     *     document_no: string|null,
     *     document_type_label: string,
     *     document_date: string,
     *     customer_name: string|null,
     *     gross_total_minor: int,
     *     currency_code: string,
     *     workflow_status: string,
     *     workflow_label: string
     * }>
     */
    private function documents(LegalEntity $legalEntity, string $pattern): array
    {
        return FiscalDocument::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where(function ($matching) use ($pattern): void {
                $matching
                    ->where('document_no', 'like', $pattern)
                    ->orWhere('customer_name', 'like', $pattern)
                    ->orWhere('customer_tax_identification_number', 'like', $pattern);
            })
            ->with(['submissions' => fn ($query) => $query->latest('id')->select(['id', 'fiscal_document_id', 'status'])])
            ->latest('document_date')
            ->latest('id')
            ->limit(self::DOCUMENT_LIMIT)
            ->get()
            ->map(function (FiscalDocument $document): array {
                $submission = $document->submissions->first();

                return [
                    'public_id' => $document->public_id,
                    'document_no' => $document->document_no,
                    'document_type_label' => $document->document_type->label(),
                    'document_date' => $document->document_date->toDateString(),
                    'customer_name' => $document->customer_name,
                    'gross_total_minor' => $document->gross_total_minor,
                    'currency_code' => $document->currency_code,
                    'workflow_status' => $submission instanceof AgtSubmission
                        ? $submission->status->value
                        : $document->status->value,
                    'workflow_label' => $submission instanceof AgtSubmission
                        ? $submission->status->label()
                        : $document->status->label(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{public_id: string, name: string, tax_identification_number: string|null, is_active: bool}>
     */
    private function customers(LegalEntity $legalEntity, string $pattern): array
    {
        return $legalEntity->customers()
            ->where(function ($matching) use ($pattern): void {
                $matching
                    ->where('name', 'like', $pattern)
                    ->orWhere('tax_identification_number', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern);
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(self::CUSTOMER_LIMIT)
            ->get(['id', 'public_id', 'name', 'tax_identification_number', 'is_active'])
            ->map(fn (Customer $customer): array => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'is_active' => $customer->is_active,
            ])
            ->values()
            ->all();
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }
}

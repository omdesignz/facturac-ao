<?php

namespace App\Http\Requests;

use App\Analytics\TransactionReport;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Workspace;
use App\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FiscalDocument::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'title' => 'Relatório de transacções',
            'from' => now('Africa/Luanda')->startOfMonth()->toDateString(),
            'to' => now('Africa/Luanda')->toDateString(),
            'columns' => TransactionReport::DEFAULT_COLUMNS,
            'types' => [], 'statuses' => [],
            'include_lines' => false, 'include_payments' => false,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $company = $this->currentLegalEntity();

        return [
            'title' => ['required', 'string', 'max:100'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'establishment' => ['nullable', 'string', Rule::exists('establishments', 'public_id')->where(
                fn ($query) => $query->where('legal_entity_id', $company->id ?? 0)->where('workspace_id', $company->workspace_id ?? 0),
            )],
            'customer' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'types' => ['array', 'max:20'],
            'types.*' => ['required', 'distinct', Rule::enum(FiscalDocumentType::class)],
            'statuses' => ['array', 'max:6'],
            'statuses.*' => ['required', 'distinct', Rule::enum(FiscalDocumentStatus::class)->except([FiscalDocumentStatus::Draft])],
            'columns' => ['required', 'array', 'min:3', 'max:30', Rule::contains(['document_no', 'document_date', 'currency_code'])],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys(TransactionReport::columns()))],
            'include_lines' => ['required', 'boolean'],
            'include_payments' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'A data final não pode ser anterior à inicial.',
            'columns.contains' => 'Mantenha as colunas Documento, Data e Moeda.',
            'establishment.exists' => 'Escolha um estabelecimento desta empresa.',
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace ? $workspace->legalEntities()->oldest('id')->first() : null;
    }
}

<?php

namespace App\Fiscal;

use App\AgtSubmissionStatus;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class DocumentListCommand
{
    private function __construct(
        public int $page,
        public int $perPage,
        public ?string $agtStatus,
        public ?FiscalDocumentType $type,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $validated = Validator::make(['filters' => $input], self::rules())->validate()['filters'];

        return new self((int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 25),
            $validated['agt_status'] ?? null,
            isset($validated['type']) ? FiscalDocumentType::from($validated['type']) : null);
    }

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return [
            'filters' => ['array:page,per_page,agt_status,type'],
            'filters.page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'filters.per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'filters.agt_status' => ['sometimes', 'required', Rule::in(array_unique([
                ...array_column(AgtSubmissionStatus::cases(), 'value'), ...array_column(FiscalDocumentStatus::cases(), 'value'),
            ]))],
            'filters.type' => ['sometimes', 'required', Rule::enum(FiscalDocumentType::class)],
        ];
    }
}

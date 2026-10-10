<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\CatalogueItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class CatalogueCapabilities
{
    /** @return array<string, mixed> */
    public function readItem(DocumentReadContext $context, CatalogueReadCommand $command): array
    {
        $context->authorize('catalogue.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        [$record, $data] = SensitiveReadQuery::run(function () use ($context, $command): array {
            $record = $this->records($context, detail: true)->where('public_id', $command->publicId)->firstOrFail();

            return [$record, $this->present($record, detail: true)];
        });
        RequiredAudit::record(fn () => activity('capability')->performedOn($record)->causedBy($context->auditCauser())
            ->event('catalogue.read')->withProperties([...$context->audit(), 'operation_id' => (string) Str::uuid(), 'capability_version' => 1,
                'user_agent' => null, 'data_scope' => 'legal_entity_master', 'resource_public_id' => $data['public_id']])->log('Scoped master-data read'));
        $context->recordSuccessfulUse();

        return $data;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function listItems(DocumentReadContext $context, CatalogueListCommand $command): LengthAwarePaginator
    {
        $context->authorize('catalogue.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        $page = SensitiveReadQuery::run(function () use ($context, $command): LengthAwarePaginator {
            $records = $this->filtered($context, $command)->latest('id')->paginate($command->perPage, page: $command->page);

            return new LengthAwarePaginator($records->getCollection()->map(fn (CatalogueItem $record): array => $this->present($record)),
                $records->total(), $records->perPage(), $records->currentPage());
        });
        RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event('catalogue.list')
            ->withProperties([...$context->audit(), 'operation_id' => (string) Str::uuid(), 'capability_version' => 1, 'user_agent' => null, 'data_scope' => 'legal_entity_master',
                'page' => $command->page, 'per_page' => $command->perPage, 'result_count' => $page->count(),
                'search_applied' => $command->search !== null, 'status_filter' => $command->status])->log('Scoped master-data list'));
        $context->recordSuccessfulUse();

        return $page;
    }

    /** @return Builder<CatalogueItem> */
    private function records(DocumentReadContext $context, bool $detail = false): Builder
    {
        return CatalogueItem::query()->useWritePdo()->where('workspace_id', $context->workspaceId())->where('legal_entity_id', $context->legalEntityId())
            ->select(['id', 'public_id', 'code', 'type', 'name', 'unit_of_measure', 'unit_price_minor', 'currency_code', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code', 'is_active'])->when($detail, fn (Builder $query) => $query->addSelect('description'));
    }

    /** @return Builder<CatalogueItem> */
    private function filtered(DocumentReadContext $context, CatalogueListCommand $command): Builder
    {
        $query = $this->records($context);
        if ($command->status !== 'all') {
            $query->where('is_active', $command->status === 'active');
        }

        if ($command->type !== null) {
            $query->where('type', $command->type->value);
        }
        if ($command->code !== null) {
            $query->whereRaw(DB::getDriverName() === 'pgsql' ? 'code COLLATE "C" = ?' : 'code COLLATE BINARY = ?', [$command->code]);
        }

        if ($command->search !== null) {
            $function = match (DB::getDriverName()) {
                'pgsql' => 'strpos', 'sqlite' => 'instr',
                default => throw new \RuntimeException('Master-data reads require PostgreSQL or SQLite.'),
            };
            $query->where(function (Builder $nested) use ($function, $command): void {
                $nested->whereRaw("$function(name, ?) > 0", [$command->search])
                    ->orWhereRaw("$function(code, ?) > 0", [$command->search]);
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(CatalogueItem $record, bool $detail = false): array
    {
        $data = $record->only(['public_id', 'code', 'name', 'unit_of_measure', 'currency_code', 'tax_type', 'tax_code', 'tax_exemption_code']);
        $price = $record->getRawOriginal('unit_price_minor');
        if ((! is_int($price) && ! is_string($price)) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', (string) $price) !== 1
            || (strlen((string) $price) === 19 && strcmp((string) $price, '9223372036854775807') > 0)) {
            throw new \RuntimeException('Invalid master-data representation.');
        }
        $data += ['type' => $record->type->value, 'unit_price_minor' => (string) $price, 'price_scale' => 2,
            'tax_percentage' => $record->tax_percentage, 'is_active' => $record->is_active];
        $rules = ['public_id' => ['required', 'ulid'], 'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'], 'unit_of_measure' => ['required', 'string', 'max:32'],
            'currency_code' => ['required', 'regex:/\A[A-Z]{3}\z/'], 'tax_type' => ['required', 'string', 'max:8'],
            'tax_code' => ['present', 'nullable', 'string', 'max:8'], 'tax_exemption_code' => ['present', 'nullable', 'string', 'max:8'],
            'tax_percentage' => ['required', 'regex:/\A-?[0-9]{1,3}\.[0-9]{2}\z/']];
        if ($detail) {
            $data['description'] = $record->description;
            $rules['description'] = ['present', 'nullable', 'string', 'max:500'];
        }
        if (Validator::make($data, $rules)->fails()) {
            throw new \RuntimeException('Invalid master-data representation.');
        }
        foreach ($data as $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw new \RuntimeException('Invalid master-data representation.');
            }
        }

        return $data;
    }
}

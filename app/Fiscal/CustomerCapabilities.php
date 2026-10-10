<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class CustomerCapabilities
{
    /** @return array{items: list<array<string, mixed>>, has_more: bool} */
    public function searchCustomers(DocumentReadContext $context, CustomerSearchCommand $command): array
    {
        $context->authorize('customers.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        $data = SensitiveReadQuery::run(function () use ($context, $command): array {
            $statement = CustomerSearchQuery::statement($context, $command);
            $rows = DB::connection()->select($statement['sql'], $statement['bindings'], false);
            abort_if($rows === [] || (bool) $rows[0]->over_cap, 503);
            $items = [];
            foreach ($rows as $row) {
                if ($row->public_id !== null) {
                    $record = (new Customer)->newFromBuilder((array) $row);
                    $items[] = $this->present($record);
                }
            }

            return ['items' => array_slice($items, 0, 10), 'has_more' => count($items) > 10];
        });
        RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event('customers.search')
            ->withProperties([...$context->audit(), 'operation_id' => (string) Str::uuid(), 'capability_version' => 1,
                'user_agent' => null, 'data_scope' => 'legal_entity_master', 'result_count' => count($data['items']),
                'search_applied' => true, 'status_filter' => $command->status])->log('Bounded master-data search'));

        return $data;
    }

    /** @return array<string, mixed> */
    public function readCustomer(DocumentReadContext $context, CustomerReadCommand $command): array
    {
        $context->authorize('customers.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        [$record, $data] = SensitiveReadQuery::run(function () use ($context, $command): array {
            $record = $this->records($context)->where('public_id', $command->publicId)->firstOrFail();

            return [$record, $this->present($record)];
        });
        RequiredAudit::record(fn () => activity('capability')->performedOn($record)->causedBy($context->auditCauser())
            ->event('customers.read')->withProperties([...$context->audit(), 'operation_id' => (string) Str::uuid(), 'capability_version' => 1,
                'user_agent' => null, 'data_scope' => 'legal_entity_master', 'resource_public_id' => $data['public_id']])->log('Scoped master-data read'));
        $context->recordSuccessfulUse();

        return $data;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function listCustomers(DocumentReadContext $context, CustomerListCommand $command): LengthAwarePaginator
    {
        $context->authorize('customers.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        $page = SensitiveReadQuery::run(function () use ($context, $command): LengthAwarePaginator {
            $records = $this->filtered($context, $command)->latest('id')->paginate($command->perPage, page: $command->page);

            return new LengthAwarePaginator($records->getCollection()->map(fn (Customer $record): array => $this->present($record)),
                $records->total(), $records->perPage(), $records->currentPage());
        });
        RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event('customers.list')
            ->withProperties([...$context->audit(), 'operation_id' => (string) Str::uuid(), 'capability_version' => 1, 'user_agent' => null, 'data_scope' => 'legal_entity_master',
                'page' => $command->page, 'per_page' => $command->perPage, 'result_count' => $page->count(),
                'search_applied' => $command->search !== null, 'status_filter' => $command->status])->log('Scoped master-data list'));
        $context->recordSuccessfulUse();

        return $page;
    }

    /** @return Builder<Customer> */
    private function records(DocumentReadContext $context): Builder
    {
        return Customer::query()->useWritePdo()->where('workspace_id', $context->workspaceId())->where('legal_entity_id', $context->legalEntityId())
            ->select(['id', 'public_id', 'name', 'country_code', 'is_active']);
    }

    /** @return Builder<Customer> */
    private function filtered(DocumentReadContext $context, CustomerListCommand $command): Builder
    {
        $query = $this->records($context);
        if ($command->status !== 'all') {
            $query->where('is_active', $command->status === 'active');
        }

        if ($command->search !== null) {
            $function = match (DB::getDriverName()) {
                'pgsql' => 'strpos', 'sqlite' => 'instr',
                default => throw new \RuntimeException('Master-data reads require PostgreSQL or SQLite.'),
            };
            $query->whereRaw("$function(name, ?) > 0", [$command->search]);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(Customer $record): array
    {
        $data = ['public_id' => $record->public_id, 'name' => $record->name, 'country_code' => $record->country_code, 'is_active' => $record->is_active];
        $rules = ['public_id' => ['required', 'ulid'], 'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'regex:/\A[A-Z]{2}\z/']];
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

<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Analytics\AgingQuery;
use App\Http\Requests\MobileListRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MobileCustomerController
{
    public function __invoke(MobileListRequest $request, AgingQuery $aging): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $search = $request->search();

        $customers = $legalEntity->customers()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matching) use ($search): void {
                $matching
                    ->whereSearchLike('name', "%{$search}%")
                    ->orWhereSearchLike('tax_identification_number', "%{$search}%")
                    ->orWhereSearchLike('email', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(25);

        $owed = array_column($aging->byCustomer($legalEntity), 'outstanding_minor', 'customer_public_id');

        return $request->pageOf($customers, array_values($customers->getCollection()
            ->map(fn (Customer $customer): array => self::present($customer, $legalEntity, $owed[$customer->public_id] ?? 0))
            ->all()));
    }

    /**
     * @return array{public_id: string, name: string, tax_identification_number: string|null, email: string|null, outstanding_minor: int, currency_code: string}
     */
    public static function present(Customer $customer, LegalEntity $legalEntity, int $outstandingMinor): array
    {
        return [
            'public_id' => $customer->public_id,
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'email' => $customer->email,
            'outstanding_minor' => $outstandingMinor,
            'currency_code' => $legalEntity->currency_code,
        ];
    }
}

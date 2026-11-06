<?php

namespace App\Actions;

use App\Fiscal\CustomerCommands;
use App\Fiscal\CustomerCreateInput;
use App\Fiscal\HumanCustomerCommandContext;
use App\Models\LegalEntity;
use App\Models\MobileCreatedRecord;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer or an item made on the phone, through the web's own commands. The
 * client key brings a retried request back to the record it already made, so a
 * dropped connection cannot leave the person refused for their own new customer.
 */
final readonly class CreateMobileRecord
{
    public function __construct(
        private CustomerCommands $customers,
        private CreateCatalogueItem $items,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function customer(LegalEntity $legalEntity, User $user, string $clientKey, array $attributes): MobileCreatedRecord
    {
        return $this->once(
            $legalEntity,
            $user,
            $clientKey,
            'customer_id',
            fn (): int => $this->customers->create(
                HumanCustomerCommandContext::resolve($user, $legalEntity),
                CustomerCreateInput::human($attributes),
            )->id,
            ['tax_identification_number' => 'Já existe um cliente com este NIF.'],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  Closure(): array<string, mixed>  $productAttributes
     *
     * @throws ValidationException
     */
    public function item(LegalEntity $legalEntity, User $user, string $clientKey, array $validated, Closure $productAttributes): MobileCreatedRecord
    {
        return $this->once(
            $legalEntity,
            $user,
            $clientKey,
            'catalogue_item_id',
            fn (): int => $this->items->execute($user, $legalEntity, $validated, $productAttributes)->id,
            ['code' => 'Já existe um artigo com este código.'],
        );
    }

    /**
     * @param  Closure(): int  $create
     * @param  array<string, string>  $conflict  what to say when another request took the same NIF or code first
     */
    private function once(LegalEntity $legalEntity, User $user, string $clientKey, string $column, Closure $create, array $conflict): MobileCreatedRecord
    {
        try {
            return DB::transaction(fn (): MobileCreatedRecord => $this->existing($legalEntity, $clientKey)
                ?? MobileCreatedRecord::query()->create([
                    'workspace_id' => $legalEntity->workspace_id,
                    'legal_entity_id' => $legalEntity->id,
                    $column => $create(),
                    'client_key' => $clientKey,
                    'created_by_user_id' => $user->id,
                ]));
        } catch (UniqueConstraintViolationException) {
            // Either the same request raced itself, and the winner's record is the
            // answer, or a different one took this NIF or code between the check and now.
            return $this->existing($legalEntity, $clientKey) ?? throw ValidationException::withMessages($conflict);
        }
    }

    private function existing(LegalEntity $legalEntity, string $clientKey): ?MobileCreatedRecord
    {
        $existing = MobileCreatedRecord::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('client_key', $clientKey)
            ->first();

        if ($existing instanceof MobileCreatedRecord) {
            $existing->replayed = true;
        }

        return $existing;
    }
}

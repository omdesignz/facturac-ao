<?php

namespace App\Actions;

use App\Fiscal\HumanServiceCommandContext;
use App\Fiscal\ServiceCommands;
use App\Fiscal\ServiceCreateInput;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use App\Models\User;
use Closure;

/**
 * A new article, from the web's form or the phone: services go through the service
 * command, which verifies their tax treatment, and products are stored as given.
 */
final readonly class CreateCatalogueItem
{
    public function __construct(private ServiceCommands $services) {}

    /**
     * @param  array<string, mixed>  $validated  the form's validated fields
     * @param  Closure(): array<string, mixed>  $productAttributes  the stored shape of a product; a service never needs it, and its command validates its own price
     */
    public function execute(User $user, LegalEntity $legalEntity, array $validated, Closure $productAttributes): CatalogueItem
    {
        if ($validated['type'] !== 'service') {
            return $legalEntity->catalogueItems()->create([
                ...$productAttributes(),
                'workspace_id' => $legalEntity->workspace_id,
                'currency_code' => $legalEntity->currency_code,
            ]);
        }

        $barcode = $validated['barcode'] ?? null;
        // The service command accepts a fixed set of fields; the barcode
        // is added to the article once it exists.
        unset($validated['type'], $validated['tracks_stock'], $validated['reorder_level'], $validated['barcode']);
        $item = $this->services->create(
            HumanServiceCommandContext::resolve($user, $legalEntity),
            ServiceCreateInput::human($validated),
        );

        if ($barcode !== null) {
            $item->update(['barcode' => $barcode]);
        }

        return $item;
    }
}

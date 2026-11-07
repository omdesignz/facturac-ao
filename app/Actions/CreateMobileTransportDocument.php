<?php

namespace App\Actions;

use App\Billing\SubscriptionAccess;
use App\CatalogueItemType;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\SubscriptionRequired;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\MobileTransportDocument;
use App\Models\User;
use App\SubscriptionFeature;
use App\TransportDocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A transport guide issued on the phone, through the web's own draft and issue
 * actions. The phone says which kind, where from and to, when, the vehicle, and
 * which catalogue items in what quantity; codes, descriptions, units and values
 * are read here.
 *
 * The draft and the number are made in one transaction, so a guide either exists
 * issued or not at all, and the client key brings a retried request back to the
 * guide it already issued. The phone never leaves a draft behind.
 *
 * @phpstan-type Guide array{
 *     document_type: TransportDocumentType,
 *     establishment_public_id: string,
 *     customer_public_id: string|null,
 *     recipient: array{name: string|null, tax_identification_number: string|null},
 *     origin: array{address: string, city: string},
 *     destination: array{address: string, city: string},
 *     movement_start_at: string,
 *     movement_end_at: string|null,
 *     vehicle_registration: string|null,
 *     notes: string|null,
 *     lines: list<array{catalogue_item_public_id: string, quantity: string}>
 * }
 */
final readonly class CreateMobileTransportDocument
{
    /** Quantities on a guide are kept to three decimal places, as on the web. */
    private const int QUANTITY_SCALE = 3;

    public function __construct(
        private SaveTransportDocumentDraft $saveDraft,
        private IssueTransportDocument $issue,
    ) {}

    /**
     * What the guide would say, without writing anything.
     *
     * @param  Guide  $guide
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function preview(LegalEntity $legalEntity, array $guide): array
    {
        $profile = $this->profile($legalEntity, $guide);

        return [
            'document_type' => $guide['document_type']->value,
            'document_type_label' => $guide['document_type']->label(),
            'recipient' => [
                'name' => $profile['recipient']['name'],
                'tax_identification_number' => $profile['recipient']['tax_identification_number'],
            ],
            'origin' => ['address' => $profile['origin']['address'], 'city' => $profile['origin']['city']],
            'destination' => ['address' => $profile['destination']['address'], 'city' => $profile['destination']['city']],
            'movement_start_at' => $profile['movement_start_at'],
            'movement_end_at' => $profile['movement_end_at'],
            'vehicle_registration' => $profile['transporter']['vehicle_registration'],
            'lines' => array_map(fn (array $line): array => [
                'product_code' => $line['product_code'],
                'description' => $line['product_description'],
                'quantity' => $this->decimal($line['quantity_units'], self::QUANTITY_SCALE),
                'unit_of_measure' => $line['unit_of_measure'],
                'net_amount_minor' => $this->amount($line),
            ], $profile['lines']),
            'gross_total_minor' => array_sum(array_map(fn (array $line): int => $this->amount($line), $profile['lines'])),
            'currency_code' => $legalEntity->currency_code,
        ];
    }

    /**
     * @param  Guide  $guide
     *
     * @throws BillingActionRefused
     * @throws SubscriptionRequired
     * @throws ValidationException
     */
    public function execute(
        LegalEntity $legalEntity,
        User $user,
        string $clientKey,
        array $guide,
        int $expectedTotalMinor,
    ): MobileTransportDocument {
        app(SubscriptionAccess::class)->require($legalEntity->workspace_id, SubscriptionFeature::Transport);

        try {
            return DB::transaction(fn (): MobileTransportDocument => $this->existing($legalEntity, $clientKey)
                ?? $this->create($legalEntity, $user, $clientKey, $guide, $expectedTotalMinor));
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical requests raced past the check: the loser's whole
            // transaction, number included, is gone, and it answers with the winner's.
            return $this->existing($legalEntity, $clientKey) ?? throw $exception;
        }
    }

    /**
     * The guide a client key already issued, if any.
     */
    public function existing(LegalEntity $legalEntity, string $clientKey): ?MobileTransportDocument
    {
        $existing = MobileTransportDocument::query()
            ->with('transportDocument')
            ->where('legal_entity_id', $legalEntity->id)
            ->where('client_key', $clientKey)
            ->first();

        if ($existing instanceof MobileTransportDocument) {
            $existing->replayed = true;
        }

        return $existing;
    }

    /**
     * @param  Guide  $guide
     */
    private function create(
        LegalEntity $legalEntity,
        User $user,
        string $clientKey,
        array $guide,
        int $expectedTotalMinor,
    ): MobileTransportDocument {
        $draft = $this->saveDraft->execute($legalEntity, $user, $this->profile($legalEntity, $guide));

        // The goods are valued as the screen showed them, or the guide is not issued.
        if ($draft->gross_total_minor !== $expectedTotalMinor) {
            throw ValidationException::withMessages([
                'expected_total_minor' => 'O valor da guia mudou desde que foi apresentada. Reveja-a antes de emitir.',
            ]);
        }

        $issued = $this->issue->execute($draft, $user, $draft->revision);

        return MobileTransportDocument::query()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'transport_document_id' => $issued->id,
            'client_key' => $clientKey,
            'created_by_user_id' => $user->id,
        ]);
    }

    /**
     * The guide as {@see SaveTransportDocumentDraft} reads it, the same profile the
     * web's form produces.
     *
     * @param  Guide  $guide
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function profile(LegalEntity $legalEntity, array $guide): array
    {
        $establishment = $this->establishment($legalEntity, $guide['establishment_public_id']);
        $customer = $guide['customer_public_id'] === null ? null : $this->customer($legalEntity, $guide['customer_public_id']);
        $recipientTaxNumber = $guide['recipient']['tax_identification_number'] ?? $customer?->tax_identification_number;

        if (blank($recipientTaxNumber)) {
            throw ValidationException::withMessages([
                'recipient.tax_identification_number' => 'Indique o NIF de quem recebe.',
            ]);
        }

        $today = now($establishment->timezone)->toDateString();

        if (CarbonImmutable::parse($guide['movement_start_at'], $establishment->timezone)->toDateString() < $today) {
            throw ValidationException::withMessages([
                'movement_start_at' => 'A carga não pode ser antes de hoje: a guia é emitida hoje.',
            ]);
        }

        return [
            'document_type' => $guide['document_type']->value,
            'establishment_public_id' => $establishment->public_id,
            'customer_public_id' => $customer?->public_id,
            'movement_direction' => 'outgoing',
            // Issued today; the goods may leave now or later, never before.
            'movement_date' => $today,
            'movement_start_at' => $guide['movement_start_at'],
            'movement_end_at' => $guide['movement_end_at'],
            'recipient' => [
                'name' => $guide['recipient']['name'] ?? (string) $customer?->name,
                'tax_identification_number' => (string) $recipientTaxNumber,
                'country_code' => $customer->country_code ?? 'AO',
                'address' => filled($customer?->address_line) ? (string) $customer->address_line : $guide['destination']['address'],
                'city' => $guide['destination']['city'],
                'province' => null,
            ],
            'origin' => [...$guide['origin'], 'province' => null, 'country_code' => 'AO'],
            'destination' => [...$guide['destination'], 'province' => null, 'country_code' => 'AO'],
            'transporter' => [
                'name' => null,
                'tax_identification_number' => null,
                'vehicle_registration' => $guide['vehicle_registration'] === null
                    ? null
                    : mb_strtoupper($guide['vehicle_registration']),
            ],
            'gross_weight_grams' => null,
            'package_count' => null,
            'notes' => $guide['notes'],
            'lines' => $this->lines($legalEntity, $guide['lines']),
        ];
    }

    /**
     * @param  list<array{catalogue_item_public_id: string, quantity: string}>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function lines(LegalEntity $legalEntity, array $lines): array
    {
        $items = CatalogueItem::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_active', true)
            ->whereIn('public_id', array_column($lines, 'catalogue_item_public_id'))
            ->get()
            ->keyBy('public_id');

        return array_map(function (array $line, int $index) use ($items): array {
            $item = $items->get($line['catalogue_item_public_id']);

            if (! $item instanceof CatalogueItem) {
                throw ValidationException::withMessages([
                    "lines.{$index}.catalogue_item_public_id" => 'Este artigo já não está disponível.',
                ]);
            }

            if ($item->type === CatalogueItemType::Service) {
                throw ValidationException::withMessages([
                    "lines.{$index}.catalogue_item_public_id" => "«{$item->name}» é um serviço. Uma guia acompanha mercadorias.",
                ]);
            }

            return [
                'catalogue_item_public_id' => $item->public_id,
                'order_references' => [],
                'product_code' => $item->code,
                'product_description' => $item->name,
                'quantity_units' => $this->scaled($line['quantity'], self::QUANTITY_SCALE),
                'quantity_scale' => self::QUANTITY_SCALE,
                'unit_of_measure' => $item->unit_of_measure,
                'unit_price_minor' => $item->unit_price_minor,
            ];
        }, $lines, array_keys($lines));
    }

    /**
     * @throws ValidationException
     */
    private function establishment(LegalEntity $legalEntity, string $publicId): Establishment
    {
        return Establishment::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first()
            ?? throw ValidationException::withMessages([
                'establishment_public_id' => 'Este estabelecimento já não está disponível.',
            ]);
    }

    /**
     * @throws ValidationException
     */
    private function customer(LegalEntity $legalEntity, string $publicId): Customer
    {
        return Customer::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first()
            ?? throw ValidationException::withMessages([
                'customer_public_id' => 'Este cliente já não está disponível.',
            ]);
    }

    /**
     * The same arithmetic {@see SaveTransportDocumentDraft} applies to each line.
     *
     * @param  array<string, mixed>  $line
     */
    private function amount(array $line): int
    {
        return intdiv((int) $line['quantity_units'] * (int) $line['unit_price_minor'], 10 ** self::QUANTITY_SCALE);
    }

    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }

    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }
}

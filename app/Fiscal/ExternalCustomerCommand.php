<?php

namespace App\Fiscal;

use App\Fiscal\Agt\Support\CanonicalJson;

final class ExternalCustomerCommand
{
    public function __construct(private readonly CustomerCommands $customers, private readonly CanonicalJson $json, private readonly CommandIdempotency $ledger) {}

    public function canonical(IntegrationCommandContext $context, CustomerCreateInput $input): string
    {
        abort_unless($input->isExternal, 403);

        return $this->json->encode(['canonicalizer' => 'command-json-v1', 'command' => 'customers.create', 'version' => 1,
            'principal' => ['kind' => 'integration', 'public_id' => strtolower($context->integrationPublicId)],
            'context' => ['workspace_public_id' => strtolower($context->workspacePublicId), 'legal_entity_public_id' => strtolower($context->entityPublicId), 'environment' => $context->environment],
            'input' => array_intersect_key($input->attributes, array_flip(['name', 'tax_identification_number', 'country_code'])), 'defaults' => 'customer-create-v1']);
    }

    /** @return array{body: string, replayed: bool} */
    public function execute(IntegrationCommandContext $context, CustomerCreateInput $input, #[\SensitiveParameter] string $key): array
    {
        return CommandDatabase::bounded(fn (): array => $this->ledger->execute($context, $key,
            hash('sha256', $this->canonical($context, $input)),
            fn (string $operationId): string => $this->customers->persist($context, $input, $operationId)->public_id));
    }
}

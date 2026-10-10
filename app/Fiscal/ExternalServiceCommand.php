<?php

namespace App\Fiscal;

use App\Fiscal\Agt\Support\CanonicalJson;

final class ExternalServiceCommand
{
    public function __construct(private readonly ServiceCommands $services, private readonly CanonicalJson $json, private readonly CommandIdempotency $ledger) {}

    public function canonical(IntegrationCommandContext $context, ServiceCreateInput $input): string
    {
        abort_unless($input->isExternal, 403);

        return $this->json->encode(['canonicalizer' => 'command-json-v1', 'command' => CommandCapability::ServiceCreate->value, 'version' => 1,
            'principal' => ['kind' => 'integration', 'public_id' => strtolower($context->integrationPublicId)],
            'context' => ['workspace_public_id' => strtolower($context->workspacePublicId), 'legal_entity_public_id' => strtolower($context->entityPublicId), 'environment' => $context->environment],
            'input' => $input->attributes, 'defaults' => 'catalogue-service-create-v1']);
    }

    /** @return array{body: string, replayed: bool} */
    public function execute(IntegrationCommandContext $context, ServiceCreateInput $input, #[\SensitiveParameter] string $key): array
    {
        return CommandDatabase::bounded(function () use ($context, $input, $key): array {
            $context->authorize(capability: CommandCapability::ServiceCreate);

            return $this->ledger->execute($context, $key,
                hash('sha256', $this->canonical($context, $input)),
                fn (string $operationId): string => $this->services->persist($context, $input, $operationId)->public_id, CommandCapability::ServiceCreate);
        });
    }
}

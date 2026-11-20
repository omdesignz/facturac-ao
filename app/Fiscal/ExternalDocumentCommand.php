<?php

namespace App\Fiscal;

use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\SubscriptionRequired;
use App\Fiscal\Agt\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExternalDocumentCommand
{
    public function __construct(private readonly DocumentCommands $documents, private readonly CanonicalJson $json, private readonly CommandIdempotency $ledger) {}

    public function canonical(IntegrationCommandContext $context, DocumentIssueInput $input): string
    {
        return $this->json->encode(['canonicalizer' => 'command-json-v1', 'command' => CommandCapability::DocumentIssue->value, 'version' => 1,
            'principal' => ['kind' => 'integration', 'public_id' => strtolower($context->integrationPublicId)],
            'context' => ['workspace_public_id' => strtolower($context->workspacePublicId), 'legal_entity_public_id' => strtolower($context->entityPublicId), 'environment' => $context->environment],
            'input' => $input->attributes, 'defaults' => 'document-issue-v1']);
    }

    /**
     * Issues once per idempotency key. A refusal rolls everything back, the
     * fiscal number included, so the same key may be sent again once the
     * cause is fixed.
     *
     * @return array{body: string, replayed: bool}
     */
    public function execute(IntegrationCommandContext $context, DocumentIssueInput $input, #[\SensitiveParameter] string $key): array
    {
        try {
            return CommandDatabase::bounded(function () use ($context, $input, $key): array {
                $context->authorize(capability: CommandCapability::DocumentIssue);

                return $this->ledger->execute($context, $key,
                    hash('sha256', $this->canonical($context, $input)),
                    fn (string $operationId): string => $this->documents->issue($context, $input, $operationId)->public_id, CommandCapability::DocumentIssue);
            });
        } catch (ValidationException $exception) {
            throw CommandRefused::invalid(array_map(fn (array $messages): string => (string) $messages[0], $exception->errors()));
        } catch (FiscalFinalizationBlocked|BillingActionRefused $exception) {
            throw new CommandRefused(409, 'DOCUMENT_BLOCKED', $exception->getMessage());
        } catch (SubscriptionRequired) {
            throw new HttpException(403);
        }
    }
}

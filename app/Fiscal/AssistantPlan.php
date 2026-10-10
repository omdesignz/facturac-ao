<?php

namespace App\Fiscal;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class AssistantPlan
{
    public const PERMISSIONS = ['searchCustomers' => 'customers.read', 'getCustomer' => 'customers.read',
        'getFiscalDocumentSummary' => 'documents.read', 'getQualifiedAgtStatus' => 'documents.agt-status.read', 'getMonthlyRecordedBilling' => 'analytics.billing.read'];

    /** @param list<array{tool: string, arguments: array<string, string>}> $calls */
    private function __construct(public string $decision, public ?string $reason, public array $calls) {}

    /** @param list<string> $permissions */
    public static function fromJson(#[\SensitiveParameter] string $json, AssistantInput $input, array $permissions): self
    {
        $data = AssistantJson::object($json, 4096, 503);
        if (in_array($data['decision'] ?? null, ['clarify', 'unsupported'], true)) {
            AssistantJson::keys($data, ['decision', 'reason'], 503);
            $reasons = $data['decision'] === 'clarify' ? ['select_customer', 'select_document', 'specify_month', 'refine_customer_search'] : ['outside_read_contract'];
            abort_unless(in_array($data['reason'], $reasons, true), 503);

            return new self($data['decision'], $data['reason'], []);
        }
        AssistantJson::keys($data, ['decision', 'calls'], 503);
        abort_unless($data['decision'] === 'read' && is_array($data['calls']) && count($data['calls']) >= 1 && count($data['calls']) <= 4, 503);
        $calls = [];
        $searches = $billing = $details = 0;
        foreach ($data['calls'] as $raw) {
            abort_unless($raw instanceof \stdClass, 503);
            $call = get_object_vars($raw);
            AssistantJson::keys($call, ['tool', 'arguments'], 503);
            abort_unless(is_string($call['tool']) && isset(self::PERMISSIONS[$call['tool']])
                && in_array(self::PERMISSIONS[$call['tool']], $permissions, true) && $call['arguments'] instanceof \stdClass, 503);
            $arguments = get_object_vars($call['arguments']);
            if ($call['tool'] === 'searchCustomers') {
                AssistantJson::keys($arguments, ['q', 'status'], 503);
                try {
                    $command = CustomerSearchCommand::fromInput($arguments['q'], $arguments['status']);
                } catch (HttpExceptionInterface) {
                    abort(503);
                }
                $arguments = ['q' => $command->search, 'status' => $command->status];
                $searches++;
            } elseif ($call['tool'] === 'getMonthlyRecordedBilling') {
                AssistantJson::keys($arguments, ['month'], 503);
                abort_unless(is_string($arguments['month']), 503);
                try {
                    BillingSummaryCommand::fromMonth($arguments['month']);
                } catch (HttpExceptionInterface) {
                    abort(503);
                }
                $billing++;
            } else {
                AssistantJson::keys($arguments, ['public_id'], 503);
                $arguments = ['public_id' => AssistantJson::publicId($arguments['public_id'], 503)];
                $kind = $call['tool'] === 'getCustomer' ? 'customer' : 'document';
                abort_unless(in_array(['kind' => $kind, 'public_id' => $arguments['public_id']], $input->references, true), 503);
                $details++;
            }
            $call = ['tool' => $call['tool'], 'arguments' => $arguments];
            abort_if(in_array($call, $calls, true), 503);
            $calls[] = $call;
        }
        abort_if($searches > 1 || $billing > 1 || $details > 2, 503);

        return new self('read', null, $calls);
    }

    /** @param list<string> $permissions
     * @return array{question: string, references: list<array{kind: string, public_id: string}>, current_month: string, schema_version: int, tools: array<string, array<string, mixed>>}
     */
    public static function plannerInput(AssistantInput $input, array $permissions): array
    {
        $tools = [];
        foreach (self::PERMISSIONS as $tool => $permission) {
            if (in_array($permission, $permissions, true)) {
                $properties = match ($tool) {
                    'searchCustomers' => ['q' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80], 'status' => ['enum' => ['active', 'inactive', 'all']]],
                    'getMonthlyRecordedBilling' => ['month' => ['type' => 'string', 'pattern' => '^[2-9][0-9]{3}-(0[1-9]|1[0-2])$']],
                    default => ['public_id' => ['type' => 'string', 'pattern' => '^[0-7][0-9a-hjkmnp-tv-z]{25}$']],
                };
                $tools[$tool] = ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
            }
        }

        return ['question' => $input->question, 'references' => $input->references, 'current_month' => CarbonImmutable::now('Africa/Luanda')->format('Y-m'), 'schema_version' => 1, 'tools' => $tools];
    }
}

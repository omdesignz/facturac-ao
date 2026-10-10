<?php

namespace App\Fiscal;

/** The only model-visible projection; it accepts no records or tool results. */
final readonly class ProviderIntentInput implements \JsonSerializable
{
    /** @param array<string, mixed> $payload */
    private function __construct(private array $payload, private AssistantInput $original) {}

    /** @param list<string> $permissions */
    public static function fromInput(AssistantInput $input, array $permissions): self
    {
        $question = $input->providerQuestion();
        $local = AssistantPlan::plannerInput($input, $permissions);
        $references = [];
        foreach ($input->references as $index => $reference) {
            $references[] = ['kind' => $reference['kind'], 'ref' => 'r'.($index + 1)];
        }
        $tools = $local['tools'];
        foreach ($tools as $tool => &$schema) {
            if (! in_array($tool, ['searchCustomers', 'getMonthlyRecordedBilling'], true)) {
                $schema['required'] = ['ref'];
                $schema['properties'] = ['ref' => ['type' => 'string', 'enum' => ['r1', 'r2']]];
            }
        }
        unset($schema);
        abort_if($tools === [], 503);

        return new self(['schema_version' => 'intent-provider-v1', 'question' => $question,
            'references' => $references, 'current_month' => $local['current_month'], 'tools' => $tools], $input);
    }

    public function json(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $permissions */
    public function localPlan(#[\SensitiveParameter] string $json, array $permissions): string
    {
        $data = AssistantJson::object($json, 4096, 503);
        if (($data['decision'] ?? null) === 'read') {
            AssistantJson::keys($data, ['decision', 'calls'], 503);
            abort_unless(is_array($data['calls']) && count($data['calls']) >= 1 && count($data['calls']) <= 4, 503);
            $seen = [];
            foreach ($data['calls'] as $call) {
                abort_unless($call instanceof \stdClass, 503);
                AssistantJson::keys(get_object_vars($call), ['tool', 'arguments'], 503);
                abort_unless(is_string($call->tool) && isset($this->payload['tools'][$call->tool]) && $call->arguments instanceof \stdClass, 503);
                if (in_array($call->tool, ['searchCustomers', 'getMonthlyRecordedBilling'], true)) {
                    continue;
                }
                AssistantJson::keys(get_object_vars($call->arguments), ['ref'], 503);
                $alias = $call->arguments->ref;
                abort_unless(is_string($alias) && in_array($alias, ['r1', 'r2'], true) && ! in_array($alias, $seen, true), 503);
                $reference = $this->original->references[$alias === 'r1' ? 0 : 1] ?? null;
                $kind = $call->tool === 'getCustomer' ? 'customer' : 'document';
                abort_unless($reference !== null && $reference['kind'] === $kind, 503);
                $seen[] = $alias;
                $call->arguments = (object) ['public_id' => $reference['public_id']];
            }
        }
        $translated = json_encode($data, JSON_THROW_ON_ERROR);
        AssistantPlan::fromJson($translated, $this->original, $permissions);

        return $translated;
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Provider input cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Provider input cannot be serialized.');
    }
}

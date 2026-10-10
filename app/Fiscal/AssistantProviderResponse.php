<?php

namespace App\Fiscal;

/** Original envelope validation occurs before SDK parsing can normalize or default fields. */
final readonly class AssistantProviderResponse
{
    /** @param array{input: int, output: int} $usage */
    private function __construct(public string $plan, public array $usage) {}

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        $data = AssistantJson::object($json, 65536, 503);
        AssistantJson::keys($data, ['id', 'type', 'role', 'model', 'content', 'stop_reason', 'stop_sequence', 'usage'], 503);
        abort_unless(is_string($data['id']) && strlen($data['id']) <= 256 && $data['type'] === 'message'
            && $data['role'] === 'assistant' && $data['model'] === AssistantProviderProfile::MODEL
            && $data['stop_reason'] === 'end_turn' && $data['stop_sequence'] === null
            && is_array($data['content']) && count($data['content']) === 1 && $data['content'][0] instanceof \stdClass, 503);
        $block = get_object_vars($data['content'][0]);
        AssistantJson::keys($block, ['type', 'text'], 503);
        abort_unless($block['type'] === 'text' && is_string($block['text']), 503);
        abort_if(strlen($block['text']) > 4096, 503);
        abort_unless($data['usage'] instanceof \stdClass, 503);
        $usage = get_object_vars($data['usage']);
        abort_if(array_diff(array_keys($usage), ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'cache_creation', 'server_tool_use', 'service_tier', 'inference_geo']) !== [], 503);
        abort_unless(isset($usage['input_tokens'], $usage['output_tokens']) && is_int($usage['input_tokens']) && is_int($usage['output_tokens'])
            && $usage['input_tokens'] >= 0 && $usage['input_tokens'] <= 8192 && $usage['output_tokens'] >= 0 && $usage['output_tokens'] <= 1024
            && ($usage['inference_geo'] ?? null) === 'us' && ($usage['service_tier'] ?? null) === 'standard', 503);
        foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $field) {
            abort_if(array_key_exists($field, $usage) && $usage[$field] !== 0, 503);
        }
        foreach (['cache_creation' => ['ephemeral_5m_input_tokens', 'ephemeral_1h_input_tokens'], 'server_tool_use' => ['web_search_requests', 'web_fetch_requests']] as $field => $allowed) {
            if (array_key_exists($field, $usage)) {
                abort_unless($usage[$field] instanceof \stdClass, 503);
                $counts = get_object_vars($usage[$field]);
                abort_if(array_diff(array_keys($counts), $allowed) !== [], 503);
                foreach ($counts as $count) {
                    abort_unless($count === 0, 503);
                }
            }
        }

        return new self($block['text'], ['input' => $usage['input_tokens'], 'output' => $usage['output_tokens']]);
    }
}

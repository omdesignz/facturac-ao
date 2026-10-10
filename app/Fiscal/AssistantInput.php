<?php

namespace App\Fiscal;

use Illuminate\Support\Str;

final readonly class AssistantInput
{
    /** @param list<array{kind: string, public_id: string}> $references */
    private function __construct(public string $nonce, public string $question, public array $references,
        #[\SensitiveParameter] private string $originalQuestion) {}

    public function providerQuestion(): string
    {
        return AssistantDisclosure::question($this->originalQuestion, $this->references);
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        $data = AssistantJson::object($json, 12288, 422);
        AssistantJson::keys($data, ['request_nonce', 'question', 'references'], 422);
        abort_unless(is_string($data['request_nonce']) && Str::isUuid($data['request_nonce']), 422);
        abort_unless(is_string($data['question']), 422);
        abort_if(preg_match('/(?![\t\n])\p{Cc}/u', $data['question']) === 1, 422);
        $question = trim($data['question']);
        abort_if(mb_strlen($question) < 1 || mb_strlen($question) > 2000 || strlen($question) > 8192
            || preg_match('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', $question) === 1, 422);
        abort_unless(is_array($data['references']) && count($data['references']) <= 2, 422);
        $references = [];
        foreach ($data['references'] as $reference) {
            abort_unless($reference instanceof \stdClass, 422);
            $reference = get_object_vars($reference);
            AssistantJson::keys($reference, ['kind', 'public_id'], 422);
            abort_unless(in_array($reference['kind'], ['customer', 'document'], true), 422);
            $reference = ['kind' => $reference['kind'], 'public_id' => AssistantJson::publicId($reference['public_id'], 422)];
            abort_if(in_array($reference, $references, true), 422);
            $references[] = $reference;
        }

        return new self(strtolower($data['request_nonce']), $question, $references, $data['question']);
    }
}

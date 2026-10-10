<?php

use App\Fiscal\AssistantInput;
use App\Fiscal\AssistantIntentGateway;
use App\Fiscal\AssistantPlan;
use App\Fiscal\AssistantProviderLedger;
use App\Fiscal\AssistantProviderTransport;
use App\Fiscal\ProviderIntentInput;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function providerInputFixture(array $references = []): AssistantInput
{
    return AssistantInput::fromJson(json_encode(['request_nonce' => (string) Str::uuid(), 'question' => 'Estado deste documento', 'references' => $references], JSON_THROW_ON_ERROR));
}

test('provider input allowlists fields and replaces references without querying records', function () {
    $id = '01arz3ndektsv4rrffq69g5fav';
    $dto = ProviderIntentInput::fromInput(providerInputFixture([['kind' => 'document', 'public_id' => $id]]), array_values(AssistantPlan::PERMISSIONS));
    $json = $dto->json();
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($data))->toBe(['schema_version', 'question', 'references', 'current_month', 'tools'])
        ->and($json)->not->toContain($id, 'public_id', 'workspace', 'actor', 'permission')
        ->and($data['references'])->toBe([['kind' => 'document', 'ref' => 'r1']])
        ->and(array_keys($data['tools']))->toBe(array_keys(AssistantPlan::PERMISSIONS));
    expect(json_decode($dto->localPlan('{"decision":"read","calls":[{"tool":"getQualifiedAgtStatus","arguments":{"ref":"r1"}}]}', array_values(AssistantPlan::PERMISSIONS)), true)['calls'][0]['arguments'])->toBe(['public_id' => $id]);
});

test('provider input never enlarges advertised permissions', function () {
    $dto = ProviderIntentInput::fromInput(providerInputFixture(), ['customers.read']);
    expect(array_keys(json_decode($dto->json(), true)['tools']))->toBe(['searchCustomers', 'getCustomer']);
    expect(fn () => ProviderIntentInput::fromInput(providerInputFixture(), []))->toThrow(HttpException::class);
});

test('provider output rejects untrusted plans before local execution', function (string $json) {
    $dto = ProviderIntentInput::fromInput(providerInputFixture([['kind' => 'document', 'public_id' => '01arz3ndektsv4rrffq69g5fav']]), array_values(AssistantPlan::PERMISSIONS));
    expect(fn () => $dto->localPlan($json, array_values(AssistantPlan::PERMISSIONS)))->toThrow(HttpException::class);
})->with([
    '{"decision":"read","calls":[{"tool":"getCustomer","arguments":{"ref":"r1"}}]}',
    '{"decision":"read","calls":[{"tool":"getQualifiedAgtStatus","arguments":{"ref":"r2"}}]}',
    '{"decision":"read","calls":[{"tool":"getQualifiedAgtStatus","arguments":{"ref":"r1","workspace_id":17}}]}',
    '{"decision":"read","calls":[{"tool":"getQualifiedAgtStatus","arguments":{"public_id":"01arz3ndektsv4rrffq69g5fav"}}]}',
    '{"decision":"read","calls":[{"tool":"getQualifiedAgtStatus","arguments":{"ref":"r1"}},{"tool":"getFiscalDocumentSummary","arguments":{"ref":"r1"}}]}',
    '{"decision":"read","calls":[]}',
    '{"decision":"unsupported","decision":"clarify","reason":"specify_month"}',
    '{"decision":"unsupported","reason":"outside_read_contract","text":"secret"}',
    '{"decision":"read","calls":[{"tool":"arbitrarySql","arguments":{}}]}',
]);

test('provider abstention remains a closed deterministic local decision', function () {
    $dto = ProviderIntentInput::fromInput(providerInputFixture(), ['customers.read']);
    expect($dto->localPlan('{"decision":"clarify","reason":"select_customer"}', ['customers.read']))->toBe('{"decision":"clarify","reason":"select_customer"}');
});

test('installed private SDK schema fits the sealed body and forces the approved options', function () {
    $dto = ProviderIntentInput::fromInput(providerInputFixture([['kind' => 'document', 'public_id' => '01arz3ndektsv4rrffq69g5fav']]), array_values(AssistantPlan::PERMISSIONS));
    $gateway = new AssistantIntentGateway(new AssistantProviderTransport, new AssistantProviderLedger);
    $body = $gateway->prepare($dto);
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect(strlen($body))->toBeLessThanOrEqual(7168)
        ->and(array_keys($data))->toBe(['model', 'messages', 'max_tokens', 'system', 'output_config', 'thinking', 'inference_geo'])
        ->and($data['model'])->toBe('claude-haiku-5-5')
        ->and($data['max_tokens'])->toBe(1024)
        ->and($data['thinking'])->toBe(['type' => 'disabled'])
        ->and($data['output_config']['effort'])->toBe('low')
        ->and($data['inference_geo'])->toBe('us')
        ->and($data['output_config']['format']['type'])->toBe('json_schema')
        ->and($data['messages'])->toHaveCount(1);
});

test('byte admission covers the actual SDK framing at minus one exact and plus one', function () {
    $gateway = new AssistantIntentGateway(new AssistantProviderTransport, new AssistantProviderLedger);
    $make = function (string $question): ProviderIntentInput {
        $input = AssistantInput::fromJson(json_encode(['request_nonce' => (string) Str::uuid(), 'question' => $question, 'references' => []], JSON_THROW_ON_ERROR));

        return ProviderIntentInput::fromInput($input, array_values(AssistantPlan::PERMISSIONS));
    };
    $framing = strlen($gateway->prepare($make('a'))) - 1;
    $encodedCharacterBytes = strlen($gateway->prepare($make('é'))) - $framing;
    foreach ([7167, 7168] as $total) {
        $questionBytes = $total - $framing;
        $question = str_repeat('é', intdiv($questionBytes, $encodedCharacterBytes)).str_repeat('a', $questionBytes % $encodedCharacterBytes);
        expect(strlen($gateway->prepare($make($question))))->toBe($total);
    }
    $questionBytes = 7169 - $framing;
    $question = str_repeat('é', intdiv($questionBytes, $encodedCharacterBytes)).str_repeat('a', $questionBytes % $encodedCharacterBytes);
    expect(fn () => $gateway->prepare($make($question)))->toThrow(HttpException::class);
});

test('Portuguese evaluation vectors admit only reviewed deterministic plans and abstention', function (string $question, array $expected) {
    $references = [['kind' => 'customer', 'public_id' => '01arz3ndektsv4rrffq69g5fav'],
        ['kind' => 'document', 'public_id' => '01arz3ndektsv4rrffq69g5faw']];
    $input = AssistantInput::fromJson(json_encode(['request_nonce' => (string) Str::uuid(), 'question' => $question, 'references' => $references], JSON_THROW_ON_ERROR));
    $dto = ProviderIntentInput::fromInput($input, array_values(AssistantPlan::PERMISSIONS));
    expect(json_decode($dto->json(), true)['question'])->toBe($question);
    $local = json_decode($dto->localPlan(json_encode($expected), array_values(AssistantPlan::PERMISSIONS)), true);
    expect($local['decision'])->toBe($expected['decision']);
    if ($expected['decision'] === 'read') {
        expect($local['calls'][0]['tool'])->toBe($expected['calls'][0]['tool']);
    } else {
        expect($local['reason'])->toBe($expected['reason']);
    }
})->with([
    ['Pesquisar clientes Acácia', ['decision' => 'read', 'calls' => [['tool' => 'searchCustomers', 'arguments' => ['q' => 'Acácia', 'status' => 'active']]]]],
    ['Mostrar o cliente seleccionado', ['decision' => 'read', 'calls' => [['tool' => 'getCustomer', 'arguments' => ['ref' => 'r1']]]]],
    ['Resumir o documento seleccionado', ['decision' => 'read', 'calls' => [['tool' => 'getFiscalDocumentSummary', 'arguments' => ['ref' => 'r2']]]]],
    ['Qual o estado AGT do documento seleccionado', ['decision' => 'read', 'calls' => [['tool' => 'getQualifiedAgtStatus', 'arguments' => ['ref' => 'r2']]]]],
    ['Facturação registada de 2026-10', ['decision' => 'read', 'calls' => [['tool' => 'getMonthlyRecordedBilling', 'arguments' => ['month' => '2026-10']]]]],
    ['Qual cliente devo escolher', ['decision' => 'clarify', 'reason' => 'select_customer']],
    ['Mostrar a facturação', ['decision' => 'clarify', 'reason' => 'specify_month']],
    ['Emitir uma factura', ['decision' => 'unsupported', 'reason' => 'outside_read_contract']],
]);

test('inherited Phase 6 input normalization remains unchanged for inputs rejected by Phase 7', function (string $question) {
    $input = AssistantInput::fromJson(json_encode(['request_nonce' => (string) Str::uuid(), 'question' => $question, 'references' => []], JSON_THROW_ON_ERROR));
    expect($input->question)->toBe('Pesquisar Acacia');
    expect(fn () => ProviderIntentInput::fromInput($input, ['customers.read']))->toThrow(HttpException::class);
})->with(["\tPesquisar Acacia\t", str_repeat(' ', 8193).'Pesquisar Acacia', str_repeat(' ', 2001).'Pesquisar Acacia']);

<?php

use App\Fiscal\AssistantDisclosure;
use App\Fiscal\AssistantProviderProfile;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('provider sealed pricing reserves the full envelope without float arithmetic', function () {
    expect(AssistantProviderProfile::reservation())->toBe(552816)
        ->and(AssistantProviderProfile::actualCharge(1000, 100))->toBe(165)
        ->and(AssistantProviderProfile::actualCharge(100001, 1024))->toBe(57817);
});

test('provider admission counts complete bytes at the estimate boundary', function () {
    expect(AssistantProviderProfile::estimate(str_repeat('a', 7167)))->toBe(8191)
        ->and(AssistantProviderProfile::estimate(str_repeat('a', 7168)))->toBe(8192);
    expect(fn () => AssistantProviderProfile::estimate(str_repeat('a', 7169)))->toThrow(HttpException::class);
});

test('provider admission measures Unicode bytes and rejects cost overflow inputs', function () {
    expect(AssistantProviderProfile::estimate(str_repeat('é', 3584)))->toBe(8192);
    expect(fn () => AssistantProviderProfile::estimate(str_repeat('é', 3584).'a'))->toThrow(HttpException::class);
    expect(fn () => AssistantProviderProfile::actualCharge(PHP_INT_MAX, 1))->toThrow(HttpException::class);
    expect(fn () => AssistantProviderProfile::actualCharge(1, -1))->toThrow(HttpException::class);
});

test('provider profile expires at the exact sealed instant', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06T23:59:59Z'));
    expect(AssistantProviderProfile::valid())->toBeTrue();
    $this->travelTo(CarbonImmutable::parse(AssistantProviderProfile::EXPIRES));
    expect(AssistantProviderProfile::valid())->toBeFalse();
    $this->travelBack();
});

test('disclosure allows only deliberate names and ordinary requests', function (string $question) {
    expect(AssistantDisclosure::question($question, []))->toBe($question);
})->with(['Pesquisar Cliente Consulta', 'Facturação de 2026-10', 'Estado AGT deste documento', 'Empresa Acácia', 'Resumo de 2026']);

test('disclosure blocks sensitive indicators without constructing a request', function (string $question) {
    expect(fn () => AssistantDisclosure::question($question, []))->toThrow(HttpException::class);
})->with(['NIF 123456789', 'FT ABC/100', 'cem USD', 'email@example.test', 'ana [at] teste [dot] ao', '+244 923 123 456', 'Rua das Flores', 'ENDEREÇO secreto', 'api_key qualquer', 'sk-test', 'https://example.test', '{"agt_payload":{}}', 'workspace 17', 'valor ١٢٣', "Cliente\u{202e}nome", "Cliente\u{200b}nome", '2026-13', '0000-01', '2026-10-01', 'r1', '00000000-0000-0000-0000-000000000000']);

test('disclosure aliases admitted ULIDs but not user forged alias tokens', function () {
    $id = '01arz3ndektsv4rrffq69g5fav';
    expect(AssistantDisclosure::question(strtoupper($id).' e '.$id, [['kind' => 'document', 'public_id' => $id]]))->toBe('r1 e r1');
    expect(fn () => AssistantDisclosure::question($id, []))->toThrow(HttpException::class);
    expect(fn () => AssistantDisclosure::question('r1 '.$id, [['kind' => 'document', 'public_id' => $id]]))->toThrow(HttpException::class);
});

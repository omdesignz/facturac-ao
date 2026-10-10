<?php

use App\Fiscal\AssistantProviderResponse;
use App\Fiscal\AssistantProviderResponseBuffer;
use App\Fiscal\AssistantProviderTransport;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../AssistantProviderFixtures.php';

test('original provider envelope validates explicit usage without SDK zero defaults', function () {
    $response = AssistantProviderResponse::fromJson(providerEnvelope());
    expect($response->usage)->toBe(['input' => 10, 'output' => 5]);
    $data = json_decode(providerEnvelope(), true);
    $data['usage']['input_tokens'] = 0;
    $data['usage']['output_tokens'] = 0;
    expect(AssistantProviderResponse::fromJson(json_encode($data))->usage)->toBe(['input' => 0, 'output' => 0]);
});

test('untrusted original envelope rejects malformed identity content and billing categories', function (Closure $mutate) {
    $data = json_decode(providerEnvelope(), true);
    $mutate($data);
    expect(fn () => AssistantProviderResponse::fromJson(json_encode($data)))->toThrow(HttpException::class);
})->with([
    'missing usage' => function (&$data) {
        unset($data['usage']);
    },
    'missing input' => function (&$data) {
        unset($data['usage']['input_tokens']);
    },
    'negative input' => function (&$data) {
        $data['usage']['input_tokens'] = -1;
    },
    'float input' => function (&$data) {
        $data['usage']['input_tokens'] = 1.5;
    },
    'string input' => function (&$data) {
        $data['usage']['input_tokens'] = '10';
    },
    'overflow input' => function (&$data) {
        $data['usage']['input_tokens'] = PHP_INT_MAX;
    },
    'above estimate' => function (&$data) {
        $data['usage']['input_tokens'] = 8193;
    },
    'excess output' => function (&$data) {
        $data['usage']['output_tokens'] = 1025;
    },
    'wrong geography' => function (&$data) {
        $data['usage']['inference_geo'] = 'global';
    },
    'missing geography' => function (&$data) {
        unset($data['usage']['inference_geo']);
    },
    'wrong tier' => function (&$data) {
        $data['usage']['service_tier'] = 'priority';
    },
    'unknown billed category' => function (&$data) {
        $data['usage']['new_charge'] = 1;
    },
    'cache usage' => function (&$data) {
        $data['usage']['cache_read_input_tokens'] = 1;
    },
    'server tool' => function (&$data) {
        $data['usage']['server_tool_use'] = ['web_search_requests' => 1];
    },
    'wrong model' => function (&$data) {
        $data['model'] = 'latest';
    },
    'refusal' => function (&$data) {
        $data['stop_reason'] = 'refusal';
    },
    'truncation' => function (&$data) {
        $data['stop_reason'] = 'max_tokens';
    },
    'continuation' => function (&$data) {
        $data['stop_reason'] = 'pause_turn';
    },
    'extra block' => function (&$data) {
        $data['content'][] = ['type' => 'text', 'text' => 'secret'];
    },
    'thinking' => function (&$data) {
        $data['content'][0]['type'] = 'thinking';
    },
    'citation' => function (&$data) {
        $data['content'][0]['citations'] = [];
    },
    'arbitrary extra field' => function (&$data) {
        $data['workspace_id'] = 17;
    },
    'oversize plan' => function (&$data) {
        $data['content'][0]['text'] = str_repeat('x', 4097);
    },
]);

test('envelope duplicate keys cannot disappear through SDK decoding', function () {
    $raw = str_replace('"type":"message"', '"type":"message","type":"message"', providerEnvelope());
    expect(fn () => AssistantProviderResponse::fromJson($raw))->toThrow(HttpException::class);
});

test('transport enforces header and chunk limits before buffering excess', function () {
    $buffer = new AssistantProviderResponseBuffer(hrtime(true) / 1e9 + 2);
    foreach (["HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n"] as $line) {
        expect($buffer->header($line))->toBe(strlen($line));
    }
    expect($buffer->chunk(str_repeat('a', 65535)))->toBe(65535)
        ->and($buffer->chunk('b'))->toBe(1)->and($buffer->chunk('c'))->toBe(0);
    $headers = new AssistantProviderResponseBuffer(hrtime(true) / 1e9 + 2);
    expect($headers->header(str_repeat('a', 16384)))->toBe(16384)->and($headers->header('b'))->toBe(0);
});

test('transport refuses redirects encodings misleading lengths and non-json media', function (string $line) {
    $buffer = new AssistantProviderResponseBuffer(hrtime(true) / 1e9 + 2);
    expect($buffer->header($line))->toBe(0);
})->with(["HTTP/1.1 302 Redirect\r\n", "HTTP/1.1 500 Error\r\n", "Content-Encoding: gzip\r\n", "Content-Length: 65537\r\n", "Content-Length: -1\r\n", "Content-Type: text/html\r\n"]);

test('expired monotonic deadline rejects headers chunks and completion', function () {
    $buffer = new AssistantProviderResponseBuffer(hrtime(true) / 1e9 - 0.001);
    expect($buffer->header("HTTP/1.1 200 OK\r\n"))->toBe(0)->and($buffer->chunk('x'))->toBe(0);
    expect(fn () => $buffer->finish())->toThrow(HttpException::class);
});

test('DNS policy rejects private reserved shared and multicast destinations', function (string $address) {
    expect(AssistantProviderTransport::publicAddress($address))->toBeFalse();
})->with(['127.0.0.1', '10.1.2.3', '172.31.1.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.254', '192.0.2.1', '224.0.0.1', '255.255.255.255', '::1', 'fe80::1', 'fc00::1', 'ff02::1', '::ffff:8.8.8.8', '2001:db8::1', 'not-an-address']);

test('DNS policy permits public unicast only', function () {
    expect(AssistantProviderTransport::publicAddress('8.8.8.8'))->toBeTrue()
        ->and(AssistantProviderTransport::publicAddress('2606:4700::1111'))->toBeTrue();
});

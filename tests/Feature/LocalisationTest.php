<?php

use App\Models\User;

test('the application runs in Angolan Portuguese', function () {
    expect(config('app.locale'))->toBe('pt_AO')
        ->and(config('app.fallback_locale'))->toBe('pt');
});

test('validation messages resolve to Portuguese rather than raw keys', function () {
    $validator = validator(
        ['legal_name' => ''],
        ['legal_name' => ['required', 'string']],
    );

    $message = $validator->errors()->first('legal_name');

    expect($message)->not->toStartWith('validation.')
        ->and($message)->toBe('O campo denominação social é obrigatório.');
});

test('field names are shown to the user in Portuguese, not as column names', function () {
    $validator = validator(
        ['tax_identification_number' => '', 'due_date' => ''],
        [
            'tax_identification_number' => ['required'],
            'due_date' => ['required'],
        ],
    );

    expect($validator->errors()->first('tax_identification_number'))->toContain('NIF')
        ->and($validator->errors()->first('due_date'))->toContain('data de vencimento');
});

test('the fiscal date rules explain themselves instead of restating the rule name', function () {
    $validator = validator(
        ['document_date' => '2099-01-01'],
        ['document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
    );

    expect($validator->errors()->first('document_date'))
        ->toBe('A data do documento não pode ser no futuro.');
});

test('authentication failures are translated', function () {
    expect(trans('auth.failed'))->toBe('Estes dados não coincidem com os nossos registos.')
        ->and(trans('passwords.sent'))->not->toStartWith('passwords.');
});

test('a rejected invoice date reaches the browser already translated', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->post(route('invoices.store'), ['document_date' => '2099-01-01'])
        ->assertSessionHasErrors([
            'document_date' => 'A data do documento não pode ser no futuro.',
        ]);
});

test('translations use the pre-1990 spelling Angola still follows', function () {
    $lines = require lang_path('pt/validation.php');
    $blob = json_encode($lines, JSON_UNESCAPED_UNICODE);

    // Angola never ratified the Acordo Ortográfico de 1990, so `selecção`
    // keeps its second c and `activo`/`correcto` keep theirs.
    expect($blob)->toContain('seleccionado')
        ->and($blob)->toContain('incorrecta')
        ->and($blob)->not->toContain('selecionado')
        ->and($blob)->not->toContain('incorreta')
        ->and($blob)->not->toContain('ação obrigat');
});

<?php

use App\Analytics\WhatsAppNumber;

test('an angolan mobile becomes the number WhatsApp opens', function (string $typed) {
    expect(WhatsAppNumber::from($typed))->toBe('244923456789');
})->with([
    'local' => '923456789',
    'spaced' => '923 456 789',
    'with plus' => '+244 923 456 789',
    'with zeros' => '00244923456789',
    'without plus' => '244923456789',
]);

test('a foreign number counts only when written in international form', function () {
    expect(WhatsAppNumber::from('+351 912 345 678'))->toBe('351912345678')
        ->and(WhatsAppNumber::from('0033 6 12 34 56 78'))->toBe('33612345678')
        ->and(WhatsAppNumber::from('912 345 678 0'))->toBeNull();
});

test('a landline, a short number or nothing gives no chat to open', function (?string $typed) {
    expect(WhatsAppNumber::from($typed))->toBeNull();
})->with([
    'nothing' => null,
    'blank' => '  ',
    'landline' => '222 123 456',
    'angolan landline in full' => '+244 222 123 456',
    'too short' => '92345',
]);

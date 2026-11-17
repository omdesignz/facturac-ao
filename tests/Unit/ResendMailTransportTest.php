<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class);

test('the cloud mail configuration resolves the Resend transport', function () {
    config([
        'mail.default' => 'resend',
        'services.resend.key' => 're_synthetic_test_key',
    ]);

    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});

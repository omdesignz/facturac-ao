<?php

test('the retired simulation gateway cannot be instantiated or activate subscriptions', function () {
    expect(class_exists('App\\Billing\\Gateways\\SimulatedPay4AllGateway'))->toBeFalse()
        ->and(class_exists('App\\Actions\\ConfirmEmisPayment'))->toBeFalse()
        ->and(interface_exists('App\\Billing\\Contracts\\EmisPaymentGateway'))->toBeFalse();
});

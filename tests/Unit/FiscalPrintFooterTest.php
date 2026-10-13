<?php

use App\Fiscal\Documents\FiscalPrintFooter;
use App\FiscalDocumentType;
use Carbon\CarbonImmutable;

test('the AGT excerpt preserves characters at positions 1 11 21 and 31', function () {
    expect((new FiscalPrintFooter)->signatureExcerpt('A123456789b123456789C123456789d123456789'))->toBe('AbCd');
});

test('the receipt notice has no signature prefix and preserves the assigned certificate verbatim', function () {
    expect((new FiscalPrintFooter)->text(FiscalDocumentType::Receipt, '264/AGT/2020', str_repeat('x', 40)))
        ->toBe('Emitido por programa validado n.º 264/AGT/2020 / facturac.ao');
});

test('invoice and factura recibo use the requested operation date precision', function (FiscalDocumentType $type, string $date) {
    expect((new FiscalPrintFooter)->text($type, 'EXEMPLO/AGT', str_repeat('x', 40), CarbonImmutable::parse('2026-10-13 10:30:00')))
        ->toBe('xxxx-Processado por programa validado n.º EXEMPLO/AGT | Os bens e/ou serviços foram colocados à disposição na data '.$date.' / facturac.ao');
})->with([
    [FiscalDocumentType::Invoice, '2026-10-13 - 10:30'],
    [FiscalDocumentType::InvoiceReceipt, '2026-10-13'],
]);

test('missing certification and operation evidence does not invent either claim', function () {
    expect((new FiscalPrintFooter)->text(FiscalDocumentType::Invoice, null))->toBe('facturac.ao');
});

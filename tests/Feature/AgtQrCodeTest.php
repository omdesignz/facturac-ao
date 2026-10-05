<?php

use App\Fiscal\Documents\AgtQrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Common\Mode;

function agtQrUrl(): string
{
    return app(AgtQrCode::class)->verificationUrl('5417028391', 'FT 2026/417');
}

test('the verification link follows the AGT format, with spaces as %20', function () {
    config(['agt.qr.verification_url' => 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe']);

    expect(agtQrUrl())->toBe(
        'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=5417028391&document=FT%202026%2F417',
    );
});

test('the code is a byte-mode QR at correction M, in the smallest version that holds the link', function () {
    $qr = app(AgtQrCode::class)->encode(agtQrUrl());
    $version = $qr->getVersion()->getVersionNumber();
    $bytes = strlen(agtQrUrl());

    // The capacity at M of the version below: if it held the link, the
    // encoder would have chosen it.
    $capacityBelow = $qr->getVersion()->getVersionForNumber($version - 1)->getTotalCodewords()
        - $qr->getVersion()->getVersionForNumber($version - 1)->getEcBlocksForLevel(ErrorCorrectionLevel::M())->getTotalEcCodewords();

    expect($qr->getErrorCorrectionLevel()->getBits())->toBe(ErrorCorrectionLevel::M()->getBits())
        ->and($qr->getMode())->toBe(Mode::BYTE())
        ->and($qr->getMatrix()->getWidth())->toBe($qr->getMatrix()->getHeight())
        ->and($capacityBelow)->toBeLessThan($bytes);
});

test('the PNG is an opaque 350 by 350 square, drawn module for module', function () {
    $service = app(AgtQrCode::class);
    $png = $service->png(agtQrUrl());
    $matrix = $service->encode(agtQrUrl())->getMatrix();
    $image = imagecreatefromstring($png);

    // Colour type 3 is a palette image; no tRNS chunk means no transparency
    // for a PDF engine to turn into a soft mask.
    expect(substr($png, 1, 3))->toBe('PNG')
        ->and(ord($png[25]))->toBe(3)
        ->and(str_contains($png, 'tRNS'))->toBeFalse()
        ->and(imagesx($image))->toBe(AgtQrCode::IMAGE_PIXELS)
        ->and(imagesy($image))->toBe(AgtQrCode::IMAGE_PIXELS);

    $modules = $matrix->getWidth();
    $modulePixels = intdiv(AgtQrCode::IMAGE_PIXELS, $modules + AgtQrCode::QUIET_ZONE_MODULES * 2);
    $offset = intdiv(AgtQrCode::IMAGE_PIXELS - $modules * $modulePixels, 2);
    $isDark = fn (int $x, int $y): bool => imagecolorsforindex($image, imagecolorat($image, $x, $y))['red'] === 0;

    // Centred, with at least the four-module quiet zone on every side.
    $farMargin = AgtQrCode::IMAGE_PIXELS - $offset - $modules * $modulePixels;
    expect($offset)->toBeGreaterThanOrEqual(AgtQrCode::QUIET_ZONE_MODULES * $modulePixels)
        ->and(abs($farMargin - $offset))->toBeLessThanOrEqual(1);

    // Every module is exactly where the matrix says, at its centre and at
    // both of its far corners, so no module is a pixel wider than another.
    for ($y = 0; $y < $modules; $y++) {
        for ($x = 0; $x < $modules; $x++) {
            $left = $offset + $x * $modulePixels;
            $top = $offset + $y * $modulePixels;
            $expected = $matrix->get($x, $y) === 1;

            foreach ([[0, 0], [intdiv($modulePixels, 2), intdiv($modulePixels, 2)], [$modulePixels - 1, $modulePixels - 1]] as [$dx, $dy]) {
                if ($isDark($left + $dx, $top + $dy) !== $expected) {
                    throw new RuntimeException("Module {$x},{$y} is drawn wrong.");
                }
            }
        }
    }

    foreach ([0, $offset - 1, AgtQrCode::IMAGE_PIXELS - 1] as $edge) {
        expect($isDark($edge, intdiv(AgtQrCode::IMAGE_PIXELS, 2)))->toBeFalse()
            ->and($isDark(intdiv(AgtQrCode::IMAGE_PIXELS, 2), $edge))->toBeFalse();
    }
});

test('the web view gets the same code as a square vector with its quiet zone', function () {
    $service = app(AgtQrCode::class);
    $side = $service->encode(agtQrUrl())->getMatrix()->getWidth() + AgtQrCode::QUIET_ZONE_MODULES * 2;
    $svg = $service->svg(agtQrUrl());

    expect($svg)->toContain("viewBox=\"0 0 {$side} {$side}\"")
        ->toContain("width=\"{$side}\" height=\"{$side}\"")
        ->toContain('shape-rendering="crispEdges"')
        ->and(substr_count($svg, '<path'))->toBe(1);
});

test('a link too long to print legibly is refused rather than printed as a blur', function () {
    app(AgtQrCode::class)->png(str_repeat('https://quiosqueagt.minfin.gov.ao/', 40));
})->throws(RuntimeException::class, 'too long');

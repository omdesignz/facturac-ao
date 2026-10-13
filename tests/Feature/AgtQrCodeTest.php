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

test('the official logo is centred and the PNG preserves every module outside it', function (string $number) {
    $service = app(AgtQrCode::class);
    $url = $service->verificationUrl('5417028391', $number);
    $png = $service->png($url);
    $qr = $service->encode($url);
    $matrix = $qr->getMatrix();
    $svg = new DOMDocument;
    $svg->loadXML($service->svg($url));
    $logo = $svg->getElementsByTagName('svg')->item(1);
    $artwork = $svg->getElementsByTagName('image')->item(0);
    $logoX = (int) $logo->getAttribute('x') - AgtQrCode::QUIET_ZONE_MODULES;
    $logoY = (int) $logo->getAttribute('y') - AgtQrCode::QUIET_ZONE_MODULES;
    $logoWidth = (int) $logo->getAttribute('width');
    $logoHeight = (int) $logo->getAttribute('height');

    expect($logoWidth)->toBeGreaterThan($logoHeight)
        ->and($logoX * 2 + $logoWidth)->toBe($matrix->getWidth())
        ->and($logoY * 2 + $logoHeight)->toBe($matrix->getHeight())
        ->and($artwork->getAttribute('href'))->toBe('data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logos/agt-qr.png'))));
    $image = imagecreatefromstring($png);

    // Colour type 2 is opaque RGB; no tRNS chunk means no transparency
    // for a PDF engine to turn into a soft mask.
    expect(substr($png, 1, 3))->toBe('PNG')
        ->and(ord($png[25]))->toBe(2)
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

    expect($logoWidth * $logoHeight * $modulePixels ** 2 / AgtQrCode::IMAGE_PIXELS ** 2)->toBeLessThan(0.2);

    // Outside the logo every module remains identical to the encoded matrix.
    for ($y = 0; $y < $modules; $y++) {
        for ($x = 0; $x < $modules; $x++) {
            if ($x >= $logoX && $x < $logoX + $logoWidth && $y >= $logoY && $y < $logoY + $logoHeight) {
                continue;
            }

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

    $bluePixels = 0;

    for ($y = $offset + $logoY * $modulePixels; $y < $offset + ($logoY + $logoHeight) * $modulePixels; $y++) {
        for ($x = $offset + $logoX * $modulePixels; $x < $offset + ($logoX + $logoWidth) * $modulePixels; $x++) {
            $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $bluePixels += (int) ($colour['blue'] > $colour['red'] + 30);
        }
    }

    expect($bluePixels)->toBeGreaterThan(0);

    foreach ([0, $offset - 1, AgtQrCode::IMAGE_PIXELS - 1] as $edge) {
        expect($isDark($edge, intdiv(AgtQrCode::IMAGE_PIXELS, 2)))->toBeFalse()
            ->and($isDark(intdiv(AgtQrCode::IMAGE_PIXELS, 2), $edge))->toBeFalse();
    }
})->with([
    'invoice' => 'FT 2026/417',
    'sales ticket' => 'TV POS-A-2026/42',
    'central alignment pattern' => 'TV '.str_repeat('A', 60).'/417',
    'long series' => 'FR '.str_repeat('A', 150).'/999999',
]);

test('the web view gets the same code as a square vector with its quiet zone', function () {
    $service = app(AgtQrCode::class);
    $side = $service->encode(agtQrUrl())->getMatrix()->getWidth() + AgtQrCode::QUIET_ZONE_MODULES * 2;
    $svg = $service->svg(agtQrUrl());

    expect($svg)->toContain("viewBox=\"0 0 {$side} {$side}\"")
        ->toContain("width=\"{$side}\" height=\"{$side}\"")
        ->toContain('shape-rendering="crispEdges"')
        ->and(substr_count($svg, '<path'))->toBe(1)
        ->and(substr_count($svg, '<image'))->toBe(1)
        ->and($svg)->not->toContain('href="http');
});

test('a link too long to print legibly is refused rather than printed as a blur', function () {
    app(AgtQrCode::class)->png(str_repeat('https://quiosqueagt.minfin.gov.ao/', 40));
})->throws(RuntimeException::class, 'too long');

test('the QR uses AGT original artwork and crops only blank margins', function () {
    $path = public_path('images/logos/agt-qr.png');
    expect(hash_file('sha256', $path))->toBe('cd025c579902f5b876ad47f8e03e00ff0383a94b81b4ec8fe8a87c3108dfec8e');
    $svg = new DOMDocument;
    $svg->loadXML(app(AgtQrCode::class)->svg(agtQrUrl()));
    $crop = array_map('intval', explode(' ', $svg->getElementsByTagName('svg')->item(2)->getAttribute('viewBox')));
    $image = imagecreatefrompng($path);

    for ($y = 0; $y < imagesy($image); $y++) {
        if ($y >= $crop[1] && $y < $crop[1] + $crop[3]) {
            continue;
        }

        for ($x = 0; $x < imagesx($image); $x++) {
            if (imagecolorat($image, $x, $y) !== 0xFFFFFF) {
                throw new RuntimeException('The crop removes part of the official logo.');
            }
        }
    }
});

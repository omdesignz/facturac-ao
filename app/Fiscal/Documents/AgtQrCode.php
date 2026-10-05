<?php

namespace App\Fiscal\Documents;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Encoder\QrCode;
use RuntimeException;

/**
 * The verification QR printed on every fiscal document, built to the AGT's
 * published specification (portaldoparceiro, "Especificações do QR Code a
 * utilizar nos documentos impressos"): Model 2, error correction M, byte mode,
 * UTF-8, delivered as a 350 × 350 PNG.
 *
 * The specification also names version 4 (33 × 33 modules), but version 4 at
 * correction M holds 62 bytes and the URL it prescribes is over a hundred
 * before the NIF and number are added. The encoder therefore picks the
 * smallest version that holds the URL at M; nothing else about the code is
 * negotiable. The conflict is recorded in docs/phase-0/agt-clarification-request.md.
 *
 * Drawn here rather than by a library renderer so the result is exact: every
 * module a whole number of pixels, the code centred in a white square with at
 * least the four-module quiet zone scanners need, and no alpha channel for a
 * PDF to turn into a soft mask. Square by construction, at any print size.
 */
class AgtQrCode
{
    public const int IMAGE_PIXELS = 350;

    /** ISO/IEC 18004 asks for four light modules on every side. */
    public const int QUIET_ZONE_MODULES = 4;

    /** Below this a printed module blurs into its neighbours. */
    private const int MINIMUM_MODULE_PIXELS = 3;

    /**
     * The link the kiosk expects, with each space in the number as %20 as the
     * specification prescribes. Values are percent-encoded per RFC 3986, so
     * the "/" in "FT 2026/12" travels as %2F; that form is pinned by the
     * golden URL fixtures and changes only with compliance review.
     */
    public function verificationUrl(string $taxIdentificationNumber, string $documentNumber): string
    {
        $baseUrl = rtrim((string) config('agt.qr.verification_url'), '?&');

        return $baseUrl
            .'?emissor='.$this->queryValue($taxIdentificationNumber)
            .'&document='.$this->queryValue($documentNumber);
    }

    public function encode(string $url): QrCode
    {
        return Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8');
    }

    /** The 350 × 350 PNG the specification asks for. */
    public function png(string $url): string
    {
        $modules = $this->modules($url);
        $count = count($modules);
        $modulePixels = $this->modulePixels($count);
        $offset = intdiv(self::IMAGE_PIXELS - $count * $modulePixels, 2);

        $image = imagecreate(self::IMAGE_PIXELS, self::IMAGE_PIXELS);

        if ($image === false) {
            throw new RuntimeException('Could not allocate the QR image.');
        }

        // The first colour allocated on a palette image is its background.
        imagecolorallocate($image, 255, 255, 255);
        $dark = imagecolorallocate($image, 0, 0, 0);

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $isDark) {
                if (! $isDark) {
                    continue;
                }

                $left = $offset + $x * $modulePixels;
                $top = $offset + $y * $modulePixels;

                imagefilledrectangle(
                    $image,
                    $left,
                    $top,
                    $left + $modulePixels - 1,
                    $top + $modulePixels - 1,
                    (int) $dark,
                );
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    public function pngDataUri(string $url): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($url));
    }

    /**
     * The same code as vector markup for the web print view, where it is
     * inlined into the page. One path on a square viewBox, quiet zone
     * included, with crisp edges so a browser never anti-aliases a module.
     */
    public function svg(string $url): string
    {
        $modules = $this->modules($url);
        $side = count($modules) + self::QUIET_ZONE_MODULES * 2;
        $path = '';

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $path .= sprintf(
                        'M%d %dh1v1h-1z',
                        $x + self::QUIET_ZONE_MODULES,
                        $y + self::QUIET_ZONE_MODULES,
                    );
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" '
            .'preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges" role="img" aria-label="QR de verificação AGT">'
            .'<rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%2$s"/></svg>',
            $side,
            $path,
        );
    }

    /**
     * The dark and light modules, row by row.
     *
     * @return list<list<bool>>
     */
    private function modules(string $url): array
    {
        $matrix = $this->encode($url)->getMatrix();
        $rows = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function modulePixels(int $moduleCount): int
    {
        $modulePixels = intdiv(
            self::IMAGE_PIXELS,
            $moduleCount + self::QUIET_ZONE_MODULES * 2,
        );

        if ($modulePixels < self::MINIMUM_MODULE_PIXELS) {
            throw new RuntimeException('The verification URL is too long for a printable QR code.');
        }

        return $modulePixels;
    }

    private function queryValue(string $value): string
    {
        return rawurlencode($value);
    }
}

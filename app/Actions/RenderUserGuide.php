<?php

namespace App\Actions;

use App\Fiscal\Documents\PdfSheet;
use App\Models\PlatformSetting;

final readonly class RenderUserGuide
{
    public function __construct(private PdfSheet $sheet) {}

    public function execute(): string
    {
        return $this->sheet->render('help.guide-pdf', [
            'guide' => config('user-guide'),
            'logo' => public_path('brand/facturac-ao.svg'),
            'supportEmail' => PlatformSetting::get('support_email') ?: null,
            'supportPhone' => PlatformSetting::get('support_phone') ?: null,
        ], [
            'title' => 'facturac.ao | Guia de utilização',
            'author' => 'facturac.ao',
            'subject' => 'Primeiros passos, vendas e gestão diária',
            'engine' => [
                'font' => 'hankengrotesk',
                'substitutions' => true,
                'margin_top' => 20,
                'margin_bottom' => 19,
                'margin_header' => 9,
                'margin_footer' => 9,
            ],
        ]);
    }
}

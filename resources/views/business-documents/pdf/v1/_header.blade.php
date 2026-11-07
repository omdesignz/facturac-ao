{{-- The company on the left, what this sheet is on the right, an ink rule beneath. --}}
<table class="layout">
    <tr>
        <td style="width: 55%; vertical-align: bottom;">
            @if ($logo)
                <img src="{{ $logo }}"
                     style="max-width: {{ config('fiscal.print.logo_max_width_mm') }}mm; max-height: {{ config('fiscal.print.logo_max_height_mm') }}mm;"
                     alt="">
            @else
                <div class="issuer-name">{{ $company['trade_name'] ?? $company['legal_name'] }}</div>
            @endif
            <div class="muted" style="margin-top: 6pt; font-size: 8.5pt;">
                {{ $company['legal_name'] }} · NIF {{ $company['tax_identification_number'] }}
                @if ($company['address_line'])
                    <br>{{ $company['address_line'] }}@if ($company['municipality']), {{ $company['municipality'] }}@endif
                @endif
            </div>
        </td>
        <td style="width: 45%; vertical-align: bottom; text-align: right;">
            <div class="doc-kind">{{ $title }}</div>
            <div class="doc-sub">{{ $subtitle }}</div>
        </td>
    </tr>
</table>
<div class="header-rule"></div>

<htmlpagefooter name="sheetFooter">
    <table class="footer">
        <tr>
            <td>{{ $company['legal_name'] }} · NIF {{ $company['tax_identification_number'] }} · {{ $title }} · Documento não fiscal</td>
            <td class="right nowrap">Página {PAGENO} de {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>

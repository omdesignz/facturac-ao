{{--
    Layout v5: readable business documents with a clear title, balanced
    parties and one prominent document total. Physical CSS properties and
    hexadecimal colours are intentional: this stylesheet runs in mPDF's
    print engine, which does not implement browser logical properties or oklch.
--}}
<style>
    @page {
        header: html_documentHeader;
        footer: html_documentFooter;
    }
    body {
        font-family: hankengrotesk, dejavusanscondensed, sans-serif;
        font-size: 9.5pt;
        color: #242426;
        line-height: 1.45;
    }
    .ink { color: #171719; }
    .muted, .faint { color: #55555b; }
    .right { text-align: right; }
    .center { text-align: center; }
    .strong, .medium {
        font-family: hankengroteskmedium;
        color: #171719;
    }
    .strong, .bold { font-weight: bold; color: #171719; }
    .nowrap { white-space: nowrap; }
    .label {
        font-family: hankengroteskmedium;
        font-size: 8pt;
        color: #55555b;
    }
    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td { vertical-align: top; }

    .doc-kind {
        font-family: hankengroteskmedium;
        font-size: 18pt;
        font-weight: bold;
        color: #171719;
        line-height: 1.15;
    }
    .doc-number {
        font-size: 10pt;
        color: #414146;
        margin-top: 4pt;
    }
    .issuer-name { font-size: 16pt; font-weight: bold; color: #171719; }
    .header-rule {
        border-bottom: 0.8pt solid #171719;
        height: 1pt;
        margin-top: 9pt;
    }
    table.footer {
        width: 100%;
        border-collapse: collapse;
        border-top: 0.5pt solid #d4d4d7;
        font-size: 7.5pt;
        color: #55555b;
    }
    table.footer td { padding-top: 6pt; }

    table.party-box {
        width: 100%;
        border-collapse: collapse;
        border: 0.5pt solid #d4d4d7;
    }
    table.party-box td { padding: 10pt 12pt; line-height: 1.55; }
    .party-name {
        font-family: hankengroteskmedium;
        font-weight: bold;
        font-size: 11pt;
        color: #171719;
    }
    table.facts {
        width: 100%;
        border-collapse: collapse;
        border-top: 0.5pt solid #d4d4d7;
        border-bottom: 0.5pt solid #d4d4d7;
    }
    table.facts td { padding: 8pt 7pt; vertical-align: top; }
    .fact-value {
        font-family: hankengroteskmedium;
        font-size: 9.5pt;
        color: #171719;
        margin-top: 3pt;
    }

    table.items { width: 100%; border-collapse: collapse; }
    table.items th {
        font-family: hankengroteskmedium;
        font-weight: bold;
        font-size: 7.5pt;
        color: #414146;
        padding: 5pt 3pt 6pt 3pt;
        border-bottom: 0.8pt solid #171719;
        vertical-align: bottom;
    }
    table.items th.group { border-bottom: 0.5pt solid #d4d4d7; padding-bottom: 4pt; }
    table.items td {
        padding: 8pt 3pt;
        border-bottom: 0.5pt solid #dddddf;
        vertical-align: top;
        font-size: 9pt;
    }
    table.items td.figure { white-space: nowrap; text-align: right; }
    .line-note { font-size: 8pt; color: #55555b; margin-top: 3pt; }

    table.summary {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14pt;
    }
    table.summary th {
        font-family: hankengroteskmedium;
        font-weight: bold;
        font-size: 8pt;
        color: #414146;
        padding: 5pt 3pt;
        border-bottom: 0.6pt solid #171719;
    }
    table.summary td { padding: 6pt 3pt; border-bottom: 0.5pt solid #dddddf; font-size: 9pt; }
    table.totals { width: 100%; border-collapse: collapse; }
    table.totals td { padding: 5pt 0; font-size: 9.5pt; }
    table.totals td.amount { text-align: right; white-space: nowrap; color: #171719; }
    table.totals tr.grand td { border-top: 1pt solid #171719; padding-top: 10pt; padding-bottom: 9pt; }
    .grand-label { font-family: hankengroteskmedium; font-size: 10pt; font-weight: bold; color: #171719; }
    .grand-amount { font-size: 19pt; font-weight: bold; color: #171719; }

    table.authenticity {
        width: 100%;
        border-collapse: collapse;
        border-top: 0.5pt solid #d4d4d7;
        page-break-inside: avoid;
    }
    table.authenticity td { padding-top: 12pt; }
    img.qr { width: 30mm; height: 30mm; }
</style>

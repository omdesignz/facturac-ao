{{--
    Non-fiscal business sheets, layout v1 (statement, quote). Physical CSS and
    hexadecimal colours on purpose: mPDF implements neither logical properties
    nor oklch. Frozen once shipped; change it by adding v2.
--}}
<style>
    @page { footer: html_sheetFooter; }
    body {
        font-family: hankengrotesk, dejavusanscondensed, sans-serif;
        font-size: 9.5pt;
        color: #242426;
        line-height: 1.45;
    }
    .muted { color: #55555b; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    .strong { font-family: hankengroteskmedium; font-weight: bold; color: #171719; }
    .label { font-family: hankengroteskmedium; font-size: 8pt; color: #55555b; }
    .positive { color: #0f7a4a; }
    .critical { color: #b42318; }
    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td { vertical-align: top; }
    .issuer-name { font-size: 16pt; font-weight: bold; color: #171719; }
    .doc-kind { font-family: hankengroteskmedium; font-size: 18pt; font-weight: bold; color: #171719; line-height: 1.15; }
    .doc-sub { font-size: 10pt; color: #414146; margin-top: 4pt; }
    .header-rule { border-bottom: 0.8pt solid #171719; height: 1pt; margin-top: 9pt; margin-bottom: 14pt; }
    table.party-box { width: 100%; border-collapse: collapse; border: 0.5pt solid #d4d4d7; }
    table.party-box td { padding: 10pt 12pt; line-height: 1.55; }
    .party-name { font-family: hankengroteskmedium; font-weight: bold; font-size: 11pt; color: #171719; }
    table.figures { width: 100%; border-collapse: collapse; margin-top: 14pt; }
    table.figures td { padding: 9pt 10pt; border: 0.5pt solid #d4d4d7; vertical-align: top; }
    .figure { font-family: hankengroteskmedium; font-size: 14pt; color: #171719; margin-top: 3pt; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 16pt; }
    table.items th {
        font-family: hankengroteskmedium; font-weight: bold; font-size: 7.5pt; color: #414146;
        padding: 5pt 3pt 6pt 3pt; border-bottom: 0.8pt solid #171719; vertical-align: bottom; text-align: left;
    }
    table.items th.right { text-align: right; }
    table.items td { padding: 7pt 3pt; border-bottom: 0.5pt solid #dddddf; vertical-align: top; font-size: 9pt; }
    table.items td.figure-cell { white-space: nowrap; text-align: right; }
    .line-note { font-size: 8pt; color: #55555b; margin-top: 2pt; }
    table.totals { width: 46%; border-collapse: collapse; margin-top: 12pt; }
    table.totals td { padding: 4pt 3pt; }
    table.totals tr.grand td { border-top: 0.8pt solid #171719; padding-top: 7pt; font-family: hankengroteskmedium; font-size: 13pt; color: #171719; }
    .notice { margin-top: 18pt; padding: 9pt 11pt; background-color: #f4f4f5; font-size: 8.5pt; color: #414146; }
    table.footer { width: 100%; border-collapse: collapse; border-top: 0.5pt solid #d4d4d7; font-size: 7.5pt; color: #55555b; }
    table.footer td { padding-top: 6pt; }
</style>

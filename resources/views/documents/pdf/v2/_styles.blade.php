{{--
    Layout v2: the AGT content hierarchy in the manner of an ERP document.
    One neutral ink, hairline rules instead of grid lines and grey slabs,
    small spaced capitals for labels, and a single emphasised total.

    The issuer's document, not the platform's: no platform colour appears.
    mPDF parses a limited CSS subset and fetches nothing, so it is all here.
--}}
<style>
    @page {
        header: html_documentHeader;
        footer: html_documentFooter;
    }

    body {
        font-family: hankengrotesk, dejavusanscondensed, sans-serif;
        font-size: 8.5pt;
        color: #2b2b30;
        line-height: 1.4;
    }

    .ink { color: #141416; }
    .muted { color: #6b6b74; }
    .faint { color: #9a9aa3; }
    .right { text-align: right; }
    .center { text-align: center; }
    .strong { font-family: hankengroteskmedium; font-weight: bold; color: #141416; }
    .medium { font-family: hankengroteskmedium; color: #141416; }
    .bold { font-weight: bold; color: #141416; }
    .nowrap { white-space: nowrap; }

    /* Small spaced capitals: every label on the sheet. */
    .label {
        font-family: hankengroteskmedium;
        font-size: 6.5pt;
        letter-spacing: 0.6pt;
        text-transform: uppercase;
        color: #6b6b74;
    }

    table.layout {
        width: 100%;
        border-collapse: collapse;
    }
    table.layout td { vertical-align: top; }

    /* ------------------------------------------------ header and footer */
    .doc-kind {
        font-family: hankengroteskmedium;
        font-size: 7.5pt;
        letter-spacing: 1.6pt;
        text-transform: uppercase;
        color: #6b6b74;
    }
    .doc-number {
        font-size: 17pt;
        font-weight: bold;
        color: #141416;
        letter-spacing: -0.2pt;
    }
    .issuer-name {
        font-size: 13pt;
        font-weight: bold;
        color: #141416;
    }
    .header-rule {
        border-bottom: 0.9pt solid #141416;
        height: 1pt;
        margin-top: 7pt;
    }
    table.footer {
        width: 100%;
        border-collapse: collapse;
        border-top: 0.4pt solid #dcdce1;
        font-size: 6.5pt;
        color: #9a9aa3;
    }
    table.footer td { padding-top: 5pt; }

    /* -------------------------------------------------------- parties */
    /*
     * A bordered box is a one-cell table, not a div: mPDF draws a bordered
     * block inside a table cell one line at a time.
     */
    table.party-box {
        width: 100%;
        border-collapse: collapse;
        border: 0.5pt solid #cfcfd6;
    }
    table.party-box td {
        padding: 8pt 10pt;
        line-height: 1.5;
    }
    .party-name {
        font-family: hankengroteskmedium;
        font-weight: bold;
        font-size: 10pt;
        color: #141416;
    }

    /* --------------------------------------------------- facts band */
    table.facts {
        width: 100%;
        border-collapse: collapse;
        background: #f4f4f6;
    }
    table.facts td {
        padding: 6pt 10pt 6pt 10pt;
        vertical-align: top;
    }
    .fact-value {
        font-family: hankengroteskmedium;
        font-size: 9pt;
        color: #141416;
        margin-top: 1.5pt;
    }

    /* --------------------------------------------------------- lines */
    table.items {
        width: 100%;
        border-collapse: collapse;
    }
    table.items th {
        font-family: hankengroteskmedium;
        font-weight: normal;
        font-size: 6.3pt;
        letter-spacing: 0.4pt;
        text-transform: uppercase;
        color: #6b6b74;
        padding: 4pt 4pt 5pt 4pt;
        border-bottom: 0.9pt solid #141416;
        vertical-align: bottom;
        white-space: nowrap;
    }
    table.items th.group {
        border-bottom: 0.4pt solid #cfcfd6;
        padding-bottom: 3pt;
    }
    table.items td {
        padding: 5pt 4pt;
        border-bottom: 0.4pt solid #e2e2e7;
        vertical-align: top;
        font-size: 8pt;
    }
    /* A figure broken across two lines reads as two figures. */
    table.items td.figure { white-space: nowrap; text-align: right; }
    .line-note {
        font-size: 6.5pt;
        color: #6b6b74;
        margin-top: 1.5pt;
    }

    /* ------------------------------------------------------ summary */
    /*
     * Spaced from what follows by its own bottom margin: mPDF drops the top
     * margin of a block that comes after a table inside a cell.
     */
    table.summary {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12pt;
    }
    table.summary th {
        font-family: hankengroteskmedium;
        font-weight: normal;
        font-size: 6.3pt;
        letter-spacing: 0.4pt;
        text-transform: uppercase;
        color: #6b6b74;
        padding: 3pt 4pt 4pt 4pt;
        border-bottom: 0.6pt solid #141416;
    }
    table.summary td {
        padding: 4pt;
        border-bottom: 0.4pt solid #e2e2e7;
        font-size: 7.8pt;
    }

    table.totals {
        width: 100%;
        border-collapse: collapse;
    }
    table.totals td {
        padding: 3.5pt 0 3.5pt 0;
        font-size: 8.3pt;
    }
    table.totals td.amount {
        text-align: right;
        white-space: nowrap;
        color: #141416;
    }
    table.totals tr.grand td {
        border-top: 1.1pt solid #141416;
        padding-top: 7pt;
        padding-bottom: 2pt;
    }
    .grand-label {
        font-family: hankengroteskmedium;
        font-size: 7pt;
        letter-spacing: 0.8pt;
        text-transform: uppercase;
        color: #141416;
    }
    .grand-amount {
        font-size: 15pt;
        font-weight: bold;
        color: #141416;
        letter-spacing: -0.2pt;
    }

    /* The verification block: never split, never squeezed, QR always square. */
    table.authenticity {
        width: 100%;
        border-collapse: collapse;
        border-top: 0.4pt solid #dcdce1;
        page-break-inside: avoid;
    }
    table.authenticity td { padding-top: 9pt; }
    img.qr {
        width: 30mm;
        height: 30mm;
    }
</style>

{{--
    Print styles for the AGT document sheets.

    Inline rather than a stylesheet because mPDF parses a limited subset of CSS
    and resolves nothing over the network: everything the sheet needs to render
    has to be in the document it is handed.
--}}
<style>
    @page {
        header: html_documentHeader;
        footer: html_documentFooter;
    }

    body {
        font-family: dejavusans, sans-serif;
        font-size: 8.5pt;
        color: #111;
        line-height: 1.35;
    }

    .muted { color: #555; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .nowrap { white-space: nowrap; }

    /* The band behind the document title, as on the AGT sheet. */
    .title-band {
        background: #e6e6e6;
        padding: 8pt 12pt;
        text-align: right;
    }
    .title-band .title {
        font-size: 26pt;
        font-weight: bold;
        letter-spacing: 1pt;
    }

    /*
     * A bordered box is a one-cell table, not a div: mPDF draws a bordered
     * block inside a table cell one line at a time, which prints as a stack
     * of underlined fragments instead of a box.
     */
    table.party-box {
        width: 100%;
        border-collapse: collapse;
        border: 0.6pt solid #333;
    }
    table.party-box td {
        padding: 6pt 8pt;
        vertical-align: top;
        line-height: 1.45;
    }

    table.grid {
        width: 100%;
        border-collapse: collapse;
    }
    table.grid th {
        background: #cfcfcf;
        border: 0.4pt solid #9a9a9a;
        padding: 3.5pt 3pt;
        font-size: 7.5pt;
        font-weight: normal;
        text-align: center;
    }
    table.grid td {
        border: 0.4pt solid #c4c4c4;
        padding: 3.5pt 3pt;
        font-size: 8pt;
    }
    /* A figure broken across two lines reads as two figures. */
    table.grid td.nowrap { white-space: nowrap; font-size: 7.5pt; }
    /* Banded rows, as printed. mPDF has no :nth-child, so the row carries it. */
    table.grid tr.alt td { background: #efefef; }

    table.totals {
        width: 100%;
        border-collapse: collapse;
    }
    table.totals td {
        padding: 3pt 6pt;
        font-size: 8pt;
        border: 0.4pt solid #c4c4c4;
    }
    table.totals td.label {
        border: 0;
        text-align: right;
        padding-right: 8pt;
    }
    table.totals tr.grand td { background: #e6e6e6; font-weight: bold; }

    /* Full-width grey band. A table for the same reason as the party box. */
    table.section-title {
        width: 100%;
        border-collapse: collapse;
    }
    table.section-title td {
        background: #cfcfcf;
        padding: 3.5pt 6pt;
        font-weight: bold;
        font-size: 8pt;
    }

    /*
     * The verification block. Fixed size and never split or squeezed: the QR
     * must reach the reader square and at a size a phone can read.
     */
    table.authenticity {
        width: 100%;
        border-collapse: collapse;
        page-break-inside: avoid;
    }
    img.qr {
        width: 30mm;
        height: 30mm;
    }

    .notice {
        border: 1pt solid #111;
        padding: 7pt;
        text-align: center;
        font-weight: bold;
        font-size: 9pt;
    }
</style>

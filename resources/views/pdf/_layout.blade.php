{{--
    Gemeinsames Layout fuer SIS- und MP-PDFs.
    Sander-Branding: rot #9B1C3B.

    Wichtig:
    - Page-Header/Footer via position:fixed (rendert auf jeder Seite)
    - Total-Pagecount via dompdf-PHP-Script-Block am Dateiende
      (counter(pages) liefert in dompdf nicht zuverlaessig)
    - Bewohner-Karte als <table> statt floating divs (bricht sonst
      seitenweise auf!)
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentTitle }}</title>
    <style>
        @page {
            margin: 110px 40px 60px 40px;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #333333;
        }

        /* ===== Page-Header (auf jeder Seite) ===== */
        header.page-header {
            position: fixed;
            top: -90px;
            left: 0;
            right: 0;
            height: 70px;
            border-bottom: 2px solid #9B1C3B;
        }
        header.page-header table {
            width: 100%;
            border-collapse: collapse;
        }
        header.page-header td {
            vertical-align: top;
            padding: 0;
        }
        header.page-header .brand-name {
            font-size: 16pt;
            font-weight: bold;
            color: #9B1C3B;
            letter-spacing: 0.05em;
        }
        header.page-header .brand-sub {
            font-size: 8pt;
            color: #54595F;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-top: 2px;
        }
        header.page-header .doc-meta {
            text-align: right;
            font-size: 9pt;
            color: #54595F;
        }
        header.page-header .doc-meta strong {
            color: #333333;
            display: block;
            font-size: 11pt;
            margin-bottom: 2px;
        }

        /* ===== Page-Footer (auf jeder Seite) ===== */
        footer.page-footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 24px;
            border-top: 1px solid #E5E7EB;
            padding-top: 4px;
            font-size: 8pt;
            color: #54595F;
        }
        footer.page-footer table {
            width: 100%;
            border-collapse: collapse;
        }
        footer.page-footer td {
            padding: 0;
            vertical-align: top;
        }
        footer.page-footer .right {
            text-align: right;
        }

        /* ===== Bewohner-Karte als kompakte 2-spaltige Tabelle ===== */
        table.resident-card {
            width: 100%;
            border-collapse: collapse;
            background: #FAE7EC;
            margin-bottom: 20px;
        }
        table.resident-card tr.title-row td {
            padding: 10px 14px 4px 14px;
            border-left: 4px solid #9B1C3B;
        }
        table.resident-card .name {
            font-size: 13pt;
            font-weight: bold;
            color: #333333;
        }
        table.resident-card td.label {
            padding: 2px 6px 2px 14px;
            border-left: 4px solid #9B1C3B;
            color: #54595F;
            font-size: 9pt;
            width: 140px;
            vertical-align: top;
        }
        table.resident-card td.value {
            padding: 2px 14px 2px 0;
            color: #333333;
            font-size: 9pt;
            vertical-align: top;
        }
        table.resident-card tr.last td {
            padding-bottom: 10px;
        }

        /* ===== Sektionen ===== */
        section {
            margin-bottom: 18px;
        }
        h2.section-title {
            font-size: 11pt;
            color: #9B1C3B;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            font-weight: bold;
            margin: 0 0 8px 0;
            border-bottom: 1px solid #E5E7EB;
            padding-bottom: 4px;
        }
        h3.topic-title {
            font-size: 10pt;
            font-weight: bold;
            margin: 0 0 4px 0;
            color: #333333;
        }
        h3.topic-title .num {
            color: #9B1C3B;
            margin-right: 6px;
        }
        .topic-block {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .content {
            white-space: pre-line;
            font-size: 10pt;
            margin: 0;
        }
        .content.muted {
            color: #999999;
            font-style: italic;
        }

        /* ===== Risiko-Tabelle (SIS) ===== */
        table.risk-matrix {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            page-break-inside: avoid;
        }
        table.risk-matrix th,
        table.risk-matrix td {
            border: 1px solid #E5E7EB;
            padding: 6px 8px;
            font-size: 9pt;
            text-align: left;
            vertical-align: top;
        }
        table.risk-matrix th {
            background: #FAE7EC;
            color: #9B1C3B;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 8pt;
        }
        table.risk-matrix td.center {
            text-align: center;
            width: 80px;
        }
        table.risk-matrix .pill {
            display: inline-block;
            padding: 2px 8px;
            font-size: 8pt;
            font-weight: bold;
        }
        table.risk-matrix .pill.yes {
            background: #FEE2E2;
            color: #991B1B;
        }
        table.risk-matrix .pill.no {
            background: #E5E7EB;
            color: #54595F;
        }

        /* ===== Grundbotschaft (MP) ===== */
        table.grundbotschaft {
            width: 100%;
            border-collapse: collapse;
            background: #FAE7EC;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        table.grundbotschaft td {
            padding: 10px 14px;
            border-left: 4px solid #9B1C3B;
        }
        table.grundbotschaft .label {
            font-size: 8pt;
            color: #9B1C3B;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            font-weight: bold;
            margin-bottom: 4px;
        }
        table.grundbotschaft .text {
            white-space: pre-line;
        }
    </style>
</head>
<body>
    {{-- Page-Header --}}
    <header class="page-header">
        <table>
            <tr>
                <td style="width:55%">
                    <div class="brand-name">Sander Pflege</div>
                    <div class="brand-sub">{{ $documentTitle }}</div>
                </td>
                <td class="doc-meta">
                    <strong>{{ $resident->formal_name }}</strong>
                    Pseudonym: {{ $resident->pseudonym }}
                </td>
            </tr>
        </table>
    </header>

    {{-- Page-Footer (Seitenzahl wird per dompdf-PHP-Block unten gezeichnet) --}}
    <footer class="page-footer">
        Pflegedex · Erzeugt am {{ $generatedAt }}{{ $generatedBy ? ' · ' . $generatedBy : '' }}
    </footer>

    {{-- Inhalt --}}
    @yield('content')

    {{--
        dompdf-PHP-Block: rendert echte Seitenzahlen
        (counter(page)/counter(pages) liefert hier "0", deshalb dieser Hack)
    --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $size = 8;
            $color = [0.33, 0.35, 0.37];
            $pageWidth = $pdf->get_width();
            $marginRight = 40;

            $pdf->page_text(
                $pageWidth - $marginRight - 70,
                $pdf->get_height() - 32,
                'Seite {PAGE_NUM} / {PAGE_COUNT}',
                $font,
                $size,
                $color
            );
        }
    </script>
</body>
</html>
